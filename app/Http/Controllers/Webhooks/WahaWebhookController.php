<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Http\Controllers\Controller;
use App\Jobs\IngestWhatsappMessageJob;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives WAHA (WhatsApp) events. Verifies the shared secret, records the
 * event idempotently, and queues ingestion — the request stays instant.
 */
class WahaWebhookController extends Controller
{
    use VerifiesWebhookSecret;

    public function __invoke(Request $request): Response
    {
        // Fail closed: a blank/unset secret must never mean "accept everything".
        // Secret may arrive via an X-Webhook-Secret header or the legacy query;
        // a refusal is remembered so a misconfigured sender is visible.
        $this->abortUnlessSecretMatches($request, (string) config('billing.waha.webhook_secret'), 'waha');

        // Only inbound messages become tickets; ack everything else (status
        // updates, session events) after recording it for audit.
        $eventType = (string) $request->input('event', 'unknown');

        [$event, $fresh] = WebhookEvent::record(
            WebhookSource::Waha,
            $eventType,
            $request->input('payload.id') ?? $request->input('id'),
            // Never persist the shared secret into webhook_events.payload.
            $request->except('secret'),
        );

        if ($fresh && $eventType === 'message') {
            IngestWhatsappMessageJob::dispatch($event->id);
        }

        return response('OK', 200);
    }
}
