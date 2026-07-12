<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Alert the business team about ticket activity (new ticket / customer reply)
 * on WhatsApp + email. Queued so the network calls never block intake, and
 * always runs regardless of the AI layer.
 */
class NotifyTeamJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(
        public int $ticketId,
        public string $event,        // new_ticket | new_reply
        public ?int $messageId = null,
    ) {}

    public function handle(TeamNotifier $notifier): void
    {
        $ticket = Ticket::with('customer')->find($this->ticketId);

        if (! $ticket) {
            return;
        }

        if ($this->event === 'new_reply' && $this->messageId !== null) {
            $message = TicketMessage::find($this->messageId);
            if ($message) {
                $notifier->newReply($ticket, $message);
            }

            return;
        }

        $notifier->newTicket($ticket);
    }
}
