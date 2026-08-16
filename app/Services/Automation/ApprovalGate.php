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
use App\Models\StandingApproval;
use App\Models\SystemLog;
use App\Models\Task;
use App\Services\Agent\ContentChangeRunner;
use App\Services\Agent\IncidentMemory;
use App\Services\Agent\MaintenanceRunner;
use App\Services\Agent\SiteActionRunner;
use App\Services\Agent\SiteToolCatalog;
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

    /**
     * System operations that may NEVER get a standing approval — anything that
     * moves money or changes billing must be looked at every single time.
     */
    private const STANDING_BLOCKED_OPERATIONS = [
        'send_payment_request', 'mark_collected', 'update_subscription',
    ];

    public function __construct(private WahaClient $waha) {}

    /**
     * The standing-approval key for an action of this type+payload, or null
     * when the kind is not eligible for "always approve":
     * - ticket replies — unique customer-facing content, reviewed every time;
     * - destructive site tools (tier 3) — never pre-approved;
     * - money-moving system operations — never pre-approved;
     * - weekly maintenance — plugin updates on customers' live sites. The set
     *   of updates is different every week and is not known when the grant is
     *   given, so "always approve" here would be approving next month's update
     *   too. The owner asked to see each batch and check the sites afterwards,
     *   and a weekly proposal is a small price for that; the batch itself is
     *   ready to run in one click once approved.
     */
    public static function standingKeyFor(string $type, array $payload): ?string
    {
        return match ($type) {
            'site_action' => self::siteActionStandingKey($payload),
            'site_fix' => ($fix = (string) data_get($payload, 'fix')) !== '' ? "site_fix:{$fix}" : null,
            'system_action' => ($op = (string) data_get($payload, 'operation')) !== ''
                && ! in_array($op, self::STANDING_BLOCKED_OPERATIONS, true)
                    ? "system_action:{$op}"
                    : null,
            'monitoring_report' => 'monitoring_report',
            // 'maintenance_update' is deliberately absent — see below.
            default => null,
        };
    }

    /**
     * The EFFECTIVE tier decides eligibility: a benignly-named tool the site's
     * MCP capabilities flag as destructive escalates to tier 3 via
     * resolveTier(), and must not be pre-approvable (nor auto-run) there —
     * name-only classification would let it slip through on staging sites.
     */
    private static function siteActionStandingKey(array $payload): ?string
    {
        $tool = (string) data_get($payload, 'tool');

        if ($tool === '') {
            return null;
        }

        $catalog = app(SiteToolCatalog::class);
        $site = Site::find((int) data_get($payload, 'site_id'));
        $tier = $site !== null ? $catalog->resolveTier($site, $tool) : $catalog->tier($tool);

        return $tier < 3 ? "site_action:{$tool}" : null;
    }

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
        ?int $taskId = null,
    ): PendingAction {
        $action = PendingAction::create([
            'type' => $type,
            'status' => ActionStatus::Pending,
            'customer_id' => $customerId,
            'ticket_id' => $ticketId,
            // A delegated task waiting on this decision: it stays claimed until
            // the decision is made, and is handed back here when it is.
            'task_id' => $taskId,
            'summary' => $summary,
            'payload' => $payload,
            'proposed_by' => $proposedBy,
        ]);

        // A standing ("always approve") grant for this exact action kind runs
        // it immediately — the owner is INFORMED it ran instead of being asked.
        $standing = StandingApproval::enabledFor(self::standingKeyFor($type, $payload));

        if ($standing !== null) {
            $action->update(['standing_approval_id' => $standing->id]);
            $standing->markUsed();

            $result = $this->approve($action->refresh());

            SystemLog::record('info', 'automation',
                "פעולה #{$action->id} בוצעה אוטומטית לפי אישור קבוע \"{$standing->label}\": {$result}",
                ['action_id' => $action->id, 'standing_approval_id' => $standing->id]);
            $this->notifyOwnerAutoRun($action, $standing, $result);

            return $action->refresh();
        }

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

        if (! preg_match('/^\s*(אשר תמיד|אשר|דחה)\s*#?(\d+)\s*$/u', trim($body), $m)) {
            return null;
        }

        $action = PendingAction::find((int) $m[2]);

        if (! $action) {
            return "לא נמצאה פעולה #{$m[2]}.";
        }

        if ($action->status !== ActionStatus::Pending) {
            return "פעולה #{$action->id} כבר טופלה (סטטוס: {$action->status->getLabel()}).";
        }

        return match ($m[1]) {
            'אשר תמיד' => $this->approveAlways($action),
            'אשר' => $this->approve($action),
            default => $this->reject($action),
        };
    }

    /**
     * Approve this action AND record a standing approval, so future proposals
     * of the same kind execute automatically (owner notified, not asked).
     */
    public function approveAlways(PendingAction $action): string
    {
        $key = self::standingKeyFor($action->type, (array) $action->payload);

        if ($key === null) {
            // The reason matters: "you cannot" without "because" reads as a
            // limitation to work around rather than a decision that was made.
            $why = $action->type === 'maintenance_update'
                ? 'עדכוני תוספים באתרים חיים נבדקים בכל פעם — רשימת העדכונים שונה בכל שבוע, ואישור קבוע היה מאשר גם את זו של החודש הבא'
                : 'תוכן ללקוח / פעולה הרסנית / כספים';

            return "לפעולה מסוג זה אי אפשר לקבוע אישור קבוע ({$why}). אפשר לאשר חד-פעמית: אשר {$action->id}";
        }

        // Claim (and run) the concrete action FIRST — if another request
        // already approved/rejected it, no permanent grant may be installed on
        // the back of this lost race.
        ['claimed' => $claimed, 'message' => $message] = $this->approveInternal($action);

        if (! $claimed) {
            return $message;
        }

        $standing = StandingApproval::updateOrCreate(
            ['action_key' => $key],
            [
                'label' => self::standingLabelFor($key),
                'enabled' => true,
                'created_from_action_id' => $action->id,
            ],
        );

        SystemLog::record('info', 'automation',
            "נקבע אישור קבוע \"{$standing->label}\" — פעולות מסוג זה יבוצעו מעכשיו אוטומטית (ניתן לבטל בהגדרות ← אישורים קבועים).",
            ['standing_approval_id' => $standing->id, 'action_id' => $action->id]);

        return $message."\n🔁 נקבע אישור קבוע: \"{$standing->label}\". פעולות כאלה יבוצעו מעכשיו אוטומטית ותקבל דיווח. לביטול: הגדרות ← אישורים קבועים.";
    }

    /** A human label for a standing-approval key ("site_action:wp_cache_flush"). */
    private static function standingLabelFor(string $key): string
    {
        [$type, $detail] = array_pad(explode(':', $key, 2), 2, '');

        return match ($type) {
            'site_action' => "פעולת אתר: {$detail}",
            'site_fix' => 'תיקון אתר: '.match ($detail) {
                'clear_cache' => 'ניקוי מטמון',
                'restart' => 'הפעלה מחדש',
                'maintenance_on' => 'מצב תחזוקה — הפעלה',
                'maintenance_off' => 'מצב תחזוקה — כיבוי',
                default => $detail,
            },
            'system_action' => "פעולת מערכת: {$detail}",
            'monitoring_report' => 'שליחת דוח ניטור חודשי ללקוח',
            'maintenance_update' => 'תחזוקה שבועית — עדכוני תוספים',
            default => $key,
        };
    }

    /** Approve + execute. Returns a human status line (for WhatsApp/panel). */
    public function approve(PendingAction $action): string
    {
        return $this->approveInternal($action)['message'];
    }

    /**
     * Approve + execute, also reporting whether THIS caller won the atomic
     * pending→approved claim — approveAlways() must not install a permanent
     * grant on the back of a request that lost the race (the action may have
     * been rejected by someone else a moment earlier).
     *
     * @return array{claimed: bool, message: string}
     */
    protected function approveInternal(PendingAction $action): array
    {
        try {
            return $this->decide($action);
        } finally {
            // Every way out of decide() ends with this action no longer
            // pending — expired, lost the race, executed or failed — and a task
            // that was waiting on the decision must not stay claimed for one
            // that has been made.
            Task::releaseIfIdle($action->task_id);
        }
    }

    /** @return array{claimed: bool, message: string} */
    private function decide(PendingAction $action): array
    {
        if ($action->created_at->lt(now()->subDays(self::MAX_AGE_DAYS))) {
            $action->update(['status' => ActionStatus::Rejected, 'decided_at' => now(), 'error' => 'פג תוקף — ההצעה ישנה מדי לביצוע.']);

            return ['claimed' => false, 'message' => "פעולה #{$action->id} פגת תוקף (מעל ".self::MAX_AGE_DAYS.' ימים) ולא בוצעה.'];
        }

        // Atomically claim the pending → approved transition, so two concurrent
        // approvals (panel + WhatsApp, a double-click, two operators) can never
        // both execute the same action — a duplicate charge demand or a duplicate
        // customer reply. Only the caller whose UPDATE actually flips the row runs.
        $claimed = PendingAction::whereKey($action->id)
            ->where('status', ActionStatus::Pending)
            ->update(['status' => ActionStatus::Approved, 'decided_at' => now()]);

        if ($claimed === 0) {
            return ['claimed' => false, 'message' => "פעולה #{$action->id} כבר טופלה."];
        }

        $action->refresh();

        try {
            $this->execute($action);
        } catch (\Throwable $e) {
            $action->update(['status' => ActionStatus::Failed, 'error' => Str::limit($e->getMessage(), 300)]);

            return ['claimed' => true, 'message' => "פעולה #{$action->id} אושרה אך הביצוע נכשל: ".Str::limit($e->getMessage(), 120)];
        }

        $action->update(['status' => ActionStatus::Executed, 'executed_at' => now()]);

        return ['claimed' => true, 'message' => "פעולה #{$action->id} אושרה ובוצעה ✓"];
    }

    /** Reject without executing. */
    public function reject(PendingAction $action): string
    {
        // Claimed the same way an approval is: a rejection arriving while
        // another request is already executing the action must not overwrite
        // "approved" with "rejected" — the external call is under way, and the
        // false status would then read as a settled decision and hand a waiting
        // task back mid-execution.
        $claimed = PendingAction::whereKey($action->id)
            ->where('status', ActionStatus::Pending)
            ->update(['status' => ActionStatus::Rejected, 'decided_at' => now()]);

        if ($claimed === 0) {
            return "פעולה #{$action->id} כבר טופלה.";
        }

        // Nothing is pending on the task any more — a rejected fix is still a
        // decision, and the work goes back to a person.
        Task::releaseIfIdle($action->task_id);

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
            'maintenance_update' => app(MaintenanceRunner::class)->run($action),
            'monitoring_report' => $this->executeMonitoringReport($action),
            'content_change' => app(ContentChangeRunner::class)->run($action),
            default => throw new \RuntimeException("סוג פעולה לא מוכר: {$action->type}"),
        };
    }

    /**
     * Run an approved site action, then close the loop: when the action came
     * from an AI investigation (its payload carries the original goal), send
     * the agent back to the site — read-only — to verify the ORIGINAL problem
     * is actually solved. Solved → it reports so; not solved → it proposes the
     * next single step, which again waits for approval. Command → result →
     * approval → … until the fix is confirmed. The loop is unlimited by
     * default — every round is human-gated, so rejecting a proposal is the
     * brake — with verify_max_rounds as an optional cap (0 = no cap).
     */
    protected function executeSiteAction(PendingAction $action): void
    {
        app(SiteActionRunner::class)->run($action);

        $goal = trim((string) data_get($action->payload, 'goal'));
        $round = (int) data_get($action->payload, 'round', 1);
        $maxRounds = (int) config('agent.verify_max_rounds', 0);

        // Remember the treatment in the incident memory — the verification
        // round (below) upgrades it to "verified" when the problem is gone.
        // Best-effort: the external action already ran; a memory-write failure
        // must not flip an EXECUTED change to Failed (inviting a re-run).
        $resolutionId = null;

        try {
            if ($goal !== '' && $action->proposed_by === 'ai'
                && ($memorySite = Site::find((int) data_get($action->payload, 'site_id'))) !== null) {
                $resolutionId = app(IncidentMemory::class)->record(
                    $memorySite,
                    $goal,
                    (string) data_get($action->payload, 'tool'),
                    Str::limit($action->summary, 400),
                    $action->id,
                )->id;
            }
        } catch (\Throwable $e) {
            Log::warning('ApprovalGate: incident-memory recording failed after an executed fix', [
                'action_id' => $action->id, 'error' => $e->getMessage(),
            ]);
        }

        // Only AI-originated fixes loop — a team member picking a tool by hand
        // ("פעולת AI") asked for that one call, not for an investigation.
        if (! config('agent.verify_after_fix', true) || $goal === '' || $action->proposed_by !== 'ai') {
            return;
        }

        if ($maxRounds > 0 && $round >= $maxRounds) {
            Log::info('ApprovalGate: fix loop reached its round cap; leaving to a human', [
                'action_id' => $action->id, 'round' => $round,
            ]);

            return;
        }

        $tool = (string) data_get($action->payload, 'tool');

        // A delegated task waiting on this fix is not free the moment the fix
        // runs: the verification round is the rest of the same work. It is held
        // for that job BEFORE the dispatch, so the decision's own release —
        // which happens as soon as this returns — cannot hand the task back
        // mid-verification, where it could be delegated all over again.
        $token = (string) Str::uuid();
        Task::hold($action->task_id, $token);

        try {
            InvestigateSiteJob::dispatch(
                (int) data_get($action->payload, 'site_id'),
                "בוצעה כעת (אחרי אישור מנהל) הפעולה \"{$tool}\" כחלק מטיפול בבעיה: {$goal}\n"
                    .'בדוק עכשיו בכלי קריאה בלבד אם הבעיה המקורית נפתרה בפועל. אם נפתרה — כתוב סיכום קצר שמאשר זאת. '
                    .'אם לא נפתרה — הצע עם propose_action את הצעד הבא לתיקון.',
                $round + 1,
                null,
                $resolutionId,
                // The verification carries the task too, so a next-step proposal
                // it files is linked the same way this one was.
                releasesTaskId: $action->task_id,
                holdToken: $token,
            );
        } catch (\Throwable $e) {
            // Nothing will run, so nothing may keep holding the task.
            Task::dropHold($action->task_id, $token);

            // The fix itself already ran and succeeded — a failure to enqueue
            // the FOLLOW-UP must not bubble up and mark the executed action as
            // failed (a false audit trail that invites re-running a
            // non-idempotent change). Log it and move on.
            Log::warning('ApprovalGate: verification dispatch failed after an executed fix', [
                'action_id' => $action->id, 'error' => $e->getMessage(),
            ]);
        }
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

        // Hosting-level fixes join the incident memory too (unverified — there
        // is no automatic verification round for them). Best-effort: the fix
        // already ran; a memory failure must not mark it Failed.
        try {
            app(IncidentMemory::class)->record($site, $action->summary, "hosting:{$fix}", null, $action->id);
        } catch (\Throwable $e) {
            Log::warning('ApprovalGate: incident-memory recording failed after an executed hosting fix', [
                'action_id' => $action->id, 'error' => $e->getMessage(),
            ]);
        }
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

        // Offer the standing option only where the kind is eligible for one.
        if (self::standingKeyFor($action->type, (array) $action->payload) !== null) {
            $text .= "\n🔁 לאישור אוטומטי של פעולות כאלה מעכשיו: אשר תמיד {$action->id}";
        }

        try {
            $this->waha->sendMessage($ownerChat, $text);
        } catch (\Throwable $e) {
            Log::warning('ApprovalGate: owner notification failed; action awaits panel approval', [
                'action_id' => $action->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Tell the owner an action already ran under a standing approval. */
    protected function notifyOwnerAutoRun(PendingAction $action, StandingApproval $standing, string $result): void
    {
        if (! config('agent.notify_owner_whatsapp', true)) {
            return;
        }

        $ownerChat = $this->ownerChatId();

        if ($ownerChat === null) {
            return;
        }

        try {
            $this->waha->sendMessage($ownerChat,
                "🤖 בוצע אוטומטית (אישור קבוע: {$standing->label})\n\n"
                .Str::limit($action->summary, 500)."\n\n"
                ."תוצאה: {$result}\nלביטול האישור הקבוע: הגדרות ← אישורים קבועים.");
        } catch (\Throwable $e) {
            Log::warning('ApprovalGate: auto-run notification failed', [
                'action_id' => $action->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
