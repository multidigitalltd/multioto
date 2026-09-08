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
 *
 * The close is SILENT by design. The customer was asked once and chose not to
 * answer; "we closed your ticket" on top of that is a message about our filing
 * rather than about their problem. Nothing is lost either — a reply reopens the
 * ticket, and the history stays on it.
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

        $cutoff = now()->subDays(min($reminderDays, $closeDays));

        Ticket::query()
            ->where('status', TicketStatus::Pending)
            // Only tickets already old enough for SOME action (reminder is the
            // earlier threshold), oldest first, THEN the cap — so fresh rows can
            // never crowd an overdue ticket out of the bounded daily sweep.
            ->where(fn ($query) => $query
                ->where('pending_since', '<=', $cutoff)
                // A ticket that went Pending before this column existed carries
                // no clock, and `whereNotNull` quietly excluded it — so the
                // OLDEST waiting tickets in the system, the ones this sweep is
                // most for, were the only ones it could never close. For those
                // the clock is the last time anything happened on the ticket.
                ->orWhere(fn ($legacy) => $legacy
                    ->whereNull('pending_since')
                    ->where('updated_at', '<=', $cutoff)))
            // Ordered by the same clock the decision uses. Sorting by
            // pending_since alone puts the unstamped rows first on SQLite and
            // last on Postgres, so the cap would cut a different set on each.
            ->orderByRaw('COALESCE(pending_since, updated_at) ASC')
            ->limit(200)
            ->get()
            ->each(function (Ticket $ticket) use ($reminderDays, $closeDays) {
                $since = $ticket->pending_since ?? $ticket->updated_at;

                if ($since === null) {
                    return; // No clock at all — nothing honest to measure against.
                }

                $silentDays = $since->diffInDays(now());

                if ($silentDays >= $closeDays) {
                    // Quiet close: the customer already got the reminder and chose
                    // not to answer — a "we closed your ticket" message on top of
                    // that is just noise, so no notification is sent. Replying
                    // still reopens the ticket as usual.
                    $ticket->update(['status' => TicketStatus::Closed, 'resolved_at' => now()]);

                    return;
                }

                // Still waiting: give an unstamped ticket the clock we just read,
                // so the panel shows since when it has been waiting instead of a
                // blank, and the next run treats it as an ordinary row. Written
                // without touching updated_at — that column IS its clock here,
                // and bumping it would reset the wait to zero every night.
                if ($ticket->pending_since === null) {
                    $ticket->timestamps = false;
                    $ticket->forceFill(['pending_since' => $since])->saveQuietly();
                    $ticket->timestamps = true;
                }

                if ($silentDays >= $reminderDays && $ticket->pending_reminded_at === null) {
                    // Tag the notification with this pending cycle so a ticket that
                    // has gone Pending → replied → Pending again is reminded afresh
                    // (the status-only dedupe would otherwise swallow it).
                    SendTicketNotificationJob::dispatch(
                        $ticket->id, 'ticket.reminder', 'cycle-'.$since->getTimestamp(),
                    );
                    $ticket->update(['pending_reminded_at' => now()]);
                }
            });
    }
}
