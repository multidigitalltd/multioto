<?php

namespace App\Services\Automation;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Support\AgentReply;
use App\Services\Support\TicketIntake;
use Illuminate\Support\Str;

/**
 * Full ticket management from the WhatsApp management group. The owner runs the
 * team's operations from that one group: approving automated actions (delegated
 * to ApprovalGate) plus opening, listing and closing tickets by text command.
 *
 * Only messages from the configured management chat ever reach here (the caller
 * gates on ApprovalGate::ownerChatId), and this chat NEVER opens a customer
 * ticket from ordinary chatter — an unrecognised command is answered with help.
 */
class ManagementCommands
{
    public function __construct(
        private ApprovalGate $gate,
        private TicketIntake $intake,
        private AgentReply $reply,
    ) {}

    /**
     * Handle a message from the management chat. Returns the reply to send back,
     * or null to stay silent (e.g. plain chatter that isn't a command).
     */
    public function handle(string $chatId, string $body, ?string $messageId = null): ?string
    {
        // Approvals first — "אשר 12" / "דחה 12" keep working from the group.
        if (($reply = $this->gate->handleOwnerMessage($chatId, $body)) !== null) {
            return $reply;
        }

        $text = trim($body);

        if (preg_match('/^\s*(עזרה|help|\?|תפריט)\s*$/u', $text)) {
            return $this->help();
        }

        if (preg_match('/^\s*(פתוחות|פתוחים|רשימה|פניות)\s*$/u', $text)) {
            return $this->listOpen();
        }

        if (preg_match('/^\s*סגור\s+#?(\d+)\s*$/u', $text, $m)) {
            return $this->close((int) $m[1]);
        }

        // "ענה #12 <טקסט>" / "תשובה 12 <טקסט>" — reply to the ticket's customer.
        if (preg_match('/^\s*(?:ענה|תשובה|השב)\s+#?(\d+)\s+(.+)/us', $text, $m)) {
            return $this->replyToTicket((int) $m[1], trim($m[2]));
        }

        // "כרטיס <טלפון> <תיאור>" / "פתח <טלפון> <תיאור>" — open a new ticket.
        if (preg_match('/^\s*(?:כרטיס|פתח)\s+(\+?[0-9\-]{6,})\s+(.+)/us', $text, $m)) {
            return $this->open($m[1], trim($m[2]), $messageId);
        }

        // Not a recognised command — never open a ticket from the management
        // group; nudge the owner to the command list instead.
        return $this->help();
    }

    /** Open a new ticket for the customer matched by phone (or unidentified). */
    private function open(string $phone, string $description, ?string $messageId = null): string
    {
        // Match on both the raw input and its E.164 form: a local "0501234567"
        // becomes "+972501234567" (the way customers are stored), not "+501234567".
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = (string) config('billing.waha.default_country_code', '972').substr($digits, 1);
        }
        $e164 = '+'.$digits;

        $customer = $this->intake->matchCustomer(phone: $phone)
            ?? $this->intake->matchCustomer(phone: $e164);

        $body = $customer
            ? $description
            : $description."\n(נפתח מקבוצת הניהול עבור טלפון {$phone} — לא זוהה לקוח)";

        // Deterministic id + thread ref keyed on the WAHA message so a job retry
        // re-uses the same ticket instead of opening a duplicate (matching normal
        // WhatsApp ingestion idempotency). Falls back to a random key only when
        // no message id is available.
        $ref = 'mgmt-'.($messageId ?? Str::random(12));

        $message = $this->intake->recordInbound(
            channel: TicketChannel::Manual,
            messageChannel: MessageChannel::InternalNote,
            customer: $customer,
            body: $body,
            threadRef: $ref,
            externalMessageId: $ref,
            subject: 'נפתח מקבוצת הניהול'.($customer ? ' — '.$customer->name : ''),
        );

        $who = $customer?->name ?? 'לקוח לא מזוהה';

        return "נפתחה פנייה #{$message->ticket_id} עבור {$who}.";
    }

    /** Send a free-form reply to a ticket's customer from the management group. */
    private function replyToTicket(int $ticketId, string $body): string
    {
        $ticket = Ticket::with('customer')->find($ticketId);

        if (! $ticket) {
            return "לא נמצאה פנייה #{$ticketId}.";
        }

        if ($ticket->status === TicketStatus::Closed) {
            return "פנייה #{$ticketId} סגורה — פִּתחו אותה לפני מענה.";
        }

        if (! $this->reply->canReach($ticket)) {
            return "לפנייה #{$ticketId} אין כתובת ליצירת קשר — לא ניתן לשלוח.";
        }

        $this->reply->send($ticket, $body);

        $who = $ticket->customer?->name ?? $ticket->senderName();

        return "התשובה נשלחה ל{$who} בפנייה #{$ticketId} ✓";
    }

    /** Close a ticket by id. */
    private function close(int $ticketId): string
    {
        $ticket = Ticket::find($ticketId);

        if (! $ticket) {
            return "לא נמצאה פנייה #{$ticketId}.";
        }

        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            return "פנייה #{$ticketId} כבר סגורה.";
        }

        $ticket->update(['status' => TicketStatus::Closed]);

        return "פנייה #{$ticketId} נסגרה ✓";
    }

    /** List the currently open tickets. */
    private function listOpen(): string
    {
        $open = Ticket::query()
            ->whereIn('status', [TicketStatus::Open, TicketStatus::Pending, TicketStatus::OnHold])
            ->with('customer')
            ->latest('updated_at')
            ->limit(15)
            ->get();

        if ($open->isEmpty()) {
            return 'אין פניות פתוחות 🎉';
        }

        $lines = $open->map(fn (Ticket $t): string => sprintf(
            '#%d %s — %s',
            $t->id,
            $t->customer?->name ?? 'לא מזוהה',
            Str::limit((string) $t->subject, 40),
        ));

        return "פניות פתוחות ({$open->count()}):\n".$lines->implode("\n");
    }

    private function help(): string
    {
        return implode("\n", [
            'פקודות ניהול זמינות בקבוצה:',
            '• *פתוחות* — רשימת הפניות הפתוחות',
            '• *כרטיס <טלפון> <תיאור>* — פתיחת פנייה חדשה',
            '• *ענה <מספר> <טקסט>* — שליחת תשובה ללקוח של הפנייה',
            '• *סגור <מספר>* — סגירת פנייה',
            '• *אשר <מספר>* / *דחה <מספר>* — אישור/דחיית פעולה אוטומטית',
            '• *עזרה* — התפריט הזה',
        ]);
    }
}
