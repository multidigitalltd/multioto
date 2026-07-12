<?php

namespace App\Services\Notifications;

use App\Mail\NotificationMail;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Internal alerts to the business team — a new ticket, a customer reply — sent
 * to the WhatsApp approvals number/group AND the team email, ALWAYS and
 * independently of the AI layer. Each channel is best-effort: one failing never
 * blocks the other, and never breaks ticket handling.
 */
class TeamNotifier
{
    public function __construct(private WahaClient $waha) {}

    /** A brand-new ticket landed. */
    public function newTicket(Ticket $ticket): void
    {
        $who = $ticket->customer?->name ?? 'פונה לא מזוהה';
        $title = "🆕 פנייה חדשה #{$ticket->id} ({$ticket->channel->getLabel()})";
        $body = "מ: {$who}\nנושא: {$ticket->subject}";

        $this->send($title, $body, $ticket);
    }

    /** A customer replied on an existing ticket. */
    public function newReply(Ticket $ticket, TicketMessage $message): void
    {
        $who = $ticket->customer?->name ?? 'פונה לא מזוהה';
        $title = "💬 תגובה חדשה בפנייה #{$ticket->id}";
        $body = "מ: {$who}\nנושא: {$ticket->subject}\n\n".Str::limit($message->body, 300);

        $this->send($title, $body, $ticket);
    }

    /** Deliver an alert to both team channels (each independent, best-effort). */
    protected function send(string $title, string $body, Ticket $ticket): void
    {
        $panelUrl = rtrim((string) config('app.url'), '/')."/admin/tickets/{$ticket->id}";

        // WhatsApp — the approvals number/group.
        if (($chat = $this->teamChat()) !== null) {
            try {
                $this->waha->sendMessage($chat, "{$title}\n{$body}\n\nלצפייה: {$panelUrl}");
            } catch (\Throwable $e) {
                Log::warning('TeamNotifier: WhatsApp alert failed', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
            }
        }

        // Email — the team inbox.
        if (($email = (string) config('billing.notifications.team_email')) !== '') {
            try {
                Mail::to($email)->send(new NotificationMail($title, "{$body}\n\nלצפייה בפנייה: {$panelUrl}"));
            } catch (\Throwable $e) {
                Log::warning('TeamNotifier: email alert failed', ['ticket_id' => $ticket->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** The team WhatsApp chat (reuses the approvals number/group). */
    protected function teamChat(): ?string
    {
        $number = (string) config('billing.waha.owner_number');

        return $number !== '' ? $this->waha->normalizeChatId($number) : null;
    }
}
