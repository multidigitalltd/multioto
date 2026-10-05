<?php

namespace App\Services\SiteAgent;

use App\Enums\BillingInterval;
use App\Enums\SiteStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Jobs\SendSiteAgentVerificationJob;
use App\Mail\SiteAgentActivationMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentOrder;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteInstallation;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\Billing\ManualChargeService;
use App\Services\Cardcom\CardcomClient;
use App\Support\CardcomWebhook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Buying בוט ניהול האתר without us being involved.
 *
 * The team's activation screen already opens the three rows this product needs
 * — a subscription on a plan carrying the agent flag, a site, a verified number
 * — and gets them consistent with each other. This does the same from a public
 * form, with the one structural difference that changes everything: **the buyer
 * leaves.** They go to Cardcom and what comes back is a webhook into a process
 * with no session, so the purchase is written down first and granted later.
 *
 * Nothing is granted before the money arrives. Not the subscription, not the
 * number's binding, not the connection codes — a service handed out at checkout
 * is a service kept by everyone who abandons the payment page.
 */
class SiteAgentCheckout
{
    public function __construct(
        private ManualChargeService $charges,
        private WhatsAppCloudClient $whatsapp,
        private SiteAgentBilling $billing,
        private CardcomClient $cardcom,
    ) {}

    /**
     * Start a purchase: record the order and hand back the payment page.
     *
     * @param  array{name: string, email: string, phone: string, manager_name: ?string, domain: string, install_mode: string}  $buyer
     * @return array{order: SiteAgentOrder, url: string}
     */
    public function start(Plan $plan, array $buyer): array
    {
        if (! $plan->active || ! $plan->is_public || ! $plan->includes_site_agent) {
            throw new \RuntimeException('המסלול הזה אינו זמין לרכישה כרגע.');
        }

        $phone = $this->whatsapp->normalize($buyer['phone']);

        if ($phone === '') {
            throw new \RuntimeException('מספר הוואטסאפ אינו תקין.');
        }

        $domain = Site::stripScheme(trim($buyer['domain']));

        // Created now, not at payment: the invoice, the renewal and every later
        // conversation hang off it, and the webhook has no form to build one
        // from.
        $customer = $this->customer($buyer['name'], $buyer['email'], $phone);

        $order = SiteAgentOrder::create([
            'reference' => SiteAgentOrder::newReference(),
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'buyer_name' => $buyer['name'],
            'buyer_email' => $buyer['email'],
            'manager_phone' => $phone,
            'manager_name' => filled($buyer['manager_name'] ?? null) ? $buyer['manager_name'] : null,
            'domain' => $domain,
            'total_agorot' => $plan->grossAgorot((bool) $customer->vat_exempt),
            'trial_days' => $this->trialDaysFor($plan, $customer, $domain),
            'install_mode' => in_array($buyer['install_mode'] ?? null, SiteAgentOrder::INSTALL_MODES, true)
                ? $buyer['install_mode']
                : SiteAgentOrder::INSTALL_SELF,
            'status' => SiteAgentOrder::PENDING,
        ]);

        if ($order->isTrial()) {
            return ['order' => $order, 'url' => $this->trialCardPage($order, $customer)];
        }

        try {
            $page = $this->charges->createHostedPage(
                customer: $customer,
                totalAgorot: (int) $order->total_agorot,
                description: $plan->name.' — '.$domain,
                notes: 'רכישה עצמית של בוט ניהול האתר',
                // Two different reasons for the same total to carry no VAT, and
                // the charge has to know about both. grossAgorot() already adds
                // nothing for a plan whose price does not carry VAT on top —
                // without saying so here, the charge would split VAT back out of
                // a total that never contained any, and the invoice would report
                // tax we did not take.
                vatExempt: (bool) $customer->vat_exempt || ! $plan->vat_applies,
                // Always: this is a subscription, and a renewal that asks for the
                // card again every month is not a renewal.
                withToken: true,
                successUrl: route('store.agent.done', ['reference' => $order->reference]),
                failureUrl: route('store.agent.done', ['reference' => $order->reference]),
            );
        } catch (\Throwable $e) {
            $order->update(['status' => SiteAgentOrder::FAILED]);

            throw $e;
        }

        $order->update(['charge_id' => $page['charge']->id]);

        return ['order' => $order, 'url' => $page['url']];
    }

    /**
     * The money arrived — switch the service on.
     *
     * Called from the charge observer, so it runs whichever way the payment was
     * confirmed: the webhook, or the reconciliation that finishes a charge whose
     * webhook was lost. Somebody who paid must never depend on which happened.
     */
    public function fulfil(Charge $charge): ?SiteAgentOrder
    {
        $order = SiteAgentOrder::query()->where('charge_id', $charge->id)->first();

        if ($order === null || $order->isFulfilled()) {
            return $order;
        }

        return $this->grant($order);
    }

    /**
     * The card for a free trial was captured — open the trial.
     *
     * Called from the Cardcom webhook once the token is on file, matched on the
     * hosted page's own id. Nothing was charged, and nothing will be until the
     * trial ends (see EndSiteAgentTrialsJob).
     */
    public function fulfilTrial(string $lowProfileId): ?SiteAgentOrder
    {
        if ($lowProfileId === '') {
            return null;
        }

        $order = SiteAgentOrder::query()->where('cardcom_low_profile_id', $lowProfileId)->first();

        if ($order === null || ! $order->isTrial() || $order->isFulfilled()) {
            return $order;
        }

        // The webhook stores the card before calling here. A trial without one
        // would end with nothing to charge, which is the outcome the card at
        // signup exists to prevent.
        if ($order->customer?->paymentTokens()->where('status', TokenStatus::Active)->doesntExist()) {
            return $order;
        }

        return $this->grant($order);
    }

    /** Switch the service on for a paid or trial order. */
    private function grant(SiteAgentOrder $order): ?SiteAgentOrder
    {
        $customer = $order->customer;
        $plan = $order->plan;

        if ($customer === null || $plan === null) {
            return $order;
        }

        $subscriber = null;

        DB::transaction(function () use ($order, $customer, $plan, &$subscriber): void {
            $site = $this->site($order, $customer);

            // A second SITE is a second service, at its own price.
            //
            // Reusing whatever subscription the customer happened to hold would
            // mean the second site is paid for once, at checkout, and then
            // renews inside the first site's price forever — a customer getting
            // a second site free from month two, discovered by nobody. What IS
            // shared is a second number on the same site, which joins this
            // subscription as a paid seat (see the portal).
            $existing = $this->billing->subscriptionForSite($customer, $site->id);

            $subscription = $existing ?? Subscription::create([
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'site_id' => $site->id,
                'token_id' => $customer->paymentTokens()->latest('id')->value('id'),
                ...($order->isTrial() ? [
                    // Free until the trial ends; the first charge is dated the
                    // moment it does, and EndSiteAgentTrialsJob hands it to
                    // the ordinary renewal from there.
                    'status' => SubscriptionStatus::Trialing,
                    'trial_ends_at' => now()->addDays((int) $order->trial_days),
                    'next_charge_at' => now()->addDays((int) $order->trial_days),
                ] : [
                    // The card was captured with this charge and the first cycle is
                    // paid, so it collects itself from here on.
                    'status' => SubscriptionStatus::Active,
                    'current_period_start' => now()->toDateString(),
                    'current_period_end' => $this->periodEnd($plan)->toDateString(),
                    'next_charge_at' => $this->periodEnd($plan),
                ]),
            ]);

            // Re-used rather than created blindly: the same number may already
            // be bound to this site from an earlier attempt, and colliding with
            // the unique key here would fail a payment that has already left the
            // customer's card.
            $subscriber = SiteAgentSubscriber::firstOrNew([
                'phone' => $order->manager_phone,
                'site_id' => $site->id,
            ]);

            $subscriber->fill([
                'customer_id' => $customer->id,
                'name' => $order->manager_name ?: $subscriber->name,
            ])->forceFill([
                'revoked_at' => null,
                'revoked_reason' => null,
            ])->save();

            if ($order->wantsUsToInstall()) {
                SiteInstallation::firstOrCreate(
                    ['site_agent_order_id' => $order->id],
                    [
                        'customer_id' => $customer->id,
                        'site_id' => $site->id,
                        'domain' => $order->domain,
                        'state' => SiteInstallation::AWAITING_ACCESS,
                    ],
                );
            }

            $order->update([
                'site_id' => $site->id,
                'subscription_id' => $subscription->id,
                'status' => SiteAgentOrder::PAID,
                'fulfilled_at' => now(),
            ]);
        });

        // Outside the transaction: nothing external may run before the rows it
        // talks about are committed.
        if ($subscriber !== null) {
            SendSiteAgentVerificationJob::dispatch($subscriber->id);
        }

        // The page after payment is one browser crash away from being gone, and
        // with it the only address that can show the connection codes again.
        // Wrapped, because a mail server having a bad minute must not leave a
        // paid order unfulfilled — the service is already on either way.
        $mailed = rescue(function () use ($order): bool {
            Mail::to($order->buyer_email)->send(new SiteAgentActivationMail($order));

            return true;
        }, false);

        if (! $mailed) {
            SystemLog::record('warning', 'siteagent',
                "שליחת מייל ההפעלה ל{$order->buyer_email} נכשלה — השירות פעיל, יש לשלוח את הקישור ידנית.",
                ['order_id' => $order->id, 'reference' => $order->reference]);
        }

        SystemLog::record('info', 'siteagent',
            "רכישה עצמית של בוט ניהול האתר הושלמה: {$order->domain} ({$order->buyer_email})",
            ['order_id' => $order->id, 'install_mode' => $order->install_mode]);

        return $order->fresh();
    }

    /**
     * The site the agent will drive.
     *
     * Matched on the domain the buyer typed, because a customer who already has
     * this site with us — one we monitor, one they bought a plugin for — must
     * not end up with a second row for it. Two rows for one website is how the
     * agent connects to one of them while every screen shows the other.
     *
     * Created DISCONNECTED. The plugin is not installed yet; saying otherwise
     * would put a green "מחובר" on a site nothing can reach, and the agent would
     * accept instructions it cannot carry out.
     */
    private function site(SiteAgentOrder $order, Customer $customer): Site
    {
        $existing = Site::query()
            ->where('customer_id', $customer->id)
            ->where('domain', $order->domain)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Site::create([
            'customer_id' => $customer->id,
            'domain' => $order->domain,
            'status' => SiteStatus::Active,
            'mcp_enabled' => false,
        ]);
    }

    /**
     * How many free days this buyer gets: the plan's trial, once.
     *
     * Once per customer and once per website. A trial that could be taken again
     * with a new email for the same site, or by the same business for each of
     * its sites in turn, is a free product with extra steps.
     */
    private function trialDaysFor(Plan $plan, Customer $customer, string $domain): int
    {
        if (! $plan->hasTrial()) {
            return 0;
        }

        $hadTrial = SiteAgentOrder::query()
            ->where('trial_days', '>', 0)
            ->where('status', SiteAgentOrder::PAID)
            ->where(fn ($q) => $q->where('customer_id', $customer->id)->orWhere('domain', $domain))
            ->exists();

        $hadService = $this->billing->subscriptionFor($customer) !== null;

        return $hadTrial || $hadService ? 0 : (int) $plan->trial_days;
    }

    /**
     * The card page for a trial: the card is validated and kept, nothing charged.
     *
     * The same hosted page a customer uses to update their card, so the card
     * number never touches us and the webhook path is the one already proven.
     */
    private function trialCardPage(SiteAgentOrder $order, Customer $customer): string
    {
        $done = route('store.agent.done', ['reference' => $order->reference]);

        try {
            $page = $this->cardcom->createTokenLowProfile($customer->id, $done, $done, CardcomWebhook::url());
        } catch (\Throwable $e) {
            $order->update(['status' => SiteAgentOrder::FAILED]);

            throw $e;
        }

        if (! str_starts_with((string) $page['url'], 'https://') || blank($page['low_profile_id'])) {
            $order->update(['status' => SiteAgentOrder::FAILED]);

            throw new \RuntimeException('לא הצלחנו לפתוח את עמוד הכרטיס. נסו שוב בעוד רגע.');
        }

        $order->update(['cardcom_low_profile_id' => $page['low_profile_id']]);

        // The marker the webhook clears once this exact session is handled, so
        // a manual "sync card" cannot process it a second time.
        $customer->update(['pending_card_lp_id' => $page['low_profile_id']]);

        return (string) $page['url'];
    }

    private function periodEnd(Plan $plan): Carbon
    {
        return $plan->billing_interval === BillingInterval::Yearly
            ? now()->addYear()
            : now()->addMonth();
    }

    /**
     * The buyer's customer record: an existing one when the address is known.
     *
     * Matched on the email — what they typed and where everything will be sent.
     * A returning customer buying the agent for a second site must not become a
     * second customer; that is how one business ends up with two balances and
     * two dunning ladders.
     */
    private function customer(string $name, string $email, string $phone): Customer
    {
        $existing = Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();

        if ($existing !== null) {
            if (blank($existing->phone)) {
                $existing->update(['phone' => $phone]);
            }

            return $existing;
        }

        return Customer::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]);
    }
}
