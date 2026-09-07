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
        // A site restored from backup — or one whose options were reset — starts
        // its guard log from zero again. The panel's cursor is then ahead of
        // everything the site will ever produce, so every future removal lands
        // below `after_id` and is hidden for good. The site's own last id is the
        // tell, and it is cheaper to re-read a few entries than to go blind.
        if (array_key_exists('last_id', $status) && (int) $status['last_id'] < (int) $site->guard_cursor) {
            $site->update(['guard_cursor' => 0]);

            try {
                $status = $this->guardStatus($mcp, $site);
            } catch (\Throwable $e) {
                Log::warning('PurgeSiteThreatsJob: re-read after sequence reset failed', ['site' => $site->id, 'error' => $e->getMessage()]);
            }
        }

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
        // Read the two inventories INDEPENDENTLY. Sharing one try/catch meant a
        // site whose agent does not expose wp_admin_list never reached the
        // plugin read at all — so the containment this whole path exists for
        // never ran, on exactly the oldest agents that need it most.
        $plugins = $this->read($mcp, $site, 'wp_plugin_list');
        $users = $this->quarantinedUsers($mcp, $site, $usersChecked);

        if ($plugins === null && ! $usersChecked) {
            // Nothing answered: the site is unreachable. MonitorSiteJob owns
            // "the site is down"; this job does not raise a second alarm for it.
            return;
        }

        if (! $usersChecked) {
            // A gap, not a clean bill of health. Said once a day rather than
            // every hour, because the fix is updating the agent, not this run.
            $this->noteUncheckedUsers($site);
        }

        $slugs = $plugins === null ? [] : ThreatQuarantine::pluginsIn($plugins);

        if ($users === [] && $slugs === []) {
            return;
        }

        $contained = [];

        foreach ($slugs as $slug) {
            $file = ThreatQuarantine::pluginFileFor((string) $plugins, $slug);

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

            // Filed as "found", not "purged", even when we managed to
            // deactivate it: the files are still on the server, and the site
            // page must not show a green shield over a threat that is still
            // there. The title says what we did; the type says where it stands.
            SiteEvent::record($site->id, 'threat_found', 'critical',
                $wasContained ? "תוסף בהסגר כובה (הקבצים נשארו): {$slug}" : "תוסף בהסגר נמצא באתר: {$slug}",
                $wasContained
                    ? 'התוסף כובה ואינו נטען יותר, אך קבצי התוסף עדיין על השרת. למחיקה מלאה יש לעדכן את תוסף הסוכן (1.5.0 ומעלה).'
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

    /** One tool's text output, or null when it could not be read. */
    private function read(McpClient $mcp, Site $site, string $tool, array $arguments = []): ?string
    {
        try {
            return $mcp->textContent($mcp->callTool($site, $tool, $arguments));
        } catch (\Throwable $e) {
            Log::info('PurgeSiteThreatsJob: tool not readable', [
                'site' => $site->id, 'tool' => $tool, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Quarantined logins on a site with no guard.
     *
     * Searched across ALL users, not just administrators. The rule is that the
     * login goes whatever role it holds — an intruder who creates the account
     * as a subscriber today and escalates it tomorrow is the same intruder —
     * and `wp_admin_list` returns administrators only, so relying on it alone
     * meant the account stayed invisible until the day it was promoted, which
     * is the day it stops mattering that we found it.
     *
     * @param  bool|null  $checked  set to false when neither tool could answer,
     *                              so "nothing found" is never mistaken for
     *                              "nothing there"
     * @return list<string>
     */
    private function quarantinedUsers(McpClient $mcp, Site $site, ?bool &$checked): array
    {
        $checked = false;
        $found = [];

        foreach (ThreatQuarantine::users() as $login) {
            // wp_user_list (agent 1.3.0+) searches every role. Its search is a
            // substring match, so the exact-login filter still happens here.
            $text = $this->read($mcp, $site, 'wp_user_list', ['search' => $login, 'limit' => 25]);

            if ($text !== null) {
                $checked = true;

                if (in_array($login, ThreatQuarantine::loginsIn($text), true)) {
                    $found[] = $login;
                }

                continue;
            }

            // Older agent: administrators are all we can see. Better than not
            // looking, and the gap is reported rather than assumed away.
            $admins = $this->read($mcp, $site, 'wp_admin_list');

            if ($admins !== null && in_array($login, ThreatQuarantine::usersIn($admins), true)) {
                $found[] = $login;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Record — at most once a day — that we could not check this site's users
     * at all. Every hour would be noise; never would be a silent blind spot.
     */
    private function noteUncheckedUsers(Site $site): void
    {
        $already = SiteEvent::where('site_id', $site->id)
            ->where('type', 'threat_found')
            ->where('title', 'like', 'לא ניתן היה לבדוק%')
            ->where('detected_at', '>=', now()->subDay())
            ->exists();

        if ($already) {
            return;
        }

        SiteEvent::record($site->id, 'threat_found', 'warning',
            'לא ניתן היה לבדוק את משתמשי האתר',
            'תוסף הסוכן באתר ('.($site->agent_plugin_version ?: 'גרסה לא ידועה').
            ') אינו חושף כלי לקריאת משתמשים, כך שבדיקת ההסגר על המשתמשים לא בוצעה. יש לעדכן את התוסף ל-1.5.0 ומעלה.',
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
