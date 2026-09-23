<?php

namespace App\Services\SiteAgent;

use App\Enums\BillingInterval;
use App\Enums\SiteStatus;
use App\Enums\SubscriptionStatus;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Buying סוכן האתר without us being involved.
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
            'install_mode' => in_array($buyer['install_mode'] ?? null, SiteAgentOrder::INSTALL_MODES, true)
                ? $buyer['install_mode']
                : SiteAgentOrder::INSTALL_SELF,
            'status' => SiteAgentOrder::PENDING,
        ]);

        try {
            $page = $this->charges->createHostedPage(
                customer: $customer,
                totalAgorot: (int) $order->total_agorot,
                description: $plan->name.' — '.$domain,
                notes: 'רכישה עצמית של סוכן האתר',
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

        $customer = $order->customer;
        $plan = $order->plan;

        if ($customer === null || $plan === null) {
            return $order;
        }

        $subscriber = null;

        DB::transaction(function () use ($order, $customer, $plan, &$subscriber): void {
            $site = $this->site($order, $customer);

            // A returning customer who buys a second site does not get a second
            // subscription for the same service — the entitlement is theirs, and
            // two of them means being billed twice and chased on both. The
            // second site's number simply joins the one they have.
            $existing = $this->billing->subscriptionFor($customer);
            $reuse = $existing !== null && $existing->status !== SubscriptionStatus::Canceled;

            $subscription = $reuse ? $existing : Subscription::create([
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'site_id' => $site->id,
                // The card was captured with this charge and the first cycle is
                // paid, so it collects itself from here on.
                'status' => SubscriptionStatus::Active,
                'token_id' => $customer->paymentTokens()->latest('id')->value('id'),
                'current_period_start' => now()->toDateString(),
                'current_period_end' => $this->periodEnd($plan)->toDateString(),
                'next_charge_at' => $this->periodEnd($plan),
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
            "רכישה עצמית של סוכן האתר הושלמה: {$order->domain} ({$order->buyer_email})",
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
