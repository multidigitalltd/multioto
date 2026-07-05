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
    public function __invoke(Request $request): Response
    {
        abort_unless(
            hash_equals((string) config('billing.cardcom.webhook_secret'), (string) $request->query('secret')),
            403,
        );

        [$event, $fresh] = WebhookEvent::record(
            WebhookSource::Cardcom,
            'low_profile_completed',
            $request->input('LowProfileId'),
            $request->all(),
        );

        if ($fresh) {
            ProcessCardcomLowProfileJob::dispatch($event->id);
        }

        return response('OK', 200);
    }
}
