<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteEvent;
use App\Services\Agent\McpClient;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\ThreatQuarantine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Remove the two named intrusion indicators from a connected site, and report
 * what happened.
 *
 * The removal itself belongs to the site: the companion plugin (1.5.0+) carries
 * the quarantine list hard-coded and acts on its own hooks, within the same
 * request that created the account or activated the plugin. This job exists for
 * the parts a site cannot do for itself:
 *
 *  - It reports. The site writes what it removed to a local log; nobody reads a
 *    log on a customer's site. This drains it into the panel and alerts the team.
 *  - It sweeps sites the hooks could not reach — a user written straight into
 *    the database, a plugin folder uploaded over FTP.
 *  - It covers sites still on an older plugin, where nothing is watching at all.
 *    There it does what the old tool vocabulary allows (deactivate the plugin,
 *    which is what makes it reachable) and says plainly that finishing the job
 *    needs the plugin updated. Half a containment reported honestly beats a
 *    silent "checked, all fine".
 */
class PurgeSiteThreatsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(public int $siteId) {}

    public function handle(McpClient $mcp, TeamNotifier $team): void
    {
        if (! ThreatQuarantine::enabled()) {
            return;
        }

        $site = Site::with('customer')->find($this->siteId);

        if (! $site || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return;
        }

        try {
            $status = $this->guardStatus($mcp, $site);
        } catch (\Throwable $e) {
            // Either the site is unreachable or its plugin predates the guard.
            // Both are handled the same way: look for ourselves.
            $this->withoutGuard($mcp, $team, $site, $e->getMessage());

            return;
        }

        $this->withGuard($mcp, $team, $site, $status);
    }

    /**
     * A site whose plugin carries the guard. Ask it to sweep if anything is
     * still present, then drain its log.
     *
     * @param  array<string, mixed>  $status
     */
    private function withGuard(McpClient $mcp, TeamNotifier $team, Site $site, array $status): void
    {
        $present = array_merge(
            (array) data_get($status, 'present.users', []),
            (array) data_get($status, 'present.plugins', []),
        );

        if ($present !== []) {
            try {
                // Takes no arguments: the site decides what "quarantined" means.
                $mcp->callTool($site, 'wp_guard_purge');
                $status = $this->guardStatus($mcp, $site);
            } catch (\Throwable $e) {
                Log::warning('PurgeSiteThreatsJob: purge failed', ['site' => $site->id, 'error' => $e->getMessage()]);
            }
        }

        $actions = array_values(array_filter((array) data_get($status, 'actions', []), 'is_array'));

        if ($actions !== []) {
            $this->recordActions($site, $actions);
            $this->alertActions($team, $site, $actions);
        }

        // Advance the cursor only over entries we actually recorded, so a
        // failure above means the same entries come back next run rather than
        // being lost.
        $lastId = collect($actions)->max(fn (array $a): int => (int) ($a['id'] ?? 0));

        if ($lastId !== null && (int) $lastId > (int) $site->guard_cursor) {
            $site->update(['guard_cursor' => (int) $lastId]);
        }

        $stillThere = array_merge(
            (array) data_get($status, 'present.users', []),
            (array) data_get($status, 'present.plugins', []),
        );

        if ($stillThere !== []) {
            // The guard ran and the thing is still there: it refused (the last
            // administrator) or could not write. That needs a person today.
            $this->alertStuck($team, $site, $stillThere);
        }
    }

    /**
     * A site with no guard: read the ordinary inventory, and contain what we
     * can with the tools an older plugin has.
     */
    private function withoutGuard(McpClient $mcp, TeamNotifier $team, Site $site, string $why): void
    {
        try {
            $admins = $mcp->textContent($mcp->callTool($site, 'wp_admin_list'));
            $plugins = $mcp->textContent($mcp->callTool($site, 'wp_plugin_list'));
        } catch (\Throwable $e) {
            // Unreachable site. MonitorSiteJob already owns "the site is down";
            // this job stays quiet rather than raising a second alarm for it.
            Log::info('PurgeSiteThreatsJob: site not readable', ['site' => $site->id, 'error' => $e->getMessage()]);

            return;
        }

        $users = ThreatQuarantine::usersIn($admins);
        $slugs = ThreatQuarantine::pluginsIn($plugins);

        if ($users === [] && $slugs === []) {
            return;
        }

        $contained = [];

        foreach ($slugs as $slug) {
            $file = ThreatQuarantine::pluginFileFor($plugins, $slug);

            if ($file === null) {
                continue;
            }

            try {
                $mcp->callTool($site, 'wp_plugin_deactivate', ['plugin' => $file]);
                $contained[] = $slug;
            } catch (\Throwable $e) {
                Log::warning('PurgeSiteThreatsJob: deactivate failed', ['site' => $site->id, 'plugin' => $file, 'error' => $e->getMessage()]);
            }
        }

        foreach ($users as $login) {
            SiteEvent::record($site->id, 'threat_found', 'critical',
                "משתמש בהסגר נמצא באתר: {$login}",
                'התוסף באתר ישן מכדי להסיר אותו לבד. יש לעדכן את תוסף הסוכן (1.5.0 ומעלה) או למחוק את המשתמש ידנית — עכשיו.',
            );
        }

        foreach ($slugs as $slug) {
            $wasContained = in_array($slug, $contained, true);

            SiteEvent::record($site->id, $wasContained ? 'threat_purged' : 'threat_found', 'critical',
                $wasContained ? "תוסף בהסגר כובה: {$slug}" : "תוסף בהסגר נמצא באתר: {$slug}",
                $wasContained
                    ? 'התוסף כובה, אך קבצי התוסף עדיין על השרת. למחיקה מלאה יש לעדכן את תוסף הסוכן (1.5.0 ומעלה).'
                    : 'לא ניתן היה לכבות את התוסף מרחוק — נדרשת הסרה ידנית מיידית.',
            );
        }

        $team->alert(
            "🚨 סימן פריצה באתר {$site->domain} — הסרה חלקית בלבד",
            $this->customerLine($site).
            "נמצאו פריטים שברשימת ההסגר:\n".
            collect($users)->map(fn (string $l): string => "👤 משתמש: {$l} — לא הוסר")->implode("\n").
            ($users !== [] && $slugs !== [] ? "\n" : '').
            collect($slugs)->map(fn (string $s): string => '🧩 תוסף: '.$s.(in_array($s, $contained, true) ? ' — כובה, הקבצים נשארו' : ' — לא הוסר'))->implode("\n").
            "\n\nתוסף הסוכן באתר ישן מכדי להסיר אותם לבד (".($site->agent_plugin_version ?: 'גרסה לא ידועה').
            "). יש לעדכן את התוסף ל-1.5.0 ומעלה, ועד אז לטפל ידנית.\n\nהסיבה שהשומר לא נענה: ".$why,
            $this->siteUrl($site),
        );
    }

    /** @param  list<array<string, mixed>>  $actions */
    private function recordActions(Site $site, array $actions): void
    {
        foreach ($actions as $action) {
            $result = (string) ($action['result'] ?? '');
            $target = (string) ($action['target'] ?? '');
            $noun = ($action['kind'] ?? '') === 'user' ? 'משתמש' : 'תוסף';

            SiteEvent::record(
                $site->id,
                $result === 'removed' ? 'threat_purged' : 'threat_found',
                'critical',
                $result === 'removed'
                    ? "{$noun} בהסגר הוסר אוטומטית: {$target}"
                    : "{$noun} בהסגר לא הוסר: {$target}",
                (string) ($action['detail'] ?? ''),
            );
        }
    }

    /** @param  list<array<string, mixed>>  $actions */
    private function alertActions(TeamNotifier $team, Site $site, array $actions): void
    {
        $removed = collect($actions)->where('result', 'removed');

        $lines = collect($actions)->map(function (array $a): string {
            $icon = ($a['kind'] ?? '') === 'user' ? '👤' : '🧩';
            $verb = [
                'removed' => 'הוסר',
                'deactivated' => 'כובה (הקבצים נשארו)',
                'skipped' => 'לא הוסר',
                'failed' => 'ההסרה נכשלה',
            ][$a['result'] ?? ''] ?? (string) ($a['result'] ?? '');

            return "{$icon} {$a['target']} — {$verb}\n   ".trim((string) ($a['detail'] ?? ''));
        })->implode("\n");

        $team->alert(
            $removed->count() === count($actions)
                ? "🛡️ סימן פריצה הוסר אוטומטית באתר {$site->domain}"
                : "🚨 סימן פריצה באתר {$site->domain} — נדרשת בדיקה",
            $this->customerLine($site).
            "השומר באתר פעל בלי לחכות לאישור:\n{$lines}\n\n".
            'מישהו הצליח ליצור משתמש או להתקין תוסף באתר — ההסרה עצמה טופלה, אבל דרך הכניסה עדיין פתוחה. '.
            'כדאי להחליף סיסמאות מנהל, להחליף את מפתחות ההצפנה (salts) ולבדוק מה עוד השתנה באתר.',
            $this->siteUrl($site),
        );
    }

    /** @param  list<string>  $stillThere */
    private function alertStuck(TeamNotifier $team, Site $site, array $stillThere): void
    {
        $team->alert(
            "🚨 סימן פריצה שלא הוסר באתר {$site->domain}",
            $this->customerLine($site).
            "השומר ניסה להסיר ולא הצליח:\n".collect($stillThere)->map(fn (string $t): string => "• {$t}")->implode("\n").
            "\n\nזה קורה כששאין הרשאות כתיבה, או כשהמשתמש הוא המנהל היחיד באתר ומחיקתו הייתה נועלת את הבעלים בחוץ. נדרש טיפול ידני עכשיו.",
            $this->siteUrl($site),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function guardStatus(McpClient $mcp, Site $site): array
    {
        $text = $mcp->textContent($mcp->callTool($site, 'wp_guard_status', [
            'after_id' => (int) $site->guard_cursor,
        ]));

        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('wp_guard_status החזיר תשובה שאינה JSON.');
        }

        return $decoded;
    }

    private function customerLine(Site $site): string
    {
        return $site->customer ? "לקוח: {$site->customer->name}\n\n" : '';
    }

    private function siteUrl(Site $site): string
    {
        return rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}";
    }
}
