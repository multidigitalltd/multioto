<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The intrusions we do not wait to discuss.
 *
 * An administrator called `sys_maint` and the wp-file-manager plugin are not
 * ambiguous findings — they are the pair a compromised WordPress site gets
 * within minutes of being taken: a second owner nobody created, and a browser
 * file manager to drop a shell with. Both are removed here the moment they
 * appear, without asking anyone, because the window between "detected" and
 * "approved by a human" is the window the intruder is inside the site.
 *
 * Those two are HARD-CODED and need no instruction from anybody.
 *
 * From 1.7.0 the team can also mark a name in the panel as "delete immediately",
 * and that list IS sent over the network — which gives up a guarantee this file
 * used to make, so it is worth being exact about what replaced it. The old
 * guarantee was absolute: a fully compromised panel could not order one deletion
 * it had not already been given. What stands now is narrower and enforced HERE,
 * where no instruction can reach it:
 *
 *  - Never this plugin. An order to delete the agent cannot be used to blind the
 *    site before doing something else to it.
 *  - Never user #1, for a name that came from the panel. On nearly every install
 *    that is the owner's own account.
 *  - Never the last administrator, for any name at all (as before).
 *  - At most PUSHED_PER_SWEEP panel-ordered removals in one sweep, so an order to
 *    delete everything gets a handful and a report, not a site.
 *  - A name is a name: a login with no whitespace or separators, a plugin folder
 *    matching one strict pattern. Nothing path-shaped is accepted, on the way in
 *    OR on the way back out of the option it is stored in.
 *
 * Nothing is deleted blind either way. A user's content is reassigned to the
 * oldest remaining administrator rather than destroyed, and every action is
 * written to a log the panel drains — carrying WHICH list authorised it, because
 * "the site decided this by itself" and "the panel told it to" are different
 * facts and a reviewer needs to tell them apart.
 */
class Multioto_Agent_Guard
{
    /**
     * Logins removed on sight, whatever role they hold.
     *
     * Role is not part of the test on purpose: an intruder who creates
     * `sys_maint` as a subscriber today and escalates it tomorrow is the same
     * intruder, and the name is the tell.
     */
    const USERS = ['sys_maint'];

    /** Plugin folder slugs removed on sight. */
    const PLUGINS = ['wp-file-manager'];

    /** Names the panel marked "delete immediately", as this site received them. */
    const PUSHED_OPTION = 'multioto_agent_guard_pushed';

    /** How many names of each kind this site will hold on the panel's behalf. */
    const PUSHED_MAX = 50;

    /**
     * Panel-ordered removals allowed in one sweep.
     *
     * The cap is about the bulk case and nothing else: a panel that has been
     * taken over and orders every account on the site destroyed gets this many
     * and then a log entry saying it stopped. It is not a defence against a
     * targeted order — nothing here can be — which is why the never-lists above
     * exist alongside it.
     */
    const PUSHED_PER_SWEEP = 5;

    /** Refused however it is asked for: deleting this is deleting the watchman. */
    const NEVER_PLUGINS = ['multioto-agent'];

    /** Refused for a PANEL rule: on nearly every install this is the owner. */
    const NEVER_USER_IDS = [1];

    const LOG_OPTION = 'multioto_agent_guard_log';

    const SEQ_OPTION = 'multioto_agent_guard_seq';

    const SWEEP_MARKER = 'multioto_agent_guard_swept';

    /** Entries kept. The panel drains by id, so this is a floor under a silent panel. */
    const LOG_MAX = 50;

    /** Seconds between background sweeps (the hooks below are the instant path). */
    const SWEEP_EVERY = 300;

    public function boot(): void
    {
        // Priority 1: before anything else can act on the new account.
        add_action('user_register', [$this, 'onUserRegister'], 1);
        add_action('activated_plugin', [$this, 'onPluginActivated'], 1);
        add_action('upgrader_process_complete', [$this, 'onUpgraderComplete'], 10, 2);

        // The catch-all for everything the hooks miss: a user written straight
        // into the database, a plugin folder uploaded over FTP, or a site that
        // was already compromised before this plugin was installed.
        add_action('init', [$this, 'maybeSweep'], 1);
    }

    /** A new account whose login is on the list never finishes being created. */
    public function onUserRegister($userId): void
    {
        $user = get_user_by('id', (int) $userId);

        if ($user instanceof WP_User && $this->isQuarantinedLogin($user->user_login)) {
            $this->purgeUser($user->user_login);
        }
    }

    /** @param string $plugin plugin file, e.g. "wp-file-manager/file_folder_manager.php" */
    public function onPluginActivated($plugin): void
    {
        $slug = $this->slugOf((string) $plugin);

        if ($slug !== '' && $this->pluginSource($slug) !== '') {
            $this->purgePlugin($slug);
        }
    }

    /**
     * Installs and updates run through the upgrader rather than through
     * `activated_plugin`, so a plugin installed-but-not-activated (which still
     * leaves its files on disk, reachable) is caught here.
     *
     * @param  mixed  $upgrader
     * @param  array<string, mixed>  $data
     */
    public function onUpgraderComplete($upgrader, $data): void
    {
        if (! is_array($data) || ($data['type'] ?? '') !== 'plugin') {
            return;
        }

        $this->sweepPlugins();
    }

    /** A full sweep, at most once every SWEEP_EVERY seconds. */
    public function maybeSweep(): void
    {
        if (get_transient(self::SWEEP_MARKER)) {
            return;
        }

        set_transient(self::SWEEP_MARKER, 1, self::SWEEP_EVERY);

        $this->sweep();
    }

    /**
     * Look for everything on the list and remove what is there.
     *
     * @return list<array<string, mixed>> the actions taken (empty when clean)
     */
    public function sweep(): array
    {
        $actions = [];
        $budget = self::PUSHED_PER_SWEEP;

        foreach ($this->watchedUsers() as $login) {
            $fromPanel = $this->userSource($login) === 'panel';

            if ($fromPanel && $budget <= 0) {
                // Say which name was left, not just that something was: a log
                // entry nobody can act on is the same as no log entry.
                $actions[] = $this->log('user', $login, 'skipped', sprintf(
                    'הסריקה עצרה לאחר %d הסרות מכללי הפאנל. השם הזה לא נבדק בסריקה הזאת ויטופל בסריקה הבאה.',
                    self::PUSHED_PER_SWEEP,
                ), 'panel');

                break;
            }

            $action = $this->purgeUser($login);

            if ($action === null) {
                continue;
            }

            $actions[] = $action;

            if ($fromPanel) {
                $budget--;
            }
        }

        return array_merge($actions, $this->sweepPlugins($budget));
    }

    /**
     * @param  int  $budget  panel-ordered removals still allowed this sweep
     * @return list<array<string, mixed>>
     */
    private function sweepPlugins(int $budget = self::PUSHED_PER_SWEEP): array
    {
        $actions = [];

        foreach ($this->watchedPlugins() as $slug) {
            $fromPanel = $this->pluginSource($slug) === 'panel';

            if ($fromPanel && $budget <= 0) {
                $actions[] = $this->log('plugin', $slug, 'skipped', sprintf(
                    'הסריקה עצרה לאחר %d הסרות מכללי הפאנל. התוסף הזה לא נבדק בסריקה הזאת ויטופל בסריקה הבאה.',
                    self::PUSHED_PER_SWEEP,
                ), 'panel');

                break;
            }

            $action = $this->purgePlugin($slug);

            if ($action === null) {
                continue;
            }

            $actions[] = $action;

            if ($fromPanel) {
                $budget--;
            }
        }

        return $actions;
    }

    /**
     * Replace the panel's "delete immediately" list on this site.
     *
     * Everything is re-read from the stored option at the moment of deletion, so
     * this method is a gate and not a source of authority: what it refuses here
     * is refused again there.
     *
     * @param  mixed  $users
     * @param  mixed  $plugins
     * @return array<string, mixed> what was accepted, and what was not
     */
    public function setPushedRules($users, $plugins): array
    {
        $refused = [];

        $accepted = [
            'users' => $this->sanitise(is_array($users) ? $users : [], 'user', $refused),
            'plugins' => $this->sanitise(is_array($plugins) ? $plugins : [], 'plugin', $refused),
        ];

        $before = ['users' => $this->pushedList('users'), 'plugins' => $this->pushedList('plugins')];

        if ($before !== $accepted) {
            update_option(self::PUSHED_OPTION, $accepted, false);

            // Not into the log the panel drains: that log is a record of what was
            // done TO the site, and the panel already knows what it sent. The
            // site's own error log is where a reviewer looks for "who widened
            // this, and when" if the panel's story is the thing in doubt.
            error_log(sprintf(
                '[multioto-agent] guard rules set by panel: users=%s plugins=%s',
                implode(',', $accepted['users']) ?: '-',
                implode(',', $accepted['plugins']) ?: '-',
            ));
        }

        return [
            'users' => $accepted['users'],
            'plugins' => $accepted['plugins'],
            'refused' => array_values(array_unique($refused)),
            'max' => self::PUSHED_MAX,
            'never_plugins' => self::NEVER_PLUGINS,
        ];
    }

    /**
     * Names this site watches for: the hard-coded ones first, then the panel's.
     *
     * Order is not cosmetic. The per-sweep cap only ever applies to the panel's
     * half, so the two built-ins must be reached before any budget can run out —
     * a flood of panel rules must never be able to crowd out the removal this
     * file was written for.
     *
     * @return list<string>
     */
    private function watchedUsers(): array
    {
        return array_values(array_unique(array_merge(self::USERS, $this->pushedList('users'))));
    }

    /** @return list<string> */
    private function watchedPlugins(): array
    {
        return array_values(array_unique(array_merge(self::PLUGINS, $this->pushedList('plugins'))));
    }

    /** Which list a name is on: 'builtin', 'panel', or '' for neither. */
    private function userSource(string $login): string
    {
        $login = strtolower(trim($login));

        if (in_array($login, self::USERS, true)) {
            return 'builtin';
        }

        return in_array($login, $this->pushedList('users'), true) ? 'panel' : '';
    }

    private function pluginSource(string $slug): string
    {
        $slug = strtolower(trim($slug));

        if (in_array($slug, self::PLUGINS, true)) {
            return 'builtin';
        }

        return in_array($slug, $this->pushedList('plugins'), true) ? 'panel' : '';
    }

    /**
     * The stored panel list for one kind.
     *
     * Validated on the way OUT as well as in, and that is deliberate: this is an
     * ordinary wp_options row, so anything with database access to the site can
     * write it — an intruder included. A list that decides what gets deleted is
     * not something to read back on trust.
     *
     * @return list<string>
     */
    private function pushedList(string $kind): array
    {
        $stored = get_option(self::PUSHED_OPTION, []);

        if (! is_array($stored) || ! isset($stored[$kind]) || ! is_array($stored[$kind])) {
            return [];
        }

        $refused = [];

        return $this->sanitise($stored[$kind], $kind === 'users' ? 'user' : 'plugin', $refused);
    }

    /**
     * Normalise a received list, dropping anything this site will not hold.
     *
     * @param  array<int|string, mixed>  $values
     * @param  list<string>  $refused  collects what was dropped, for the reply
     * @return list<string>
     */
    private function sanitise(array $values, string $kind, array &$refused): array
    {
        $out = [];

        foreach ($values as $value) {
            $value = strtolower(trim((string) $value));

            if ($value === '' || in_array($value, $out, true)) {
                continue;
            }

            if (! $this->isName($value, $kind) || count($out) >= self::PUSHED_MAX) {
                $refused[] = $value;

                continue;
            }

            $out[] = $value;
        }

        return $out;
    }

    /**
     * Is this a bare name rather than something path-shaped?
     *
     * A plugin slug reaches the filesystem (WP_PLUGIN_DIR.'/'.$slug), so it is
     * held to one strict pattern — no separators, no dots, nothing that could
     * climb out of the plugins directory even before removeDirectory() refuses
     * it. A login never touches a path, so it only has to be a login.
     */
    private function isName(string $value, string $kind): bool
    {
        if ($kind === 'plugin') {
            return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,98}$/', $value);
        }

        return strlen($value) <= 60 && ! preg_match('/[\s\/\\\\]/', $value);
    }

    /**
     * Remove a quarantined account. Returns the logged action, or null when
     * there was nothing to remove.
     *
     * @return array<string, mixed>|null
     */
    public function purgeUser(string $login): ?array
    {
        $source = $this->userSource($login);

        if ($source === '') {
            return null; // Not on the list — this method has no other vocabulary.
        }

        $user = get_user_by('login', $login);

        if (! $user instanceof WP_User) {
            return null;
        }

        require_once ABSPATH.'wp-admin/includes/user.php';

        $userId = (int) $user->ID;

        // A name that arrived over the network never deletes the install's first
        // account. On nearly every WordPress site that is the owner, and it is
        // the single account whose loss cannot be undone from here.
        if ($source === 'panel' && in_array($userId, self::NEVER_USER_IDS, true)) {
            return $this->log('user', $login, 'skipped', sprintf(
                'המשתמש #%d הוא החשבון הראשון באתר — כלל שהגיע מהפאנל אינו מוחק אותו. אם זה אכן חשבון של פורץ, יש לטפל ידנית.',
                $userId,
            ), $source);
        }

        $reassignTo = $this->oldestOtherAdministrator($userId);
        $isAdmin = in_array('administrator', (array) $user->roles, true);

        // The one case where removing the account is the worse outcome: it is
        // the only administrator left, and deleting it leaves nobody who can
        // log in and fix anything. Report it and stop.
        if ($isAdmin && $reassignTo === null) {
            return $this->log('user', $login, 'skipped', sprintf(
                'המשתמש #%d הוא מנהל האתר היחיד — מחיקתו הייתה נועלת את הבעלים בחוץ. נדרש טיפול ידני מיידי.',
                $userId,
            ), $source);
        }

        $deleted = wp_delete_user($userId, $reassignTo);

        if (! $deleted) {
            return $this->log('user', $login, 'failed', "מחיקת המשתמש #{$userId} נכשלה.", $source);
        }

        $roles = implode(', ', (array) $user->roles);

        return $this->log('user', $login, 'removed', sprintf(
            'משתמש #%d (%s) נמחק. תוכן שהיה שלו הועבר למנהל #%s לבדיקה.',
            $userId,
            $isAdmin ? 'מנהל' : ($roles !== '' ? $roles : 'ללא תפקיד'),
            $reassignTo === null ? '—' : $reassignTo,
        ), $source);
    }

    /**
     * Deactivate and delete a quarantined plugin. Returns the logged action, or
     * null when it is not installed.
     *
     * @return array<string, mixed>|null
     */
    public function purgePlugin(string $slug): ?array
    {
        $source = $this->pluginSource($slug);

        if ($source === '') {
            return null;
        }

        // Refused however it was asked for. An order to remove the agent would
        // take the site off the monitoring that would have reported whatever
        // came next — so this is the one removal that is never an improvement.
        if (in_array($slug, self::NEVER_PLUGINS, true)) {
            return $this->log('plugin', $slug, 'skipped',
                'התוסף הזה הוא סוכן הניטור עצמו. הסרתו הייתה מנתקת את האתר מהפאנל, ולכן היא נדחית תמיד.',
                $source);
        }

        $directory = WP_PLUGIN_DIR.'/'.$slug;

        // The cheap answer first. The sweep runs on ordinary front-end requests,
        // and on the overwhelmingly common "nothing is there" it must cost one
        // stat call rather than pulling two wp-admin includes and building the
        // whole plugin list.
        if (! is_dir($directory)) {
            return null;
        }

        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/file.php';

        $files = [];

        foreach (array_keys(get_plugins()) as $file) {
            if ($this->slugOf((string) $file) === $slug) {
                $files[] = (string) $file;
            }
        }

        if ($files !== []) {
            // silent: true skips the plugin's own deactivation hooks. A plugin
            // being removed as a threat is the last code that should get to run
            // arbitrary logic on its way out.
            deactivate_plugins($files, true);
        }

        $deleted = $files !== [] ? delete_plugins($files) : false;

        if ($deleted === true) {
            return $this->log('plugin', $slug, 'removed', 'התוסף כובה ונמחק מהשרת.', $source);
        }

        // delete_plugins() needs a writable filesystem and only knows about
        // plugins with a header. Files dropped by hand have neither, so the
        // directory is removed directly — bounded to this hard-coded slug
        // inside the plugins directory, never to a path anybody passed in.
        if (is_dir($directory) && $this->removeDirectory($directory)) {
            return $this->log('plugin', $slug, 'removed', 'התוסף כובה ותיקיית הקבצים שלו נמחקה.', $source);
        }

        return $this->log('plugin', $slug, $files !== [] ? 'deactivated' : 'failed', is_dir($directory)
            ? 'התוסף כובה אך מחיקת הקבצים נכשלה (הרשאות כתיבה) — הקבצים עדיין על השרת ויש להסירם ידנית.'
            : 'לא ניתן היה להסיר את התוסף.', $source);
    }

    /**
     * What is on the list, what is present right now, and what was done about
     * it — the answer the panel reads.
     *
     * @return array<string, mixed>
     */
    public function status(int $afterId = 0): array
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';

        $usersPresent = [];

        foreach ($this->watchedUsers() as $login) {
            if (get_user_by('login', $login) instanceof WP_User) {
                $usersPresent[] = $login;
            }
        }

        $pluginsPresent = [];

        foreach ($this->watchedPlugins() as $slug) {
            if (is_dir(WP_PLUGIN_DIR.'/'.$slug)) {
                $pluginsPresent[] = $slug;
            }
        }

        return [
            'version' => MULTIOTO_AGENT_VERSION,
            'quarantine' => ['users' => self::USERS, 'plugins' => self::PLUGINS],
            // What this site is holding on the panel's behalf, read back from the
            // option rather than echoed from the request: the panel compares this
            // with what it meant to send, so a push that silently did not land —
            // or a list an intruder edited in the database — is visible there
            // instead of being assumed.
            'panel_rules' => ['users' => $this->pushedList('users'), 'plugins' => $this->pushedList('plugins')],
            'panel_limits' => ['max' => self::PUSHED_MAX, 'per_sweep' => self::PUSHED_PER_SWEEP, 'never_plugins' => self::NEVER_PLUGINS],
            // Anything still here after the sweep needs a person: it means the
            // removal was refused (last administrator) or could not be written.
            'present' => ['users' => $usersPresent, 'plugins' => $pluginsPresent],
            'actions' => $this->entriesAfter($afterId),
            'last_id' => $this->lastId(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function entriesAfter(int $afterId): array
    {
        $out = [];

        foreach ((array) get_option(self::LOG_OPTION, []) as $entry) {
            if (is_array($entry) && (int) ($entry['id'] ?? 0) > $afterId) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private function lastId(): int
    {
        return (int) get_option(self::SEQ_OPTION, 0);
    }

    /**
     * Record one action. Entries carry an incrementing id so the panel drains
     * by "everything after what I already have" — nothing is deleted on read,
     * so a panel that crashes mid-processing loses no evidence.
     *
     * @return array<string, mixed>
     */
    private function log(string $kind, string $target, string $result, string $detail, string $source = 'builtin'): array
    {
        $id = $this->lastId() + 1;

        $entry = [
            'id' => $id,
            'at' => gmdate('c'),
            'kind' => $kind,
            'target' => $target,
            'result' => $result,
            'detail' => $detail,
            // Which list authorised this. "The site decided by itself" and "the
            // panel told it to" are different facts, and only one of them can be
            // changed by somebody who takes over the panel.
            'source' => $source === 'panel' ? 'panel' : 'builtin',
        ];

        $entries = array_values(array_filter((array) get_option(self::LOG_OPTION, []), 'is_array'));
        $entries[] = $entry;

        if (count($entries) > self::LOG_MAX) {
            $entries = array_slice($entries, -self::LOG_MAX);
        }

        update_option(self::SEQ_OPTION, $id, false);
        update_option(self::LOG_OPTION, $entries, false);

        // Also to the site's own error log: if this plugin is the next thing
        // the intruder removes, the trail survives outside its options.
        error_log(sprintf('[multioto-agent] guard %s %s (%s): %s — %s', $kind, $target, $entry['source'], $result, $detail));

        return $entry;
    }

    private function isQuarantinedLogin(string $login): bool
    {
        return $this->userSource($login) !== '';
    }

    /** The plugin folder from a plugin file ("a/b.php" → "a", "b.php" → "b"). */
    private function slugOf(string $file): string
    {
        $file = trim($file);

        if ($file === '') {
            return '';
        }

        return strpos($file, '/') !== false
            ? strtolower(explode('/', $file)[0])
            : strtolower((string) preg_replace('/\.php$/i', '', $file));
    }

    /**
     * The administrator a removed user's content is handed to: the oldest one
     * that is not the user being removed. Null when there is no other.
     */
    private function oldestOtherAdministrator(int $excludeId): ?int
    {
        $admins = get_users([
            'role' => 'administrator',
            'exclude' => [$excludeId],
            'orderby' => 'ID',
            'order' => 'ASC',
            'number' => 1,
            'fields' => 'ID',
        ]);

        return $admins === [] ? null : (int) $admins[0];
    }

    /** Remove a directory tree through the WordPress filesystem API. */
    private function removeDirectory(string $directory): bool
    {
        global $wp_filesystem;

        if (! WP_Filesystem()) {
            return false;
        }

        // Refuse anything that is not directly inside the plugins directory,
        // however this method came to be called.
        $parent = trailingslashit(wp_normalize_path(WP_PLUGIN_DIR));
        $target = wp_normalize_path($directory);

        if (strpos($target, $parent) !== 0 || trim(substr($target, strlen($parent)), '/') === '') {
            return false;
        }

        return (bool) $wp_filesystem->delete($target, true);
    }
}
