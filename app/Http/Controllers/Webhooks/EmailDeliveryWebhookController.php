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

        [$event] = WebhookEvent::record(
            WebhookSource::Email,
            'delivery_'.strtolower($type),
            ($type !== 'Open' && filled($id)) ? $type.':'.$id : null,
            $payload,
        );

        // Claim on processed_at, not on "was this row just created". A delivery
        // that recorded the row and then failed mid-apply left processed_at
        // null, so the provider's retry still gets to finish the job; had we
        // keyed on freshness, that retry would see an existing row and drop the
        // event for good. Only the writer that flips null → now() does the work.
        $claimed = WebhookEvent::whereKey($event->getKey())
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        if ($claimed === 1) {
            try {
                $events->apply($payload);
            } catch (\Throwable $e) {
                // Not processed after all — release the claim so the retry can
                // pick it up instead of finding it permanently taken.
                WebhookEvent::whereKey($event->getKey())->update(['processed_at' => null]);

                throw $e;
            }
        }

        return response('OK', 200);
    }
}
