<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Notifications\DeliveryEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Delivery, open, bounce and spam-complaint events from the email provider.
 *
 * Separate from the inbound-parse webhook next door: that one turns a customer
 * REPLY into a ticket, this one only records what happened to a message we
 * sent. Same shared secret, same idempotent recording in webhook_events.
 *
 * Applied inline rather than queued: each event is a couple of indexed writes,
 * and a stats page that lags behind the queue is worse than one that is simply
 * current.
 */
class EmailDeliveryWebhookController extends Controller
{
    use VerifiesWebhookSecret;

    public function __invoke(Request $request, DeliveryEvents $events): Response
    {
        // Fail closed: a blank/unset secret must never mean "accept everything".
        $secret = (string) config('billing.email.webhook_secret');

        abort_unless(
            $secret !== '' && hash_equals($secret, $this->providedSecret($request)),
            403,
        );

        $payload = $request->except('secret');
        $type = (string) $request->input('RecordType', 'unknown');

        // Identity is per EVENT, not per message. The MessageID repeats across
        // record types for one message, so the type is part of it — otherwise a
        // Delivery would suppress the Open that follows. And a message can be
        // opened many times, so an Open carries no stable id at all: passing
        // null makes WebhookEvent hash the payload, which still collapses an
        // exact replay while letting a genuine second read through.
        $id = $request->input('MessageID');

        [$event, $fresh] = WebhookEvent::record(
            WebhookSource::Email,
            'delivery_'.strtolower($type),
            ($type !== 'Open' && filled($id)) ? $type.':'.$id : null,
            $payload,
        );

        if ($fresh) {
            $events->apply($payload);
            $event->markProcessed();
        }

        return response('OK', 200);
    }
}
