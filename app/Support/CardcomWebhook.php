<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The Cardcom Low Profile webhook (the `WebHookUrl` Cardcom POSTs to when a
 * card capture / charge completes). Our endpoint is fail-closed on a shared
 * secret, so a blank secret means every Cardcom callback is rejected (403) and
 * cards never sync. To make that impossible, the secret is generated and
 * persisted on first use — exactly like the WhatsApp webhook secret.
 */
class CardcomWebhook
{
    /** The shared secret, generated and stored the first time it's needed. */
    public static function secret(): string
    {
        $secret = (string) config('billing.cardcom.webhook_secret');

        if ($secret === '') {
            $secret = Str::random(40);
            Setting::put('cardcom.webhook_secret', $secret);
            config(['billing.cardcom.webhook_secret' => $secret]);
        }

        return $secret;
    }

    /** The absolute URL to hand Cardcom as WebHookUrl (secret in the query). */
    public static function url(): string
    {
        return URL::route('webhooks.cardcom', ['secret' => self::secret()]);
    }
}
