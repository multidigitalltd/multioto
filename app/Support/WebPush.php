<?php

namespace App\Support;

/**
 * Browser (Web Push) notifications helper. Everything is opt-in and best-effort:
 * push is only "on" once the VAPID keys are configured (php artisan
 * webpush:vapid → .env), so an install without keys behaves exactly as before.
 */
class WebPush
{
    /** Whether Web Push is configured (VAPID key pair present). */
    public static function enabled(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    /** The public VAPID key the browser needs to subscribe (base64url), or null. */
    public static function publicKey(): ?string
    {
        return self::enabled() ? (string) config('webpush.vapid.public_key') : null;
    }
}
