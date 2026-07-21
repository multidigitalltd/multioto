<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Builds the signed, time-limited customer-facing payment link for a demand.
 * The link lives on our own domain and redirects to the Cardcom hosted page, so
 * a demand we cancel can answer "לא פעיל" instead of forwarding to pay. Signing
 * stops charge-id enumeration; the TTL comes from config.
 */
class PaymentLink
{
    public static function for(int $chargeId): string
    {
        return URL::temporarySignedRoute(
            'billing.pay',
            now()->addHours((int) config('billing.payment_link_ttl_hours')),
            ['charge' => $chargeId],
        );
    }

    /** The one-tap Bit variant of the payment link (same TTL and signing). */
    public static function bitFor(int $chargeId): string
    {
        return URL::temporarySignedRoute(
            'billing.pay-bit',
            now()->addHours((int) config('billing.payment_link_ttl_hours')),
            ['charge' => $chargeId],
        );
    }
}
