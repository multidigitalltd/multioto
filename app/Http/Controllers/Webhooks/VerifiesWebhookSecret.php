<?php

namespace App\Http\Controllers\Webhooks;

use Illuminate\Http\Request;

/**
 * Shared secret extraction for inbound webhooks. Prefers an X-Webhook-Secret
 * header (keeps the secret out of URLs and reverse-proxy access logs) and falls
 * back to the legacy ?secret= query parameter, so providers can migrate to the
 * header without a breaking change.
 */
trait VerifiesWebhookSecret
{
    protected function providedSecret(Request $request): string
    {
        return (string) ($request->header('X-Webhook-Secret') ?? $request->query('secret', ''));
    }
}
