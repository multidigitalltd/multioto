<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessCardcomLowProfileJob;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Cardcom Low Profile completion webhooks (card capture / recovery
 * payment). Verifies the shared secret, records the event idempotently, and
 * defers all processing to the queue — nothing heavy runs in the request.
 */
class CardcomWebhookController extends Controller
{
    use VerifiesWebhookSecret;

    public function __invoke(Request $request): Response
    {
        // Fail closed: a blank/unset secret must never mean "accept everything".
        // Accept the secret from a header (preferred — stays out of URLs/logs) or
        // the legacy ?secret= query param.
        $secret = (string) config('billing.cardcom.webhook_secret');

        abort_unless(
            $secret !== '' && hash_equals($secret, $this->providedSecret($request)),
            403,
        );

        [$event, $fresh] = WebhookEvent::record(
            WebhookSource::Cardcom,
            'low_profile_completed',
            $request->input('LowProfileId'),
            // Never persist the shared secret into webhook_events.payload.
            $request->except('secret'),
        );

        if ($fresh) {
            ProcessCardcomLowProfileJob::dispatch($event->id);
        }

        return response('OK', 200);
    }
}
