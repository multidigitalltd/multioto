<?php

namespace App\Jobs;

use App\Enums\AgentCommandOutcome;
use App\Models\AgentCommand;
use App\Models\Site;
use App\Models\SystemLog;
use App\Services\Agent\IncidentMemory;
use App\Services\Agent\SiteAgent;
use App\Services\Agent\SiteMemoryStore;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs the AI site operator in the background (heavy: several MCP + model
 * calls). The AI investigates read-only and files any fix as a manager-approval
 * proposal; this job records its written summary so the team can read what it
 * found, without anything being changed on the site.
 *
 * $round > 1 marks a verification pass in the fix loop (an approved fix was
 * just executed and the agent is re-checking the original problem); the
 * summary of such a pass is also pushed to the owner's WhatsApp, so the loop's
 * outcome — "solved" or a next-step proposal — is never silent.
 */
class InvestigateSiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    /**
     * Deliberately NOT constructor-promoted. A promoted property's default is a
     * parameter default, applied only when the constructor runs — so a job
     * already serialized in the queue before this field was added would
     * unserialize WITHOUT it, and the first access (in postToChat) would throw
     * "typed property must not be accessed before initialization" and fail the
     * job after the investigation already ran (tries = 1, no retry). A
     * class-level default IS restored on unserialize, so a legacy payload
     * safely reads null → "not from chat" → posts nowhere.
     */
    public ?int $chatUserId = null;

    /**
     * When set, this run is the verification round of that recorded incident
     * resolution — a "✅ הבעיה נפתרה" summary marks it verified in the
     * agent's incident memory. Class-level default for queue-payload BC.
     */
    public ?int $verifiesResolutionId = null;

    /**
     * Which console asked (AgentCommand::SOURCE_*). The WhatsApp management
     * group has no user id, so without this a group-requested investigation
     * would report its findings nowhere and the group would wait forever for
     * the answer it was promised. Class-level default for queue-payload BC, for
     * the same reason as $chatUserId above.
     */
    public string $chatSource = AgentCommand::SOURCE_PANEL;

    public function __construct(
        public int $siteId,
        public string $goal,
        public int $round = 1,
        ?int $chatUserId = null,
        ?int $verifiesResolutionId = null,
        string $chatSource = AgentCommand::SOURCE_PANEL,
    ) {
        $this->chatUserId = $chatUserId;
        $this->verifiesResolutionId = $verifiesResolutionId;
        $this->chatSource = $chatSource;
    }

    public function handle(SiteAgent $agent, SiteMemoryStore $memory): void
    {
        $site = Site::find($this->siteId);

        if (! $site) {
            return;
        }

        $summary = $agent->investigate($site, $this->goal, $this->round);

        if (blank($summary)) {
            $reason = $this->blankReason($site);
            SystemLog::record('warning', 'ai', "אבחון AI לאתר {$site->domain} לא הניב תוצאה: ".$reason, ['site_id' => $site->id]);

            // A verification pass must never end silently — the owner is
            // waiting to hear whether the approved fix solved the problem.
            // Tell them the check itself couldn't run, and why.
            if ($this->round > 1) {
                $this->notifyOwner($site, "⚠️ בדיקת האימות לא הצליחה לרוץ: {$reason}");
            }

            // If this was asked from the chat, say so there too instead of
            // leaving the operator waiting for a reply that never comes.
            $this->postToChat($site, "⚠️ בדיקת האתר {$site->domain} לא הצליחה לרוץ: {$reason}");

            return;
        }

        // Keep the latest diagnosis on the site and in the system log; any fix
        // the AI proposed is already waiting in the approvals inbox.
        $memory->put($site, 'אבחון AI אחרון', Str::limit($summary, 2000), 'ai');
        SystemLog::record('info', 'ai', "אבחון AI לאתר {$site->domain}", [
            'site_id' => $site->id,
            'summary' => Str::limit($summary, 1000),
        ]);

        if ($this->round > 1) {
            $this->notifyOwner($site, $summary);

            // The verification confirmed the fix — remember it as a PROVEN
            // solution, so future similar problems (anywhere) start from it.
            // Only the explicit LEADING marker the prompt mandates counts:
            // "לא ניתן לאשר שהבעיה נפתרה" must not read as success.
            if ($this->verifiesResolutionId !== null && str_starts_with(trim($summary), '✅ הבעיה נפתרה')) {
                app(IncidentMemory::class)->confirm($this->verifiesResolutionId);
            }
        }

        // Asked from the chat → return the result to the same conversation (as a
        // system turn), so the operator sees the findings there and any proposed
        // fix shows up for approval — instead of only in the event log.
        $this->postToChat($site, "🔎 תוצאת בדיקת האתר {$site->domain}:\n".Str::limit($summary, 1500));
    }

    /**
     * Post a result back into the conversation that asked for it. The panel
     * console reads its thread from the database, so a system turn is the whole
     * delivery there; the WhatsApp group reads its thread on the phone, so it
     * needs the message sent as well as recorded.
     */
    private function postToChat(Site $site, string $body): void
    {
        $fromWhatsapp = $this->chatSource === AgentCommand::SOURCE_WHATSAPP;

        if ($this->chatUserId === null && ! $fromWhatsapp) {
            return; // Not asked from a chat at all (monitoring, scheduler).
        }

        // Recorded either way, so a follow-up in that conversation has the
        // findings as context.
        AgentCommand::create([
            'user_id' => $this->chatUserId,
            'source' => $this->chatSource,
            'role' => 'system',
            'instruction' => "בדיקת אתר {$site->domain}",
            'outcome' => AgentCommandOutcome::Dispatched,
            'result' => $body,
            'site_id' => $site->id,
        ]);

        if (! $fromWhatsapp) {
            return;
        }

        $chat = app(ApprovalGate::class)->ownerChatId();

        if ($chat === null) {
            return;
        }

        try {
            app(WahaClient::class)->sendMessage($chat, '🤖 '.Str::limit($body, 1200));
        } catch (\Throwable $e) {
            Log::warning('InvestigateSiteJob: chat result delivery failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Push a verification-pass summary to the owner's WhatsApp (best-effort). */
    private function notifyOwner(Site $site, string $summary): void
    {
        if (! config('agent.notify_owner_whatsapp', true)) {
            return;
        }

        $ownerChat = app(ApprovalGate::class)->ownerChatId();

        if ($ownerChat === null) {
            return;
        }

        try {
            app(WahaClient::class)->sendMessage(
                $ownerChat,
                "🔎 בדיקה אחרי תיקון — {$site->domain} (סבב {$this->round})\n\n".Str::limit($summary, 900),
            );
        } catch (\Throwable $e) {
            Log::warning('InvestigateSiteJob: owner notification failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }

    /** Why the investigation produced nothing — actionable, not a dead end. */
    private function blankReason(Site $site): string
    {
        return match (true) {
            ! app(ClaudeClient::class)->isEnabled() => 'סוכן ה-AI כבוי או ללא מפתח — בדקו בהגדרות "סוכן AI".',
            ! $site->mcp_enabled || blank($site->mcp_endpoint) => 'האתר אינו מחובר (חיבור MCP כבוי או ללא כתובת).',
            default => 'ייתכן שהאתר אינו נגיש או שבקשת ה-AI נכשלה — הריצו "בדוק חיבור AI" ובדקו את ספק ה-AI.',
        };
    }
}
