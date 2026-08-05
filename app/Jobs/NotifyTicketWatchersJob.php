<?php

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Mail\NotificationMail;
use App\Models\TicketMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Forward an inbound ticket message to the addresses copied on that ticket.
 *
 * Our own replies reach them as a plain Cc, but the customer's answers never
 * would — and a copied bookkeeper who sees only our half of the conversation is
 * worse off than one who was never copied at all: they read our answer to a
 * question they cannot see.
 *
 * Best-effort by design. A watcher copy that fails must never retry the message
 * itself into existence twice, so failures are logged and dropped.
 */
class NotifyTicketWatchersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public function __construct(public int $ticketMessageId, public ?string $exceptEmail = null) {}

    public function handle(): void
    {
        $message = TicketMessage::with('ticket.customer', 'ticket.watchers')->find($this->ticketMessageId);

        if (! $message || $message->direction !== MessageDirection::Inbound) {
            return;
        }

        $ticket = $message->ticket;

        // Never send a watcher their own message back.
        $recipients = $ticket->watcherEmails($this->exceptEmail);

        if ($recipients === []) {
            return;
        }

        $who = $message->sender_label ?: ($ticket->customer?->name ?? $ticket->senderName());
        // The tag keeps their reply on this ticket, exactly like the Cc on ours.
        $subject = "{$ticket->subject} {$ticket->emailTag()}";

        try {
            Mail::to($recipients)->send(new NotificationMail(
                $subject,
                "הודעה חדשה בפנייה #{$ticket->id} מאת {$who}:\n\n{$message->body}",
                (string) config('billing.email.support_address') ?: null,
            ));
        } catch (\Throwable $e) {
            Log::warning('NotifyTicketWatchersJob: watcher copy failed', [
                'ticket' => $ticket->id,
                'message' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
