<?php

namespace App\Services\Licensing;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\PluginOrder;
use App\Models\PluginProduct;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\Billing\ManualChargeService;
use Illuminate\Support\Facades\DB;

/**
 * Buying a plugin without us being involved.
 *
 * The shape of it is decided by one fact: **the buyer leaves.** They go to
 * Cardcom's page, and what comes back is a webhook, minutes later, into a
 * process that has no browser and no session. So the order is written down
 * before they go, and everything that happens afterwards happens from it.
 *
 * Nothing is issued before the money arrives. A licence created hopefully at
 * checkout is a licence somebody keeps when they abandon the payment page — and
 * "start" ing a card payment is not the same event as finishing one.
 *
 * The card is captured together with the charge, so a yearly licence can renew
 * itself. Without the token the customer would be asked for their card again
 * every year — which is not renewal, it is re-selling.
 */
class PluginCheckout
{
    public function __construct(
        private ManualChargeService $charges,
        private LicenseIssuer $issuer,
    ) {}

    /**
     * Start a purchase: record the order and hand back the payment page.
     *
     * @return array{order: PluginOrder, url: string}
     */
    public function start(PluginProduct $product, string $name, string $email, ?string $phone): array
    {
        $price = (int) ($product->price_agorot ?? 0);

        if ($price <= 0) {
            throw new \RuntimeException('לתוסף הזה לא נקבע מחיר, ולכן לא ניתן לרכוש אותו כרגע.');
        }

        // The customer record is created now, not at payment: the invoice, the
        // renewal and every later support conversation all hang off it, and the
        // webhook has no form to build one from.
        $customer = $this->customer($name, $email, $phone);
        $total = $this->withVat($price, (bool) $customer->vat_exempt);

        $order = PluginOrder::create([
            'plugin_product_id' => $product->id,
            'customer_id' => $customer->id,
            'buyer_name' => $name,
            'buyer_email' => $email,
            'buyer_phone' => $phone,
            'sites_limit' => (int) ($product->default_sites_limit ?? 1),
            'billing_interval' => $product->billing_interval,
            'total_agorot' => $total,
            'status' => PluginOrder::PENDING,
            'reference' => PluginOrder::newReference(),
        ]);

        try {
            $page = $this->charges->createHostedPage(
                customer: $customer,
                totalAgorot: $total,
                description: $product->name.' — רישיון'.$this->termLabel($product),
                notes: 'רכישה עצמית באתר',
                withToken: $product->billingInterval() !== null,
                successUrl: route('store.done', ['reference' => $order->reference]),
                failureUrl: route('store.done', ['reference' => $order->reference]),
            );
        } catch (\Throwable $e) {
            $order->update(['status' => PluginOrder::FAILED]);

            throw $e;
        }

        $order->update(['charge_id' => $page['charge']->id]);

        return ['order' => $order, 'url' => $page['url']];
    }

    /**
     * The money arrived — give them what they bought.
     *
     * Called from the charge observer, so it runs whichever way the payment was
     * confirmed: the webhook, or the reconciliation that finishes a charge whose
     * webhook was lost. Somebody who paid must never depend on which of those
     * happened.
     */
    public function fulfil(Charge $charge): ?PluginOrder
    {
        $order = PluginOrder::query()->where('charge_id', $charge->id)->first();

        if ($order === null || $order->isFulfilled()) {
            return $order;
        }

        $product = $order->product;
        $customer = $order->customer;

        if ($product === null || $customer === null) {
            return $order;
        }

        $interval = $order->billing_interval !== null ? BillingInterval::tryFrom($order->billing_interval) : null;
        $term = $interval !== null ? $product->termEnd(now()) : null;

        DB::transaction(function () use ($order, $product, $customer, $interval, $term): void {
            // Renewal rides the ordinary subscription machinery — the same
            // charging, invoicing, retrying and dunning as everything else we
            // sell. The first period is the one just paid for, so the next
            // charge is a full term away.
            $subscription = $interval !== null
                ? Subscription::create([
                    'customer_id' => $customer->id,
                    'name' => $product->name.' — רישיון',
                    'billing_interval' => $interval,
                    'price_agorot_override' => $product->price_agorot,
                    'vat_applies' => true,
                    'status' => SubscriptionStatus::Active,
                    'token_id' => $customer->paymentTokens()->latest('id')->value('id'),
                    'current_period_start' => now()->toDateString(),
                    'current_period_end' => $term?->toDateString(),
                    'next_charge_at' => $term,
                ])
                : null;

            [$license, , $emailed] = $this->issuer->issue([
                'plugin_product_id' => $product->id,
                'customer_id' => $customer->id,
                'subscription_id' => $subscription?->id,
                'sites_limit' => $order->sites_limit,
                'expires_at' => $term?->toDateString(),
                'email' => $order->buyer_email,
            ]);

            $order->update([
                'license_id' => $license->id,
                'status' => PluginOrder::PAID,
                'fulfilled_at' => now(),
            ]);

            SystemLog::record($emailed ? 'info' : 'warning', 'licensing',
                "רכישה עצמית הושלמה: {$product->name} ל{$order->buyer_email}"
                    .($emailed ? ' — המפתח נשלח.' : ' — שליחת המפתח במייל נכשלה, יש לשלוח ידנית.'),
                ['order_id' => $order->id, 'license_id' => $license->id]);
        });

        return $order->fresh();
    }

    /**
     * The buyer's customer record: an existing one when the address is known,
     * a new one otherwise.
     *
     * Matched on the email because that is what the buyer typed and what the
     * key will be sent to. A returning customer buying a second plugin must not
     * become a second customer — that is how one business ends up with two
     * balances and two dunning ladders.
     */
    private function customer(string $name, string $email, ?string $phone): Customer
    {
        $existing = Customer::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();

        if ($existing !== null) {
            // Their phone if we did not have one; never overwrite what is there.
            if (blank($existing->phone) && filled($phone)) {
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

    /**
     * The price the buyer is charged. Prices are quoted before VAT, and the
     * payment page takes what will actually be taken off the card — so the
     * addition happens here, once, and the charge splits it back out for the
     * invoice.
     */
    private function withVat(int $netAgorot, bool $exempt): int
    {
        if ($exempt) {
            return $netAgorot;
        }

        return (int) round($netAgorot * (1 + (float) config('billing.vat_rate')));
    }

    private function termLabel(PluginProduct $product): string
    {
        return match ($product->billingInterval()) {
            BillingInterval::Yearly => ' לשנה',
            BillingInterval::Monthly => ' לחודש',
            default => '',
        };
    }
}
