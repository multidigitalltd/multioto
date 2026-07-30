<?php

namespace App\Services\Automation;

use App\Enums\MessageChannel;
use App\Enums\TaskStatus;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Jobs\NotifyTaskCreatedJob;
use App\Jobs\RunAgentInstructionJob;
use App\Models\Task;
use App\Models\Ticket;
use App\Services\Support\AgentReply;
use App\Services\Support\TicketIntake;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Full operations from the WhatsApp management group. The owner runs the team
 * from that one group: approving automated actions (delegated to ApprovalGate),
 * opening / listing / closing customer tickets, keeping the team's own task list
 * ("משימה מחר להתקשר לדני"), and handing work to the AI agent — either a free
 * instruction or an existing task ("סוכן משימה 7").
 *
 * Tickets and tasks are deliberately separate command words: a ticket is a
 * customer conversation, a task is our own to-do, and one turning into the other
 * by accident would be worse than either.
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

        if (preg_match('/^\s*(משימות|מטלות)\s*$/u', $text)) {
            return $this->listTasks();
        }

        if (preg_match('/^\s*סגור\s+#?(\d+)\s*$/u', $text, $m)) {
            return $this->close((int) $m[1]);
        }

        // "בוצע 7" — a TASK is done. Tickets close with "סגור", so the two
        // numbering spaces can never be confused for one another.
        if (preg_match('/^\s*(?:בוצע|בוצעה|סיימתי)\s+#?(\d+)\s*$/u', $text, $m)) {
            return $this->completeTask((int) $m[1]);
        }

        // "סוכן משימה 7" — hand an existing task to the AI agent. Checked before
        // the free-text form so the task number is not read as an instruction.
        if (preg_match('/^\s*סוכן\s+משימה\s+#?(\d+)\s*$/u', $text, $m)) {
            return $this->delegateTask($chatId, (int) $m[1]);
        }

        // "סוכן <הוראה>" — free instruction to the AI agent (the command console
        // over WhatsApp). It investigates on its own and proposes for approval.
        if (preg_match('/^\s*סוכן\s+(.+)/us', $text, $m)) {
            return $this->askAgent($chatId, trim($m[1]));
        }

        // "משימה <תיאור>" — open an internal task, optionally dated
        // ("משימה מחר להתקשר לדני"). Matched before the ticket commands below
        // because it takes free text rather than a phone number.
        if (preg_match('/^\s*(?:משימה|מטלה|todo)\s+(.+)/ius', $text, $m)) {
            return $this->openTask(trim($m[1]), $messageId);
        }

        // "ענה #12 <טקסט>" / "תשובה 12 <טקסט>" — reply to the ticket's customer.
        if (preg_match('/^\s*(?:ענה|תשובה|השב)\s+#?(\d+)\s+(.+)/us', $text, $m)) {
            return $this->replyToTicket((int) $m[1], trim($m[2]));
        }

        // "פנה <טלפון> <טקסט>" — proactively message a customer (opens a ticket
        // AND sends). Checked before "כרטיס", which only opens an internal ticket.
        if (preg_match('/^\s*פנה\s+(\+?[0-9\-]{6,})\s+(.+)/us', $text, $m)) {
            return $this->contactCustomer($m[1], trim($m[2]));
        }

        // "כרטיס <טלפון> <תיאור>" / "פתח <טלפון> <תיאור>" — open a new ticket.
        if (preg_match('/^\s*(?:כרטיס|פתח)\s+(\+?[0-9\-]{6,})\s+(.+)/us', $text, $m)) {
            return $this->open($m[1], trim($m[2]), $messageId);
        }

        // Not a recognised command — never open a ticket from the management
        // group; nudge the owner to the command list instead.
        return $this->help();
    }

    /*
    | ----------------------------------------------------------------
    | Tasks — the team's own to-do list, not customer tickets
    | ----------------------------------------------------------------
    */

    /**
     * Open an internal task from the group. An optional date word opens the
     * description ("משימה מחר להתקשר לדני") — a thought worth capturing usually
     * arrives with its deadline attached, and typing it later never happens.
     */
    private function openTask(string $text, ?string $messageId = null): string
    {
        [$dueAt, $title] = $this->splitDue($text);

        if ($title === '') {
            return 'צריך תיאור למשימה. לדוגמה: *משימה מחר להתקשר לדני*';
        }

        // Keyed on the WhatsApp message so a retry of the ingestion job cannot
        // turn one sentence said once into two identical tasks — the same
        // idempotency the ticket-opening path uses. Falls back to a random key
        // only when no message id is available.
        $ref = 'mgmt-task-'.($messageId ?? Str::random(12));

        $task = Task::firstOrCreate(['source_ref' => $ref], [
            'title' => Str::limit($title, 120, ''),
            // Nothing is lost when the title is trimmed for the list view.
            'description' => Str::length($title) > 120 ? $title : null,
            'status' => TaskStatus::Open,
            'priority' => TicketPriority::Normal,
            'due_at' => $dueAt,
        ]);

        // Unassigned, so this reaches the managers who are not in the group —
        // the same path every other "new task" entry point uses. Deliberately
        // non-fatal: the task is already saved and the group is waiting to hear
        // its number, so letting a queue hiccup abort the command would leave a
        // captured task looking unhandled, and the retyped command would open a
        // second one under a new message id.
        if ($task->wasRecentlyCreated) {
            try {
                NotifyTaskCreatedJob::dispatch($task->id);
            } catch (\Throwable $e) {
                Log::warning('ManagementCommands: task notification not queued', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $when = $dueAt !== null ? ' · עד '.$dueAt->format('d/m/Y') : '';

        return "נפתחה משימה #{$task->id}{$when} ✓\nלהעביר לסוכן: *סוכן משימה {$task->id}* · לסימון כבוצעה: *בוצע {$task->id}*";
    }

    /** The open tasks, soonest deadline first (undated last). */
    private function listTasks(): string
    {
        $tasks = Task::query()->open()
            ->with('assignees')
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->limit(15)
            ->get();

        if ($tasks->isEmpty()) {
            return 'אין משימות פתוחות 🎉';
        }

        $lines = $tasks->map(function (Task $t): string {
            $due = $t->due_at !== null ? ' · עד '.$t->due_at->format('d/m') : '';
            $who = $t->assignees->pluck('name')->implode(', ');

            return sprintf(
                '#%d %s%s%s',
                $t->id,
                Str::limit($t->title, 50),
                $due,
                $who !== '' ? ' · '.$who : '',
            );
        });

        return "משימות פתוחות ({$tasks->count()}):\n".$lines->implode("\n");
    }

    /** Mark a task done by id. */
    private function completeTask(int $taskId): string
    {
        $task = Task::find($taskId);

        if (! $task) {
            return "לא נמצאה משימה #{$taskId}.";
        }

        if ($task->status === TaskStatus::Done) {
            return "משימה #{$taskId} כבר מסומנת כבוצעה.";
        }

        $task->markStatus(TaskStatus::Done);

        return "משימה #{$taskId} סומנה כבוצעה ✓";
    }

    /*
    | ----------------------------------------------------------------
    | The AI agent
    | ----------------------------------------------------------------
    */

    /**
     * Give the AI agent a free instruction from the group. It runs on the queue
     * (several AI turns, far too slow for the webhook) and answers back here;
     * whatever it wants to actually DO still comes back as a proposal to approve.
     */
    private function askAgent(string $chatId, string $instruction): string
    {
        if ($instruction === '') {
            return 'מה למסור לסוכן? לדוגמה: *סוכן בדוק למי יש חוב פתוח מעל חודש*';
        }

        RunAgentInstructionJob::dispatch($chatId, $instruction);

        return '🤖 מסרתי לסוכן — אענה כאן כשיסיים.';
    }

    /**
     * Hand an existing task to the agent: the task itself becomes the
     * instruction, and it is marked as being worked on so nobody picks it up in
     * parallel. It is NOT auto-completed — the agent proposes, a person decides,
     * and the task closes only when someone says so.
     */
    private function delegateTask(string $chatId, int $taskId): string
    {
        $task = Task::with('customer')->find($taskId);

        if (! $task) {
            return "לא נמצאה משימה #{$taskId}.";
        }

        if ($task->status === TaskStatus::Done) {
            return "משימה #{$taskId} כבר בוצעה.";
        }

        $instruction = trim($task->title."\n".(string) $task->description);

        if ($task->customer !== null) {
            $instruction .= "\n(המשימה משויכת ללקוח: {$task->customer->name})";
        }

        // Claim it conditionally: sending the command twice, or two managers
        // sending it at once, would otherwise set the status a second time and
        // dispatch a second agent — duplicate AI work and duplicate proposals
        // for one task.
        $claimed = Task::whereKey($taskId)
            ->where('status', TaskStatus::Open)
            ->update(['status' => TaskStatus::InProgress, 'reminded_at' => null]);

        if ($claimed !== 1) {
            return "משימה #{$taskId} כבר בטיפול — לא הועברה שוב לסוכן.";
        }

        try {
            RunAgentInstructionJob::dispatch($chatId, $instruction, $taskId);
        } catch (\Throwable $e) {
            // No job exists to run failed() for us, so hand the task back
            // before the failure surfaces — otherwise it stays claimed by an
            // agent that never started.
            $this->releaseTask($taskId);

            throw $e;
        }

        return "🤖 משימה #{$taskId} הועברה לסוכן — אענה כאן כשיסיים.";
    }

    /**
     * Hand a claimed task back to the humans. Only from "in progress": a status
     * a person set since is newer than ours and must stand.
     */
    private function releaseTask(int $taskId): void
    {
        Task::whereKey($taskId)
            ->where('status', TaskStatus::InProgress)
            ->update(['status' => TaskStatus::Open, 'reminded_at' => null]);
    }

    /**
     * A leading date word → [due date, the rest of the text]. Only the forms a
     * person actually types in a hurry; anything else is left as plain text so a
     * description that merely starts with a number is never eaten as a date.
     *
     * @return array{0: ?Carbon, 1: string}
     */
    private function splitDue(string $text): array
    {
        $text = trim($text);

        if (preg_match('/^(היום|מחר|מחרתיים)\s+(.+)/us', $text, $m)) {
            $due = match ($m[1]) {
                'היום' => now()->endOfDay(),
                'מחר' => now()->addDay()->endOfDay(),
                default => now()->addDays(2)->endOfDay(),
            };

            return [$due, trim($m[2])];
        }

        // "15/8" or "15/8/2026" — day/month, the Israeli written order.
        if (preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\s+(.+)/us', $text, $m)) {
            // A group before the last one is filled with '' when it does not
            // participate, so an omitted year must be tested for content.
            $year = filled($m[3] ?? null) ? (int) $m[3] : (int) now()->year;
            $year = $year < 100 ? 2000 + $year : $year;

            if (checkdate((int) $m[2], (int) $m[1], $year)) {
                $due = Carbon::create($year, (int) $m[2], (int) $m[1])->endOfDay();

                return [$due, trim($m[4])];
            }
        }

        return [null, $text];
    }

    /**
     * Proactively reach out to a customer by phone: open a WhatsApp ticket AND
     * send the message in one step (matches the "פנה ללקוח" panel action). The
     * customer's reply threads back onto this ticket.
     */
    private function contactCustomer(string $phone, string $body): string
    {
        $digits = $this->internationalDigits($phone);

        if ($digits === '') {
            return 'מספר טלפון לא תקין.';
        }

        $e164 = '+'.$digits;
        $customer = $this->intake->matchCustomer(phone: $phone)
            ?? $this->intake->matchCustomer(phone: $e164);

        $ticket = Ticket::create([
            'customer_id' => $customer?->id,
            'contact_handle' => $customer ? null : $e164,
            'channel' => TicketChannel::Whatsapp,
            'subject' => 'פנייה יזומה מהצוות'.($customer ? ' — '.$customer->name : ''),
            'status' => TicketStatus::Open,
            // The customer's WhatsApp chat — delivery target AND the thread the
            // customer's reply lands on.
            'external_thread_ref' => $digits.'@c.us',
        ]);

        $this->reply->send($ticket, $body);

        $who = $customer?->name ?? $e164;

        return "ההודעה נשלחה ל{$who} ✓ (פנייה #{$ticket->id})";
    }

    /** Open a new ticket for the customer matched by phone (or unidentified). */
    private function open(string $phone, string $description, ?string $messageId = null): string
    {
        // Match on both the raw input and its E.164 form: a local "0501234567"
        // becomes "+972501234567" (the way customers are stored), not "+501234567".
        $digits = $this->internationalDigits($phone);
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

        // The team opening a ticket by command is never an opt-out request, so
        // this is defensive only — never report a ticket number we don't have.
        if ($message === null) {
            return "לא נפתחה פנייה עבור {$who}.";
        }

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

    /**
     * A phone in any local format → bare international digits ("0501234567" →
     * "972501234567"), the form WhatsApp JIDs and E.164 matching are built from.
     */
    private function internationalDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = (string) config('billing.waha.default_country_code', '972').substr($digits, 1);
        }

        return $digits;
    }

    private function help(): string
    {
        return implode("\n", [
            'פקודות ניהול זמינות בקבוצה:',
            '',
            '*פניות (לקוחות)*',
            '• *פתוחות* — רשימת הפניות הפתוחות',
            '• *פנה <טלפון> <טקסט>* — פנייה יזומה ללקוח (פותח פנייה ושולח)',
            '• *כרטיס <טלפון> <תיאור>* — פתיחת פנייה חדשה',
            '• *ענה <מספר> <טקסט>* — שליחת תשובה ללקוח של הפנייה',
            '• *סגור <מספר>* — סגירת פנייה',
            '',
            '*משימות (שלנו)*',
            '• *משימה <תיאור>* — פתיחת משימה. אפשר להתחיל בתאריך: *משימה מחר להתקשר לדני* או *משימה 15/8 לחדש דומיין*',
            '• *משימות* — רשימת המשימות הפתוחות',
            '• *בוצע <מספר>* — סימון משימה כבוצעה',
            '',
            '*סוכן AI*',
            '• *סוכן <הוראה>* — הוראה חופשית לסוכן (הוא בודק לבד ומגיש פעולות לאישור)',
            '• *סוכן משימה <מספר>* — העברת משימה קיימת לסוכן',
            '',
            '• *אשר <מספר>* / *דחה <מספר>* — אישור/דחיית פעולה אוטומטית',
            '• *עזרה* — התפריט הזה',
        ]);
    }
}
