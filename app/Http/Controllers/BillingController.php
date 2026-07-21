<?php

namespace App\Http\Controllers;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
use App\Support\CardcomWebhook;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        try {
            $lowProfile = $cardcom->createTokenLowProfile(
                $customer->id,
                route('billing.update-card.done', ['result' => 'success']),
                route('billing.update-card.done', ['result' => 'failed']),
                CardcomWebhook::url(),
            );
        } catch (\Throwable $e) {
            Log::error('updateCard: Cardcom token page creation threw', [
                'customer_id' => $customer->id,
                'error' => Str::limit($e->getMessage(), 300),
            ]);

            return view('billing.card-error');
        }

        $cardUrl = (string) ($lowProfile['url'] ?? '');

        // Only ever frame a real Cardcom https page. An empty/invalid URL means
        // Cardcom rejected the request (logged in the client) — show a clear
        // message instead of embedding a broken 404 the customer can't act on.
        if (! Str::startsWith($cardUrl, 'https://')) {
            return view('billing.card-error');
        }

        // Remember this session so the team can reconcile the card manually if
        // the completion webhook is lost (see the "sync card" panel action).
        if (! empty($lowProfile['low_profile_id'])) {
            $customer->update(['pending_card_lp_id' => $lowProfile['low_profile_id']]);
        }

        return view('billing.card-iframe', [
            'cardUrl' => $cardUrl,
        ]);
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
