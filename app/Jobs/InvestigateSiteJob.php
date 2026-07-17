<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SystemLog;
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

    public function __construct(
        public int $siteId,
        public string $goal,
        public int $round = 1,
    ) {}

    public function handle(SiteAgent $agent, SiteMemoryStore $memory): void
    {
        $site = Site::find($this->siteId);

        if (! $site) {
            return;
        }

        $summary = $agent->investigate($site, $this->goal, $this->round);

        if (blank($summary)) {
            SystemLog::record('warning', 'ai', "אבחון AI לאתר {$site->domain} לא הניב תוצאה: ".$this->blankReason($site), ['site_id' => $site->id]);

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
