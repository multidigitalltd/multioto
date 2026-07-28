<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookSource;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Notifications\DeliveryEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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

        // Claim on processed_at, not on "was this row just created" — a delivery
        // that recorded the row and then failed mid-apply must still be finished
        // by the provider's retry, and keying on freshness would drop it for
        // good. Only the writer that flips null → now() does the work; a
        // concurrent delivery blocks on that row and then finds it taken.
        //
        // Claim and apply share one transaction so a failure halfway leaves
        // nothing behind: the claim rolls back together with whatever the event
        // had already written, and the retry starts from a clean slate instead
        // of adding a second open to the same read.
        DB::transaction(function () use ($event, $events, $payload): void {
            $claimed = WebhookEvent::whereKey($event->getKey())
                ->whereNull('processed_at')
                ->update(['processed_at' => now()]);

            if ($claimed === 1) {
                $events->apply($payload);
            }
        });

        return response('OK', 200);
    }
}
