<?php

namespace App\Services\Notifications;

use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TeamPushNotification;
use App\Services\Waha\WahaClient;
use App\Support\EmailList;
use App\Support\WebPush;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Illuminate\Support\Facades\Schema;
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
        $who = $ticket->senderName();
        $title = "🆕 פנייה חדשה #{$ticket->id} ({$ticket->channel->getLabel()})";
        $body = "מ: {$who}\nנושא: {$ticket->subject}";

        // Include the opening message so the team sees what was written without
        // opening the panel.
        $opening = $ticket->messages()->orderBy('id')->value('body');
        if (filled($opening)) {
            $body .= "\n\nתוכן:\n".Str::limit((string) $opening, 600);
        }

        $this->send($title, $body, $ticket);
        $this->bell($this->ticketUrl($ticket), 'פנייה חדשה', "{$who} · {$ticket->subject}", 'heroicon-o-lifebuoy', 'פתח פנייה');
    }

    /** Human labels for the signup payment methods. */
    private const PAYMENT_METHOD_LABELS = [
        'credit_card' => 'כרטיס אשראי',
        'standing_order' => 'הוראת קבע בנקאית',
        'bank_transfer' => 'העברה בנקאית',
        'checks' => 'צ׳קים',
    ];

    /**
     * A new customer signed up (via the public /join form). Fires on EVERY
     * signup regardless of payment method, so a credit-card signup — which
     * opens no ticket — still reaches the team on WhatsApp, email and the bell.
     */
    public function newCustomer(Customer $customer): void
    {
        $label = self::PAYMENT_METHOD_LABELS[$customer->payment_method] ?? (string) $customer->payment_method;

        $title = "🎉 לקוח חדש נרשם — {$customer->name}";
        $body = "איש קשר: {$customer->contact_name}\n"
            ."טלפון: {$customer->phone}\n"
            ."אימייל: {$customer->email}\n"
            ."אמצעי תשלום: {$label}";

        $url = rtrim((string) config('app.url'), '/')."/admin/customers/{$customer->id}";

        $this->alert($title, $body, $url);
        $this->bell($url, 'לקוח חדש נרשם', "{$customer->name} · {$label}", 'heroicon-o-user-plus', 'פתח לקוח');
    }

    /** A customer replied on an existing ticket. */
    public function newReply(Ticket $ticket, TicketMessage $message): void
    {
        $who = $ticket->senderName();
        $title = "💬 תגובה חדשה בפנייה #{$ticket->id}";
        $body = "מ: {$who}\nנושא: {$ticket->subject}\n\nתוכן:\n".Str::limit($message->body, 600);

        $this->send($title, $body, $ticket);
        $this->bell($this->ticketUrl($ticket), "תגובת לקוח בפנייה #{$ticket->id}", "{$who}: ".Str::limit($message->body, 120), 'heroicon-o-chat-bubble-left-right', 'פתח פנייה');
    }

    /**
     * In-panel bell notification for the whole team, with a click-through to the
     * relevant record. Best-effort: never blocks the WhatsApp/email alert, and
     * no-ops before the notifications table exists (fresh deploy, pre-migrate).
     */
    protected function bell(string $url, string $title, string $body, string $icon, string $actionLabel): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        try {
            $recipients = User::query()->get();

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::make()
                ->title($title)
                ->body($body)
                ->icon($icon)
                ->actions([Action::make('view')->label($actionLabel)->url($url)])
                ->sendToDatabase($recipients);
        } catch (\Throwable $e) {
            Log::warning('TeamNotifier: bell notification failed', ['error' => $e->getMessage()]);
        }

        // Browser push (opt-in) — a real "pops on your desktop" alert, sent only
        // to members who subscribed and only when VAPID keys are configured.
        $this->push($title, $body, $url);
    }

    /**
     * Send a browser (Web Push) alert to team members who subscribed. Best-effort
     * and no-op unless Web Push is configured (VAPID keys) — an install without
     * keys behaves exactly as before.
     */
    protected function push(string $title, string $body, string $url): void
    {
        if (! WebPush::enabled()) {
            return;
        }

        try {
            $subscribers = User::query()->whereHas('pushSubscriptions')->get();

            if ($subscribers->isNotEmpty()) {
                LaravelNotification::send($subscribers, new TeamPushNotification($title, $body, $url));
            }
        } catch (\Throwable $e) {
            Log::warning('TeamNotifier: web push failed', ['error' => $e->getMessage()]);
        }
    }

    private function ticketUrl(Ticket $ticket): string
    {
        return rtrim((string) config('app.url'), '/')."/admin/tickets/{$ticket->id}";
    }

    /** Deliver an alert to both team channels (each independent, best-effort). */
    protected function send(string $title, string $body, Ticket $ticket): void
    {
        $panelUrl = $this->ticketUrl($ticket);
        // Tag the subject and set a Reply-To so a team member can just reply to
        // the alert email and have it reach the customer. The signed agentReplyTag
        // authenticates that reply (IngestEmailMessageJob rejects a reply without
        // it), so a spoofed From alone can't push a message to the customer.
        $this->alert(
            $title.' '.$ticket->emailTag().' '.$ticket->agentReplyTag(),
            $body."\n\n💬 להשיב ללקוח: השיבו ישירות למייל הזה, או בקבוצה — ״ענה {$ticket->id} <טקסט>״.",
            $panelUrl,
            replyTo: (string) config('billing.email.support_address') ?: null,
        );
    }

    /**
     * Generic team alert (not tied to a ticket) — used for SSL expiry,
     * operational warnings, etc. WhatsApp to the approvals number/group AND
     * the team email; each best-effort and independent.
     */
    public function alert(string $title, string $body, ?string $url = null, ?string $replyTo = null): void
    {
        $suffix = $url !== null ? "\n\nלצפייה: {$url}" : '';

        if (($chat = $this->teamChat()) !== null) {
            try {
                $this->waha->sendMessage($chat, "{$title}\n{$body}{$suffix}");
            } catch (\Throwable $e) {
                Log::warning('TeamNotifier: WhatsApp alert failed', ['error' => $e->getMessage()]);
            }
        }

        // The team-email setting may hold several addresses (comma/;-separated).
        $recipients = EmailList::parse(config('billing.notifications.team_email'));

        if ($recipients !== []) {
            try {
                Mail::to($recipients)->send(new NotificationMail($title, $body.$suffix, $replyTo));
            } catch (\Throwable $e) {
                Log::warning('TeamNotifier: email alert failed', ['error' => $e->getMessage()]);
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
