<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * The one place that builds the signed, time-limited card-capture link. Signing
 * prevents customer-id enumeration and the TTL comes from config; keeping it in
 * a single helper means the route name and expiry can't drift across the many
 * callers (dunning, onboarding, the panel actions, the AI toolkit).
 */
class CardLink
{
    public static function for(int $customerId): string
    {
        return URL::temporarySignedRoute(
            'billing.update-card',
            now()->addHours((int) config('billing.card_update_link_ttl_hours')),
            ['customer' => $customerId],
        );
    }
}
