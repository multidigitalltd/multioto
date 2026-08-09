<?php

namespace App\Http\Controllers\Webhooks;

use App\Support\WebhookRejections;
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

    /**
     * Refuse anything that does not carry the configured secret — and remember
     * that a refusal happened.
     *
     * Failing closed is right, and so is telling the caller nothing. But the
     * refusal is only ever seen by the caller: a provider configured with the
     * address minus its secret keeps knocking every hour, while the panel looks
     * exactly like one where nobody ever set the integration up. The record
     * kept here is what lets a screen say "requests are arriving and being
     * turned away" instead of staying silent about it.
     */
    protected function abortUnlessSecretMatches(Request $request, string $secret, string $channel): void
    {
        // A blank secret must never mean "accept everything".
        if ($secret !== '' && hash_equals($secret, $this->providedSecret($request))) {
            return;
        }

        WebhookRejections::record($channel);

        abort(403);
    }
}
