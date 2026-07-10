<?php

namespace App\Observers;

use App\Enums\TicketStatus;
use App\Jobs\SendTicketNotificationJob;
use App\Models\Ticket;

/**
 * Ticket lifecycle notifications: when the team resolves a ticket, the
 * customer is told automatically over the original channel. (The "received"
 * acknowledgement is dispatched by TicketIntake at creation, where the
 * inbound context is known.)
 */
class TicketObserver
{
    public function updated(Ticket $ticket): void
    {
        if ($ticket->wasChanged('status') && $ticket->status === TicketStatus::Resolved) {
            SendTicketNotificationJob::dispatch($ticket->id, 'ticket.resolved');
        }
    }
}
