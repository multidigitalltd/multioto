<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Http\Controllers\Controller;
use App\Jobs\IngestEmailMessageJob;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives inbound-parse webhooks from the transactional email provider
 * (a customer replying to a support address). Verifies the shared secret,
 * records the delivery idempotently and queues ingestion.
 */
class EmailWebhookController extends Controller
{
    use VerifiesWebhookSecret;

    public function __invoke(Request $request): Response
    {
        // Fail closed: a blank/unset secret must never mean "accept everything".
        // Secret may arrive via an X-Webhook-Secret header or the legacy query.
        $secret = (string) config('billing.email.webhook_secret');

        abort_unless(
            $secret !== '' && hash_equals($secret, $this->providedSecret($request)),
            403,
        );

        [$event, $fresh] = WebhookEvent::record(
            WebhookSource::Email,
            'inbound_message',
            $request->input('MessageID') ?? $request->input('message_id'),
            // Never persist the shared secret into webhook_events.payload.
            $request->except('secret'),
        );

        if ($fresh) {
            IngestEmailMessageJob::dispatch($event->id);
        }

        return response('OK', 200);
    }
}
