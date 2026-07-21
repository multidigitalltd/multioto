<?php

namespace App\Jobs;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Alert the team when an open ticket blows past its first-response SLA target
 * without a reply. Fires ONCE per ticket (sla_alerted_at guards against nagging)
 * and only for tickets still awaiting our first response — a reply or a move to
 * "waiting for customer" takes it out of scope.
 */
class CheckSlaBreachesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(TeamNotifier $team): void
    {
        Ticket::query()
            ->with('customer')
            ->where('status', TicketStatus::Open)
            ->whereNull('first_response_at')
            ->whereNull('sla_alerted_at')
            ->orderBy('created_at')
            ->limit(200)
            ->get()
            // Per-priority target lives in config, so the breach test runs in PHP.
            ->filter(fn (Ticket $ticket): bool => $ticket->firstResponseSlaStatus() === 'breached')
            ->each(function (Ticket $ticket) use ($team): void {
                $hours = $ticket->slaFirstResponseHours();
                $team->alert(
                    "⏱️ חריגת SLA — פנייה #{$ticket->id} ללא מענה",
                    "פנייה מ{$ticket->senderName()} פתוחה מעל {$hours} שעות ללא תגובה ראשונה.\n"
                        ."נושא: {$ticket->subject}\n"
                        .'עדיפות: '.($ticket->priority?->getLabel() ?? '—'),
                    rtrim((string) config('app.url'), '/')."/admin/tickets/{$ticket->id}/edit",
                );

                $ticket->update(['sla_alerted_at' => now()]);
            });
    }
}
