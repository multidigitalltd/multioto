<?php

namespace App\Services\Automation;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Models\Ticket;
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
    ) {}

    /**
     * Handle a message from the management chat. Returns the reply to send back,
     * or null to stay silent (e.g. plain chatter that isn't a command).
     */
    public function handle(string $chatId, string $body): ?string
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

        // "כרטיס <טלפון> <תיאור>" / "פתח <טלפון> <תיאור>" — open a new ticket.
        if (preg_match('/^\s*(?:כרטיס|פתח)\s+(\+?[0-9\-]{6,})\s+(.+)/us', $text, $m)) {
            return $this->open($m[1], trim($m[2]));
        }

        // Not a recognised command — never open a ticket from the management
        // group; nudge the owner to the command list instead.
        return $this->help();
    }

    /** Open a new ticket for the customer matched by phone (or unidentified). */
    private function open(string $phone, string $description): string
    {
        $normalized = '+'.ltrim(preg_replace('/[^0-9]/', '', $phone), '0');
        $customer = $this->intake->matchCustomer(phone: $phone)
            ?? $this->intake->matchCustomer(phone: $normalized);

        $body = $customer
            ? $description
            : $description."\n(נפתח מקבוצת הניהול עבור טלפון {$phone} — לא זוהה לקוח)";

        $message = $this->intake->recordInbound(
            channel: TicketChannel::Manual,
            messageChannel: MessageChannel::InternalNote,
            customer: $customer,
            body: $body,
            externalMessageId: 'mgmt-'.Str::random(12),
            subject: 'נפתח מקבוצת הניהול'.($customer ? ' — '.$customer->name : ''),
        );

        $who = $customer?->name ?? 'לקוח לא מזוהה';

        return "נפתחה פנייה #{$message->ticket_id} עבור {$who}.";
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
            '• *סגור <מספר>* — סגירת פנייה',
            '• *אשר <מספר>* / *דחה <מספר>* — אישור/דחיית פעולה אוטומטית',
            '• *עזרה* — התפריט הזה',
        ]);
    }
}
