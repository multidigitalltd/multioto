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
    /**
     * Maintain the "waiting for customer" clock: stamp pending_since when the
     * ticket enters Pending (and clear any prior reminder), and clear both when
     * it leaves Pending (e.g. the customer replied). Runs before save so the
     * columns persist in the same write.
     */
    public function saving(Ticket $ticket): void
    {
        if (! $ticket->isDirty('status')) {
            return;
        }

        if ($ticket->status === TicketStatus::Pending) {
            $ticket->pending_since = now();
            $ticket->pending_reminded_at = null;
        } else {
            $ticket->pending_since = null;
            $ticket->pending_reminded_at = null;
        }

        // Reopened (terminal → non-terminal): reset the CSAT cycle so any rating
        // from the previous resolution is dropped and the NEXT resolution invites
        // the customer again — the dashboard must reflect the final outcome, not a
        // rating given before the ticket was reopened.
        $original = $ticket->getOriginal('status');
        $wasStatus = $original instanceof TicketStatus ? $original : TicketStatus::tryFrom((string) $original);

        if ($wasStatus !== null
            && in_array($wasStatus, Ticket::TERMINAL, true)
            && ! in_array($ticket->status, Ticket::TERMINAL, true)) {
            $ticket->csat_rating = null;
            $ticket->csat_comment = null;
            $ticket->csat_requested_at = null;
            $ticket->csat_rated_at = null;
        }
    }

    public function updated(Ticket $ticket): void
    {
        if ($ticket->wasChanged('status') && $ticket->status === TicketStatus::Resolved) {
            SendTicketNotificationJob::dispatch($ticket->id, 'ticket.resolved');
        }
    }
}
