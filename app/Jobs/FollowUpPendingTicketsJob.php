<?php

namespace App\Jobs;

use App\Enums\TicketStatus;
use App\Jobs\Concerns\PausesForShabbat;
use App\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Chase tickets stuck "waiting for customer" (Pending): once they have been
 * silent for reminder_days, send the customer one reminder; after close_days
 * of silence, auto-close the ticket. Timings live in config/billing.php.
 * Dispatched once a day by the scheduler.
 */
class FollowUpPendingTicketsJob implements ShouldQueue
{
    use PausesForShabbat;
    use Queueable;

    public function handle(): void
    {
        if ($this->rescheduledForShabbat()) {
            return;
        }

        $config = config('billing.support.pending_followup');

        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $reminderDays = (int) ($config['reminder_days'] ?? 3);
        $closeDays = (int) ($config['close_days'] ?? 7);

        Ticket::query()
            ->where('status', TicketStatus::Pending)
            ->whereNotNull('pending_since')
            // Only tickets already old enough for SOME action (reminder is the
            // earlier threshold), oldest first, THEN the cap — so fresh rows can
            // never crowd an overdue ticket out of the bounded daily sweep.
            ->where('pending_since', '<=', now()->subDays(min($reminderDays, $closeDays)))
            ->oldest('pending_since')
            ->limit(200)
            ->get()
            ->each(function (Ticket $ticket) use ($reminderDays, $closeDays) {
                $silentDays = $ticket->pending_since->diffInDays(now());

                if ($silentDays >= $closeDays) {
                    // Quiet close: the customer already got the reminder and chose
                    // not to answer — a "we closed your ticket" message on top of
                    // that is just noise, so no notification is sent. Replying
                    // still reopens the ticket as usual.
                    $ticket->update(['status' => TicketStatus::Closed, 'resolved_at' => now()]);

                    return;
                }

                if ($silentDays >= $reminderDays && $ticket->pending_reminded_at === null) {
                    // Tag the notification with this pending cycle so a ticket that
                    // has gone Pending → replied → Pending again is reminded afresh
                    // (the status-only dedupe would otherwise swallow it).
                    SendTicketNotificationJob::dispatch(
                        $ticket->id, 'ticket.reminder', 'cycle-'.$ticket->pending_since->getTimestamp(),
                    );
                    $ticket->update(['pending_reminded_at' => now()]);
                }
            });
    }
}
