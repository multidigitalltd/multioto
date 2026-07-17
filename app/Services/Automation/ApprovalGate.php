<?php

namespace App\Services\Automation;

use App\Enums\ActionStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Jobs\InvestigateSiteJob;
use App\Jobs\SendTicketReplyJob;
use App\Mail\MonitoringReportMail;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Site;
use App\Services\Agent\SiteActionRunner;
use App\Services\Agent\SystemActionRunner;
use App\Services\Hosting\HostingClient;
use App\Services\Monitoring\MonitoringReport;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The human-approval gate for every customer-facing automated action. The AI /
 * automation PROPOSES; the owner receives the full proposal on WhatsApp and
 * replies "אשר <id>" or "דחה <id>" (a panel screen is the fallback). Only an
 * approved action executes, and every decision is recorded. Approvals expire:
 * a proposal older than MAX_AGE_DAYS is refused rather than executed late.
 */
class ApprovalGate
{
    /** A stale proposal must not execute long after its context has changed. */
    public const MAX_AGE_DAYS = 7;

    public function __construct(private WahaClient $waha) {}

    /**
     * Record a proposed action and notify the owner on WhatsApp. WhatsApp being
     * unavailable never loses the proposal — it stays pending in the panel.
     */
    public function propose(
        string $type,
        string $summary,
        array $payload,
        ?int $customerId = null,
        ?int $ticketId = null,
        string $proposedBy = 'ai',
    ): PendingAction {
        $action = PendingAction::create([
            'type' => $type,
            'status' => ActionStatus::Pending,
            'customer_id' => $customerId,
            'ticket_id' => $ticketId,
            'summary' => $summary,
            'payload' => $payload,
            'proposed_by' => $proposedBy,
        ]);

        $this->notifyOwner($action);

        return $action;
    }

    /**
     * Intercept an owner WhatsApp message if it is an approval command.
     * Returns the reply text to send back to the owner, or null when the
     * message is not an approval command (so normal ticket intake proceeds).
     */
    public function handleOwnerMessage(string $chatId, string $body): ?string
    {
        $ownerChat = $this->ownerChatId();

        if ($ownerChat === null || $chatId !== $ownerChat) {
            return null;
        }

        if (! preg_match('/^\s*(אשר|דחה)\s*#?(\d+)\s*$/u', trim($body), $m)) {
            return null;
        }

        $action = PendingAction::find((int) $m[2]);

        if (! $action) {
            return "לא נמצאה פעולה #{$m[2]}.";
        }

        if ($action->status !== ActionStatus::Pending) {
            return "פעולה #{$action->id} כבר טופלה (סטטוס: {$action->status->getLabel()}).";
        }

        return $m[1] === 'אשר' ? $this->approve($action) : $this->reject($action);
    }

    /** Approve + execute. Returns a human status line (for WhatsApp/panel). */
    public function approve(PendingAction $action): string
    {
        if ($action->created_at->lt(now()->subDays(self::MAX_AGE_DAYS))) {
            $action->update(['status' => ActionStatus::Rejected, 'decided_at' => now(), 'error' => 'פג תוקף — ההצעה ישנה מדי לביצוע.']);

            return "פעולה #{$action->id} פגת תוקף (מעל ".self::MAX_AGE_DAYS.' ימים) ולא בוצעה.';
        }

        // Atomically claim the pending → approved transition, so two concurrent
        // approvals (panel + WhatsApp, a double-click, two operators) can never
        // both execute the same action — a duplicate charge demand or a duplicate
        // customer reply. Only the caller whose UPDATE actually flips the row runs.
        $claimed = PendingAction::whereKey($action->id)
            ->where('status', ActionStatus::Pending)
            ->update(['status' => ActionStatus::Approved, 'decided_at' => now()]);

        if ($claimed === 0) {
            return "פעולה #{$action->id} כבר טופלה.";
        }

        $action->refresh();

        try {
            $this->execute($action);
        } catch (\Throwable $e) {
            $action->update(['status' => ActionStatus::Failed, 'error' => Str::limit($e->getMessage(), 300)]);

            return "פעולה #{$action->id} אושרה אך הביצוע נכשל: ".Str::limit($e->getMessage(), 120);
        }

        $action->update(['status' => ActionStatus::Executed, 'executed_at' => now()]);

        return "פעולה #{$action->id} אושרה ובוצעה ✓";
    }

    /** Reject without executing. */
    public function reject(PendingAction $action): string
    {
        $action->update(['status' => ActionStatus::Rejected, 'decided_at' => now()]);

        return "פעולה #{$action->id} נדחתה. לא בוצע דבר.";
    }

    /**
     * Execute an approved action by type. New automation types (site fixes,
     * content edits…) register here — this is the ONLY place automation
     * touches the outside world, always post-approval.
     */
    protected function execute(PendingAction $action): void
    {
        match ($action->type) {
            'ticket_reply' => $this->executeTicketReply($action),
            'site_fix' => $this->executeSiteFix($action),
            'site_action' => $this->executeSiteAction($action),
            'system_action' => app(SystemActionRunner::class)->run($action),
            'monitoring_report' => $this->executeMonitoringReport($action),
            default => throw new \RuntimeException("סוג פעולה לא מוכר: {$action->type}"),
        };
    }

    /**
     * Run an approved site action, then close the loop: when the action came
     * from an AI investigation (its payload carries the original goal), send
     * the agent back to the site — read-only — to verify the ORIGINAL problem
     * is actually solved. Solved → it reports so; not solved → it proposes the
     * next single step, which again waits for approval. Command → result →
     * approval → … until the fix is confirmed, capped at verify_max_rounds so
     * one stubborn problem can't loop forever.
     */
    protected function executeSiteAction(PendingAction $action): void
    {
        app(SiteActionRunner::class)->run($action);

        $goal = trim((string) data_get($action->payload, 'goal'));
        $round = (int) data_get($action->payload, 'round', 1);
        $maxRounds = (int) config('agent.verify_max_rounds', 3);

        // Only AI-originated fixes loop — a team member picking a tool by hand
        // ("פעולת AI") asked for that one call, not for an investigation.
        if (! config('agent.verify_after_fix', true) || $goal === '' || $action->proposed_by !== 'ai') {
            return;
        }

        if ($round >= $maxRounds) {
            Log::info('ApprovalGate: fix loop reached its round cap; leaving to a human', [
                'action_id' => $action->id, 'round' => $round,
            ]);

            return;
        }

        $tool = (string) data_get($action->payload, 'tool');

        InvestigateSiteJob::dispatch(
            (int) data_get($action->payload, 'site_id'),
            "בוצעה כעת (אחרי אישור מנהל) הפעולה \"{$tool}\" כחלק מטיפול בבעיה: {$goal}\n"
                .'בדוק עכשיו בכלי קריאה בלבד אם הבעיה המקורית נפתרה בפועל. אם נפתרה — כתוב סיכום קצר שמאשר זאת. '
                .'אם לא נפתרה — הצע עם propose_action את הצעד הבא לתיקון.',
            $round + 1,
        );
    }

    /** Send an approved monthly monitoring report to the customer. */
    protected function executeMonitoringReport(PendingAction $action): void
    {
        $customer = Customer::find((int) data_get($action->payload, 'customer_id'));

        if (! $customer || blank($customer->email)) {
            throw new \RuntimeException('הלקוח או כתובת המייל חסרים.');
        }

        // Recompute at send time so the approved report reflects current data.
        $report = app(MonitoringReport::class)->for($customer);

        Mail::to($customer->email)->send(new MonitoringReportMail($customer, $report));
    }

    /** Apply an approved, reversible site fix via the hosting driver. */
    protected function executeSiteFix(PendingAction $action): void
    {
        $site = Site::find((int) data_get($action->payload, 'site_id'));
        $fix = (string) data_get($action->payload, 'fix');

        if (! $site) {
            throw new \RuntimeException('האתר לא נמצא.');
        }

        $hosting = app(HostingClient::class);

        match ($fix) {
            'clear_cache' => $hosting->clearCache($site),
            'restart' => $hosting->restartSite($site),
            'maintenance_on' => $hosting->suspendSite($site),
            'maintenance_off' => $hosting->restoreSite($site),
            default => throw new \RuntimeException("תיקון לא מוכר: {$fix}"),
        };
    }

    /** Send an approved AI reply to the customer over the ticket's channel. */
    protected function executeTicketReply(PendingAction $action): void
    {
        $ticket = $action->ticket;
        $reply = (string) data_get($action->payload, 'reply', '');

        if (! $ticket || $reply === '') {
            throw new \RuntimeException('הפנייה או תוכן התשובה חסרים.');
        }

        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => $ticket->channel === TicketChannel::Whatsapp ? MessageChannel::Whatsapp : MessageChannel::Email,
            'body' => $reply,
            'author' => MessageAuthor::Ai,
        ]);

        SendTicketReplyJob::dispatch($message->id);
    }

    /** The owner's WhatsApp chat id, from settings (null = gate is panel-only). */
    public function ownerChatId(): ?string
    {
        $number = (string) config('billing.waha.owner_number');

        return $number !== '' ? $this->waha->normalizeChatId($number) : null;
    }

    /** WhatsApp the proposal to the owner (best-effort). */
    protected function notifyOwner(PendingAction $action): void
    {
        // The team can silence agent proposals on WhatsApp from the panel — the
        // proposal still waits in the approvals inbox, the group just doesn't get it.
        if (! config('agent.notify_owner_whatsapp', true)) {
            return;
        }

        $ownerChat = $this->ownerChatId();

        if ($ownerChat === null) {
            Log::info('ApprovalGate: owner WhatsApp not configured; action awaits panel approval', ['action_id' => $action->id]);

            return;
        }

        $text = "🔔 פעולה #{$action->id} ממתינה לאישור\n\n"
            .Str::limit($action->summary, 700)."\n\n"
            ."✅ לאישור השיבו: אשר {$action->id}\n"
            ."❌ לדחייה: דחה {$action->id}";

        try {
            $this->waha->sendMessage($ownerChat, $text);
        } catch (\Throwable $e) {
            Log::warning('ApprovalGate: owner notification failed; action awaits panel approval', [
                'action_id' => $action->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
