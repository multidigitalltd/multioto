<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SystemLog;
use App\Services\Agent\McpClient;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * End every login on a site: new encryption keys, and every open session cut.
 *
 * Runs for two reasons, and the reason is carried through everything this job
 * writes — a monthly hygiene rotation and a rotation because somebody broke in
 * are the same two API calls and completely different events, and a log that
 * cannot tell them apart is one nobody can read afterwards.
 *
 * BOTH steps are attempted, and neither is allowed to stand in for the other:
 *
 *  - Rotating the salts invalidates every login cookie ever issued. It needs
 *    wp-config.php to be writable, which on plenty of hosts it is not.
 *  - Destroying the session tokens signs out everyone who is signed in now. It
 *    needs nothing but the database, and it is what still works when the first
 *    step cannot run.
 *
 * So a site where the config is read-only is still emptied of live sessions,
 * and the report says exactly which half happened. Reporting "sessions ended"
 * when only one of them ran — on the day of a break-in — is the failure this
 * shape exists to avoid.
 */
class LockOutSiteSessionsJob implements ShouldQueue
{
    use Queueable;

    /** Routine key hygiene, on a schedule. Nobody is under attack. */
    public const REASON_ROUTINE = 'routine';

    /** The guard removed an intrusion indicator; whoever put it there may hold a session. */
    public const REASON_INTRUSION = 'intrusion';

    /**
     * Single attempt. Both calls change the live site, and a blind retry after
     * a timeout would rotate the keys a second time — signing the customer out
     * again, minutes later, for no reason they could see.
     */
    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $siteId,
        public string $reason = self::REASON_ROUTINE,
    ) {}

    public function handle(McpClient $mcp, TeamNotifier $team): void
    {
        $site = Site::with('customer')->find($this->siteId);

        if (! $site || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return;
        }

        $rotated = $this->attempt($mcp, $site, 'wp_salts_rotate');
        $signedOut = $this->attempt($mcp, $site, 'wp_sessions_destroy');

        $this->record($site, $rotated, $signedOut);

        // A routine rotation that worked is not news. An intrusion always is,
        // and so is any failure: a site whose keys could not be replaced is one
        // where a stolen cookie still works, and nobody else will notice.
        if ($this->isIntrusion() || ! $rotated['ok'] || ! $signedOut['ok']) {
            $this->alert($team, $site, $rotated, $signedOut);
        }
    }

    /**
     * Run one of the two calls, and never let its failure stop the other.
     *
     * @return array{ok: bool, error: string|null}
     */
    private function attempt(McpClient $mcp, Site $site, string $tool): array
    {
        try {
            $mcp->textContent($mcp->callTool($site, $tool, [], 60));

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('LockOutSiteSessionsJob: tool failed', [
                'site' => $site->id,
                'tool' => $tool,
                'reason' => $this->reason,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => Str::limit(trim($e->getMessage()) ?: class_basename($e), 300)];
        }
    }

    /**
     * Write what happened onto the site, so the security screen can show it
     * months later without anybody having kept the alert.
     *
     * @param  array{ok: bool, error: string|null}  $rotated
     * @param  array{ok: bool, error: string|null}  $signedOut
     */
    private function record(Site $site, array $rotated, array $signedOut): void
    {
        $bothWorked = $rotated['ok'] && $signedOut['ok'];

        SiteEvent::record(
            $site->id,
            $bothWorked ? 'sessions_locked' : 'sessions_lock_failed',
            $bothWorked ? ($this->isIntrusion() ? 'warning' : 'info') : 'critical',
            $bothWorked
                ? ($this->isIntrusion()
                    ? 'נותקו כל ההתחברויות בעקבות חשד לפריצה'
                    : 'החלפת מפתחות הצפנה חודשית — כל ההתחברויות נותקו')
                : 'ניתוק ההתחברויות לא הושלם',
            implode("\n", array_filter([
                $rotated['ok'] ? '✓ מפתחות ההצפנה הוחלפו' : '✗ החלפת מפתחות נכשלה: '.$rotated['error'],
                $signedOut['ok'] ? '✓ אסימוני ההתחברות נמחקו' : '✗ מחיקת אסימוני ההתחברות נכשלה: '.$signedOut['error'],
            ])),
        );

        if (! $bothWorked) {
            SystemLog::record('warning', 'security',
                "ניתוק ההתחברויות באתר {$site->domain} לא הושלם",
                ['site_id' => $site->id, 'reason' => $this->reason]);
        }
    }

    /**
     * @param  array{ok: bool, error: string|null}  $rotated
     * @param  array{ok: bool, error: string|null}  $signedOut
     */
    private function alert(TeamNotifier $team, Site $site, array $rotated, array $signedOut): void
    {
        $bothWorked = $rotated['ok'] && $signedOut['ok'];

        $title = match (true) {
            $this->isIntrusion() && $bothWorked => "🔐 נותקו כל ההתחברויות באתר {$site->domain}",
            $this->isIntrusion() => "🚨 ניתוק ההתחברויות נכשל באתר {$site->domain}",
            default => "⚠️ החלפת מפתחות ההצפנה נכשלה באתר {$site->domain}",
        };

        $lines = [
            $site->customer?->name ? "לקוח: {$site->customer->name}" : null,
            $rotated['ok'] ? '✓ מפתחות ההצפנה הוחלפו' : '✗ החלפת מפתחות נכשלה: '.$rotated['error'],
            $signedOut['ok'] ? '✓ אסימוני ההתחברות נמחקו' : '✗ מחיקת אסימוני ההתחברות נכשלה: '.$signedOut['error'],
            '',
            $bothWorked
                ? 'כל מי שהיה מחובר לאתר — כולל הלקוח — נדרש להתחבר מחדש.'
                : 'לפחות חלק מההתחברויות הקיימות עדיין תקפות. אם מישהו נכנס לאתר, ייתכן שהוא עדיין בפנים — נדרש טיפול ידני.',
            $this->isIntrusion()
                ? 'הסיבה: השומר הסיר סימן פריצה מהאתר. כדאי גם להחליף סיסמאות מנהל ולבדוק מה עוד השתנה.'
                : null,
        ];

        try {
            $team->alert($title, implode("\n", array_filter($lines, fn ($l): bool => $l !== null)),
                rtrim((string) config('app.url'), '/').'/admin/sites/'.$site->id);
        } catch (\Throwable $e) {
            Log::warning('LockOutSiteSessionsJob: alert could not be sent', ['error' => $e->getMessage()]);
        }
    }

    private function isIntrusion(): bool
    {
        return $this->reason === self::REASON_INTRUSION;
    }
}
