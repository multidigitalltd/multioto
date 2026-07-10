<?php

namespace App\Jobs;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Mail\NotificationMail;
use App\Models\Ticket;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Send a templated lifecycle notification (acknowledgement, resolved…) for a
 * ticket over its originating channel — WhatsApp tickets get a WhatsApp
 * message, everything else goes to the customer's email. The sent text is
 * recorded on the ticket as a system message, so the thread shows exactly
 * what the customer received. Silently skips when the template is disabled
 * or the ticket has no reachable destination.
 */
class SendTicketNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(
        public int $ticketId,
        public string $templateKey,
    ) {}

    public function handle(TemplateEngine $templates, WahaClient $waha): void
    {
        $ticket = Ticket::with('customer')->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        // Idempotency across retries/duplicate dispatches: one notification of
        // a given kind per ticket per status-cycle is enough.
        $dedupeKey = "notify-{$this->templateKey}-{$ticket->id}-".($ticket->status->value ?? '');

        if ($ticket->messages()->where('external_message_id', $dedupeKey)->exists()) {
            return;
        }

        $data = $templates->ticketData($ticket);

        if ($ticket->channel === TicketChannel::Whatsapp) {
            $chatId = $ticket->external_thread_ref
                ?? $ticket->customer?->whatsapp_jid
                ?? $ticket->customer?->phone;
            $rendered = $templates->render($this->templateKey, 'whatsapp', $data);

            if (! $chatId || $rendered === null) {
                return;
            }

            $waha->sendMessage($chatId, $rendered['body']);
            $this->record($ticket, MessageChannel::Whatsapp, $rendered['body'], $dedupeKey);

            return;
        }

        $email = $ticket->customer?->email;
        $rendered = $templates->render($this->templateKey, 'email', $data);

        if (! $email || $rendered === null) {
            return;
        }

        Mail::to($email)->send(new NotificationMail($rendered['subject'] ?? $ticket->subject, $rendered['body']));
        $this->record($ticket, MessageChannel::Email, $rendered['body'], $dedupeKey);
    }

    protected function record(Ticket $ticket, MessageChannel $channel, string $body, string $dedupeKey): void
    {
        $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => $channel,
            'body' => $body,
            'author' => MessageAuthor::System,
            'external_message_id' => $dedupeKey,
        ]);
    }
}
