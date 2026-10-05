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
 *  - It hands the site the names the team marked "delete immediately" in the
 *    panel, and reads back what the site is actually enforcing. The site bounds
 *    that list itself (never the agent, never user #1, never the last admin, a
 *    cap per sweep), so what the panel sends is a request and not an authority.
 *  - It looks for the rest of the team's names — the watch-only ones, which the
 *    site's own guard has never heard of and never will. Those are reported.
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

        // Hand the site the rules marked "delete immediately" BEFORE deciding
        // what to purge, so a rule added a minute ago is acted on in this run
        // rather than the next one.
        $status = $this->syncAutoRemoveRules($mcp, $site, $status, $holdsOurRules);

        $this->withGuard($mcp, $team, $site, $status);

        // The guard answered for the names it carries. Ours it has never heard
        // of, so they are looked for here — see sweepOwnRules().
        $this->sweepOwnRules($mcp, $team, $site, $holdsOurRules);
    }

    /**
     * Give the site the "delete immediately" list, and read back what it holds.
     *
     * Pushed on every run where it differs from what the site reports, which is
     * also the repair: a site restored from a backup comes back with the list it
     * had on the day of the backup, and nothing else would ever notice.
     *
     * `panel_rules` missing from the status is how an older plugin announces that
     * it cannot hold a list at all. That is not an error to report — most sites
     * will be in that state the day this ships — but it does mean an auto-remove
     * rule is not auto-removing there, which sweepOwnRules() then says.
     *
     * @param  array<string, mixed>  $status
     * @param  bool|null  $holdsOurRules  set to whether the site is enforcing them
     * @return array<string, mixed> the status, re-read when a push changed it
     */
    private function syncAutoRemoveRules(McpClient $mcp, Site $site, array $status, ?bool &$holdsOurRules): array
    {
        $holdsOurRules = false;

        if (! array_key_exists('panel_rules', $status)) {
            return $status; // Plugin older than 1.7.0 — nothing to push to.
        }

        $holdsOurRules = true;

        $wanted = [
            'users' => ThreatQuarantine::autoRemoveUsers(),
            'plugins' => ThreatQuarantine::autoRemovePlugins(),
        ];

        $held = [
            'users' => $this->sortedStrings(data_get($status, 'panel_rules.users', [])),
            'plugins' => $this->sortedStrings(data_get($status, 'panel_rules.plugins', [])),
        ];

        if ($held === ['users' => $this->sortedStrings($wanted['users']), 'plugins' => $this->sortedStrings($wanted['plugins'])]) {
            return $status; // Already in force — no call, no noise.
        }

        try {
            $mcp->callTool($site, 'wp_guard_rules', $wanted);

            // Re-read rather than assume: the site refuses anything it will not
            // hold, and what it is actually enforcing is the only answer worth
            // acting on — a push reported as sent is not a push that landed.
            return $this->guardStatus($mcp, $site);
        } catch (\Throwable $e) {
            // The list did not land, so nothing here is enforcing it. Saying so
            // is what makes sweepOwnRules look for these names instead.
            $holdsOurRules = false;

            Log::warning('PurgeSiteThreatsJob: pushing auto-remove rules failed', [
                'site' => $site->id, 'error' => $e->getMessage(),
            ]);

            return $status;
        }
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function sortedStrings($values): array
    {
        $out = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => mb_strtolower(trim((string) $value)),
            is_array($values) ? $values : [],
        ), static fn (string $value): bool => $value !== '')));

        sort($out);

        return $out;
    }

    /**
     * The rules the team added from the panel, on a site that guards itself.
     *
     * `wp_guard_status` answers from the list hard coded in the plugin — which
     * is the whole point: nothing sent over the network can widen what a site
     * deletes. The cost of that guarantee is that the guard's report says
     * nothing about a rule added in the panel, so without this pass such a rule
     * would be watched for on paper and on no up-to-date site in practice.
     *
     * Worse than merely silent, in fact: CheckSitePluginChangesJob treats a
     * matching new install as a quarantined addition, drops it from its own
     * alert and hands it to this job. Adding a rule would then make the system
     * report LESS about that name than before it existed.
     *
     * Found and reported, never removed — the same line the screen draws. And
     * it costs an ordinary system nothing: no rules, no calls.
     *
     * @param  bool  $holdsOurRules  whether the site accepted the "delete
     *                               immediately" list. When it did, those names
     *                               are reported through its own guard log and
     *                               looking for them here would file every
     *                               finding twice. When it did not — an older
     *                               plugin, or a push that failed — they are
     *                               swept here, because a rule marked for
     *                               deletion that is neither deleted nor
     *                               reported is the worst of the three.
     */
    private function sweepOwnRules(McpClient $mcp, TeamNotifier $team, Site $site, bool $holdsOurRules): void
    {
        $logins = $holdsOurRules ? ThreatQuarantine::reportOnlyUsers() : ThreatQuarantine::customUsers();
        $slugs = $holdsOurRules ? ThreatQuarantine::reportOnlyPlugins() : ThreatQuarantine::customPlugins();

        if ($logins === [] && $slugs === []) {
            return;
        }

        $foundUsers = $logins === []
            ? []
            : $this->quarantinedUsers($mcp, $site, $checked, $logins);

        $inventory = $slugs === [] ? null : $this->read($mcp, $site, 'wp_plugin_list');

        $foundPlugins = $inventory === null
            ? []
            : array_values(array_intersect($slugs, ThreatQuarantine::slugsIn($inventory)));

        // A name that reaches this method is one nothing deleted. Two very
        // different reasons for that, and the team has to be told which: a rule
        // that was never meant to delete, or one marked "delete immediately" on
        // a site whose plugin cannot enforce it. The second is the dangerous
        // one, because somebody ticked a box and believes it is handled.
        $unenforced = array_merge(ThreatQuarantine::autoRemoveUsers(), ThreatQuarantine::autoRemovePlugins());

        $fresh = [];
        $anyUnenforced = false;

        foreach ([['משתמש', '👤', $foundUsers], ['תוסף', '🧩', $foundPlugins]] as [$noun, $icon, $found]) {
            foreach ($found as $target) {
                $marked = in_array($target, $unenforced, true);

                if ($this->noteOwnRuleFinding($site, $noun, $target, $marked)) {
                    $fresh[] = "{$icon} {$noun}: {$target}".($marked ? ' — מסומן "מחק מיד" ולא נמחק' : '');
                }

                $anyUnenforced = $anyUnenforced || $marked;
            }
        }

        if ($fresh === []) {
            return;
        }

        $team->alert(
            $anyUnenforced
                ? "🚨 כלל \"מחק מיד\" לא נאכף באתר {$site->domain}"
                : "🚨 כלל מעקב נמצא באתר {$site->domain}",
            $this->customerLine($site).
            "נמצאו פריטים מכללי המעקב שהוספתם בפאנל:\n".implode("\n", $fresh).
            "\n\n".($anyUnenforced
                ? 'פריט שסומן "מחק מיד" נמצא ולא נמחק: תוסף הסוכן באתר הזה ('.($site->agent_plugin_version ?: 'גרסה לא ידועה')
                    .') ישן מכדי להחזיק את הרשימה. יש לעדכן אותו ל-1.7.0 ומעלה, ועד אז למחוק ידנית — עכשיו.'
                : 'אלה אינם מוסרים אוטומטית: הם נוספו למעקב בלבד. נדרשת בדיקה ידנית, או סימון "מחק מיד" אם ברור שהם תמיד פריצה.'),
            $this->siteUrl($site),
        );
    }

    /**
     * File a panel-rule finding, at most once a day per site and name.
     *
     * Nothing removes these, so the same name is found again every hour for as
     * long as it is there. Filing it every hour would bury the one finding that
     * is new under a hundred that are not, and a team that learns to skip this
     * alert is worse off than one that never had it.
     *
     * @param  bool  $markedForRemoval  the rule says delete, and this site did not
     * @return bool whether this was new, and so worth an alert
     */
    private function noteOwnRuleFinding(Site $site, string $noun, string $target, bool $markedForRemoval = false): bool
    {
        $title = $markedForRemoval
            ? "{$noun} שסומן \"מחק מיד\" לא נמחק באתר: {$target}"
            : "{$noun} מכלל מעקב נמצא באתר: {$target}";

        $already = SiteEvent::where('site_id', $site->id)
            ->where('type', 'threat_found')
            ->where('title', $title)
            ->where('detected_at', '>=', now()->subDay())
            ->exists();

        if ($already) {
            return false;
        }

        SiteEvent::record($site->id, 'threat_found', 'critical', $title,
            $markedForRemoval
                ? 'הכלל מסומן "מחק מיד", אך תוסף הסוכן באתר הזה ('.($site->agent_plugin_version ?: 'גרסה לא ידועה')
                    .') ישן מכדי להחזיק את הרשימה ולכן לא מחק דבר. יש לעדכן אותו ל-1.7.0 ומעלה, ועד אז למחוק ידנית.'
                : 'זהו כלל מעקב שהוספתם בפאנל, לאיתור ודיווח בלבד — הוא אינו מסומן "מחק מיד" ולכן שום דבר לא הסיר אותו. '.
                    'יש להסיר ידנית, או להחליט שהפריט תקין ולהסיר את הכלל.',
        );

        return true;
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
            $this->lockOutSessions($site, $actions);
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
     * @param  list<string>|null  $logins  a narrower list to look for; the whole
     *                                     watch list when not given
     * @return list<string>
     */
    private function quarantinedUsers(McpClient $mcp, Site $site, ?bool &$checked, ?array $logins = null): array
    {
        $checked = false;
        $found = [];

        foreach ($logins ?? ThreatQuarantine::users() as $login) {
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

            // Which list authorised it, as the site reported it. "The plugin's
            // own hard-coded list" and "a rule somebody ticked in the panel" are
            // different facts about a deletion on a customer's site, and only
            // one of them can be changed by whoever holds the panel.
            $byRule = ($action['source'] ?? 'builtin') === 'panel';
            $origin = $byRule ? ' (כלל מהפאנל)' : '';

            SiteEvent::record(
                $site->id,
                $result === 'removed' ? 'threat_purged' : 'threat_found',
                'critical',
                $result === 'removed'
                    ? "{$noun} בהסגר הוסר אוטומטית{$origin}: {$target}"
                    : "{$noun} בהסגר לא הוסר{$origin}: {$target}",
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
            $origin = ($a['source'] ?? 'builtin') === 'panel' ? ' [כלל מהפאנל]' : '';
            $verb = [
                'removed' => 'הוסר',
                'deactivated' => 'כובה (הקבצים נשארו)',
                'skipped' => 'לא הוסר',
                'failed' => 'ההסרה נכשלה',
            ][$a['result'] ?? ''] ?? (string) ($a['result'] ?? '');

            return "{$icon} {$a['target']}{$origin} — {$verb}\n   ".trim((string) ($a['detail'] ?? ''));
        })->implode("\n");

        $team->alert(
            $removed->count() === count($actions)
                ? "🛡️ סימן פריצה הוסר אוטומטית באתר {$site->domain}"
                : "🚨 סימן פריצה באתר {$site->domain} — נדרשת בדיקה",
            $this->customerLine($site).
            "השומר באתר פעל בלי לחכות לאישור:\n{$lines}\n\n".
            'מישהו הצליח ליצור משתמש או להתקין תוסף באתר — ההסרה עצמה טופלה. '.
            'מפתחות ההצפנה מוחלפים עכשיו אוטומטית וכל ההתחברויות מנותקות (תגיע הודעה נפרדת). '.
            'כדאי גם להחליף סיסמאות מנהל ולבדוק מה עוד השתנה באתר.',
            $this->siteUrl($site),
        );
    }

    /**
     * A quarantined account or plugin was ON THIS SITE, so somebody got in —
     * and they may be holding a login cookie right now. Replacing the keys and
     * cutting the sessions is the half of the containment the removal does not
     * cover: deleting the account they created does nothing to the browser they
     * are already signed in from.
     *
     * Triggered by the guard having ANYTHING to report, not by it having
     * succeeded. Every outcome it logs means the indicator was present:
     * `deactivated` is a plugin it neutralised but could not delete from a
     * read-only filesystem, and `skipped` is usually the intruder's account
     * being the site's last administrator. Those are the WORSE cases, not
     * lesser ones — reading them as "nothing happened" would leave the attacker
     * signed in precisely where the cleanup failed.
     *
     * Each log entry is acted on once: the cursor advances over what was
     * recorded, so a threat that stays stuck does not sign the customer out
     * again on every sweep.
     *
     * @param  list<array<string, mixed>>  $actions
     */
    private function lockOutSessions(Site $site, array $actions): void
    {
        if ($actions === []) {
            return;
        }

        LockOutSiteSessionsJob::dispatch($site->id, LockOutSiteSessionsJob::REASON_INTRUSION);
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
