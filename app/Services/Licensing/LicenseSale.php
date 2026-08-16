<?php

namespace App\Services\Licensing;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\License;
use App\Models\PluginProduct;
use App\Models\Subscription;
use App\Models\SystemLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Selling one licence.
 *
 * Two things happen, and they are deliberately the two things this system
 * already knows how to do:
 *
 *  · **The money becomes a subscription** — for a yearly or monthly licence.
 *    Not a licence-shaped billing path of its own: the ordinary machine already
 *    charges the card, issues the invoice, retries a failure and runs the
 *    dunning ladder, and a second copy of any of that is a second place for
 *    money to go wrong. A one-off sale creates no subscription and is charged
 *    from the manual-charge screen like anything else.
 *
 *  · **The licence is issued and emailed** — working immediately, for the full
 *    term. The customer bought it; making them wait for the collection run to
 *    fire would be a worse first hour than the risk of a card that declines,
 *    which the dunning ladder and the revoke button both answer.
 *
 * The first charge is left to the scheduler rather than fired from here. This
 * runs inside somebody's click, and a card transaction inside a web request is
 * the one thing this codebase does not do.
 */
class LicenseSale
{
    public function __construct(private LicenseIssuer $issuer) {}

    /**
     * @return array{license: License, key: string, emailed: bool, subscription: ?Subscription}
     */
    public function sell(
        PluginProduct $product,
        Customer $customer,
        int $sitesLimit,
        ?BillingInterval $interval,
        ?int $priceAgorot = null,
        ?string $email = null,
        ?string $notes = null,
    ): array {
        $price = $priceAgorot ?? $product->price_agorot;
        $term = $interval !== null ? $this->termEnd($interval) : null;

        // One transaction: a subscription created without the licence it pays
        // for would charge the customer every year for nothing, and a licence
        // created without the subscription would never renew. Neither half is
        // worth having on its own.
        [$license, $key, $emailed, $subscription] = DB::transaction(function () use (
            $product, $customer, $sitesLimit, $interval, $price, $term, $email, $notes
        ): array {
            $subscription = $interval !== null
                ? Subscription::create([
                    'customer_id' => $customer->id,
                    'name' => $product->name.' — רישיון',
                    'billing_interval' => $interval,
                    'price_agorot_override' => $price,
                    'vat_applies' => true,
                    'status' => SubscriptionStatus::Active,
                    'current_period_start' => now()->toDateString(),
                    'current_period_end' => $term?->toDateString(),
                    // Collected by the ordinary run, within the quarter hour.
                    'next_charge_at' => now(),
                ])
                : null;

            [$license, $key, $sent] = $this->issuer->issue([
                'plugin_product_id' => $product->id,
                'customer_id' => $customer->id,
                'subscription_id' => $subscription?->id,
                'sites_limit' => $sitesLimit,
                'expires_at' => $term?->toDateString(),
                'email' => $email ?: $customer->email,
                'notes' => $notes,
            ]);

            return [$license, $key, $sent, $subscription];
        });

        SystemLog::record('info', 'licensing',
            "נמכר רישיון ל{$product->name} ללקוח {$customer->name}"
                .($subscription !== null
                    ? " — מנוי {$interval->getLabel()} שייגבה בהרצת הגבייה הקרובה."
                    : ' — מכירה חד-פעמית; החיוב נעשה ידנית.'),
            ['license_id' => $license->id, 'customer_id' => $customer->id, 'subscription_id' => $subscription?->id]);

        return ['license' => $license, 'key' => $key, 'emailed' => $emailed, 'subscription' => $subscription];
    }

    private function termEnd(BillingInterval $interval): Carbon
    {
        return match ($interval) {
            BillingInterval::Yearly => now()->addYear(),
            BillingInterval::Monthly => now()->addMonth(),
        };
    }
}
