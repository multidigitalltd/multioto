<?php

namespace App\Http\Controllers;

use App\Enums\ChargeStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
use App\Services\Notifications\TeamNotifier;
use App\Support\CardcomWebhook;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Customer-facing billing entry points. Card capture itself happens entirely
 * on Cardcom's hosted (PCI Level 1) Low Profile page — we only embed it in an
 * iframe, so the customer stays on our page and no card data ever reaches us.
 */
class BillingController extends Controller
{
    /**
     * Show Cardcom's hosted card page embedded in an iframe so the customer can
     * enter/replace a card without leaving our site. This link is used both by
     * the signup flow and the card-update links in dunning messages (signed, so
     * it can't be enumerated to probe customer ids).
     */
    public function updateCard(Customer $customer, Request $request, CardcomClient $cardcom): View
    {
        // The link carries a revocation nonce. Its signature is still valid after
        // the team cancels it, but the token no longer matches — so a canceled
        // card link shows "אינו פעיל" instead of opening a card page.
        if (! hash_equals((string) $customer->card_link_token, (string) $request->query('token'))) {
            return view('billing.card-inactive');
        }

        // Built BEFORE the call to Cardcom. Every signup now lands here, so a
        // Cardcom outage would otherwise take the bank details down with it —
        // and those have nothing to do with Cardcom. A customer who came to be
        // told how to pay by transfer must still be told, even on the day the
        // card provider is unreachable.
        $context = $this->securityCardContext($customer);

        try {
            $lowProfile = $cardcom->createTokenLowProfile(
                $customer->id,
                route('billing.update-card.done', ['result' => 'success']),
                // A day, not the few minutes the card page lives: an expired
                // signature would replace the reason with a 403, and the
                // failure page is the only place the reason ever appears.
                URL::temporarySignedRoute('billing.update-card.failed', now()->addDay(), ['customer' => $customer->id]),
                CardcomWebhook::url(),
            );
        } catch (\Throwable $e) {
            Log::error('updateCard: Cardcom token page creation threw', [
                'customer_id' => $customer->id,
                'error' => Str::limit($e->getMessage(), 300),
            ]);

            return view('billing.card-error', $context);
        }

        $cardUrl = (string) ($lowProfile['url'] ?? '');

        // Only ever frame a real Cardcom https page. An empty/invalid URL means
        // Cardcom rejected the request (logged in the client) — show a clear
        // message instead of embedding a broken 404 the customer can't act on.
        if (! Str::startsWith($cardUrl, 'https://')) {
            return view('billing.card-error', $context);
        }

        // Remember this session so the team can reconcile the card manually if
        // the completion webhook is lost (see the "sync card" panel action).
        if (! empty($lowProfile['low_profile_id'])) {
            $customer->update(['pending_card_lp_id' => $lowProfile['low_profile_id']]);
        }

        return view('billing.card-iframe', [...$context, 'cardUrl' => $cardUrl]);
    }

    /**
     * What this particular customer needs told on the card page.
     *
     * A customer paying by transfer or standing order is here for the SECURITY
     * card, not to be charged — so the page says so, and carries the details of
     * the way they actually pay. Losing those behind a card form would trade
     * one omission for another.
     *
     * The grace period is read from the customer's OWN subscriptions, never
     * from the current global default. This route also serves ordinary
     * card-update links, and a customer whose subscriptions carry no allowance
     * — or carry ninety days — would otherwise be told they are charged after
     * thirty. Promising a customer a deadline the collection does not follow is
     * worse than saying nothing, so with no allowance on file nothing is
     * promised.
     *
     * @return array<string, mixed>
     */
    private function securityCardContext(Customer $customer): array
    {
        $method = (string) $customer->payment_method;
        $manualMethod = PaymentMethod::isManualValue($method);

        if (! $manualMethod) {
            return ['securityCard' => false, 'methodLabel' => null, 'paymentInstructions' => null, 'fallbackDays' => 0];
        }

        // The shortest allowance any of their live subscriptions carries: it is
        // the first date a card could be charged, and the only one it would be
        // honest to name.
        $fallbackDays = (int) $customer->subscriptions()
            ->whereNot('status', SubscriptionStatus::Canceled)
            ->whereNotNull('card_fallback_days')
            ->min('card_fallback_days');

        return [
            'securityCard' => true,
            'methodLabel' => PaymentMethod::tryFrom($method)?->getLabel() ?? '',
            'paymentInstructions' => trim((string) config('billing.signup.instructions.'.$method)),
            // A customer signing up has no subscription yet, so the arrangement
            // they are agreeing to right now is the standing one.
            'fallbackDays' => $fallbackDays > 0
                ? $fallbackDays
                : ($customer->subscriptions()->exists() ? 0 : (int) config('billing.card_fallback_days', 0)),
        ];
    }

    /**
     * A card entry that Cardcom refused.
     *
     * This page exists because nothing else covers the case. Cardcom sends its
     * webhook for a completed deal, not a declined one — so a customer who
     * types their card and is turned down produces no webhook, no record, and
     * no alert. They see a generic apology, we hear nothing, and days later
     * dunning starts against somebody who already tried to pay.
     *
     * So the redirect itself is the notification: it asks Cardcom what happened
     * to this exact session, tells the customer in their own language, and tells
     * the team with the raw reason attached.
     */
    public function updateCardFailed(Customer $customer, CardcomClient $cardcom): View
    {
        $result = [];

        if (filled($customer->pending_card_lp_id)) {
            try {
                $result = $cardcom->getLpResult((string) $customer->pending_card_lp_id);
            } catch (\Throwable $e) {
                Log::warning('updateCardFailed: could not read the Cardcom result', [
                    'customer_id' => $customer->id,
                    'error' => Str::limit($e->getMessage(), 200),
                ]);
            }
        }

        // Cardcom nests the interesting part: the top level says the session
        // ended, the transaction block says WHY the card was refused.
        $reason = trim((string) (
            data_get($result, 'TranzactionInfo.Description')
                ?: data_get($result, 'Description')
                ?: ''
        ));
        $code = (string) (data_get($result, 'TranzactionInfo.ResponseCode') ?: data_get($result, 'ResponseCode') ?: '');

        $this->announceCardFailure($customer, $code, $reason);

        return view('billing.update-card-done', [
            'result' => 'failed',
            // Cardcom writes these for the card holder and they say the one
            // thing that helps ("call your card company"). Anything we cannot
            // read falls back to the generic apology rather than to a code.
            'reason' => $reason,
        ]);
    }

    /**
     * Tell the team, once per session.
     *
     * The customer may reload the page; the alert is about the attempt, not
     * about the page view.
     */
    private function announceCardFailure(Customer $customer, string $code, string $reason): void
    {
        $key = 'card-failure:'.$customer->id.':'.($customer->pending_card_lp_id ?: 'unknown');

        if (! Cache::add($key, true, now()->addDay())) {
            return;
        }

        Log::warning('Card update declined by Cardcom', [
            'customer_id' => $customer->id,
            'low_profile_id' => $customer->pending_card_lp_id,
            'response_code' => $code,
            'description' => $reason,
        ]);

        try {
            app(TeamNotifier::class)->alert(
                "💳 עדכון כרטיס נדחה — {$customer->name}",
                implode("\n", array_filter([
                    "לקוח: {$customer->name} (#{$customer->id})",
                    $reason !== '' ? "סיבה מקארדקום: {$reason}" : 'קארדקום לא החזירה סיבה.',
                    $code !== '' ? "קוד: {$code}" : null,
                    'הלקוח ניסה למסור כרטיס ולא הצליח — הכרטיס לא נשמר.',
                ])),
                route('filament.admin.resources.customers.view', ['record' => $customer->id]),
            );
        } catch (\Throwable $e) {
            Log::warning('Card-failure alert could not be sent', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Customer-facing payment-demand link. Redirects to the demand's Cardcom
     * hosted page while it is still payable; a paid, canceled or otherwise
     * closed demand shows a clear "this link is no longer active" page rather
     * than forwarding to a stale payment screen. Signed + throttled, so a
     * charge id can't be enumerated.
     */
    public function pay(Charge $charge): View|RedirectResponse
    {
        return $this->forwardWhilePayable($charge, (string) $charge->cardcom_pay_url);
    }

    /**
     * Direct-to-Bit variant of the payment link: same signed + cancelable
     * gateway, redirecting to Cardcom's Bit URL instead of the card page. Paying
     * via Bit fires the same webhook, so the charge finalises identically.
     */
    public function payBit(Charge $charge): View|RedirectResponse
    {
        return $this->forwardWhilePayable($charge, (string) $charge->cardcom_bit_url);
    }

    /**
     * Redirect to a Cardcom URL only while the demand is still payable (pending
     * with a real https URL); otherwise show the "inactive" page. Signed +
     * throttled at the route, so a charge id can't be enumerated.
     */
    private function forwardWhilePayable(Charge $charge, string $target): View|RedirectResponse
    {
        $payable = $charge->status === ChargeStatus::Pending
            && Str::startsWith($target, 'https://');

        if (! $payable) {
            return view('billing.pay-inactive', [
                'paid' => $charge->status === ChargeStatus::Succeeded,
            ]);
        }

        return redirect()->away($target);
    }
}
