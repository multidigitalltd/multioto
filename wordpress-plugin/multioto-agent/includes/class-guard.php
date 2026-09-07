<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The two intrusions we do not wait to discuss.
 *
 * An administrator called `sys_maint` and the wp-file-manager plugin are not
 * ambiguous findings — they are the pair a compromised WordPress site gets
 * within minutes of being taken: a second owner nobody created, and a browser
 * file manager to drop a shell with. Both are removed here the moment they
 * appear, without asking anyone, because the window between "detected" and
 * "approved by a human" is the window the intruder is inside the site.
 *
 * Three things make that safe to do automatically:
 *
 * The list is HARD-CODED, not passed in. Nothing the platform sends can widen
 * it, so even a fully compromised panel cannot use this to delete an
 * administrator or a plugin of its choosing — it can only ask for these two.
 *
 * Nothing is deleted blind. A user's content is reassigned to the oldest
 * remaining administrator rather than destroyed, and the last administrator on
 * a site is never deleted at all: locking the owner out is a worse outcome than
 * the account we were trying to remove, so that case is reported for a person
 * instead.
 *
 * And every action is written to a log the panel drains, so a removal that
 * happened while nobody was watching still reaches the team.
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

        if ($slug !== '' && in_array($slug, self::PLUGINS, true)) {
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

        foreach (self::USERS as $login) {
            $action = $this->purgeUser($login);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return array_merge($actions, $this->sweepPlugins());
    }

    /** @return list<array<string, mixed>> */
    private function sweepPlugins(): array
    {
        $actions = [];

        foreach (self::PLUGINS as $slug) {
            $action = $this->purgePlugin($slug);

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * Remove a quarantined account. Returns the logged action, or null when
     * there was nothing to remove.
     *
     * @return array<string, mixed>|null
     */
    public function purgeUser(string $login): ?array
    {
        if (! $this->isQuarantinedLogin($login)) {
            return null; // Not on the list — this method has no other vocabulary.
        }

        $user = get_user_by('login', $login);

        if (! $user instanceof WP_User) {
            return null;
        }

        require_once ABSPATH.'wp-admin/includes/user.php';

        $reassignTo = $this->oldestOtherAdministrator((int) $user->ID);
        $isAdmin = in_array('administrator', (array) $user->roles, true);

        // The one case where removing the account is the worse outcome: it is
        // the only administrator left, and deleting it leaves nobody who can
        // log in and fix anything. Report it and stop.
        if ($isAdmin && $reassignTo === null) {
            return $this->log('user', $login, 'skipped', sprintf(
                'המשתמש #%d הוא מנהל האתר היחיד — מחיקתו הייתה נועלת את הבעלים בחוץ. נדרש טיפול ידני מיידי.',
                $user->ID,
            ));
        }

        $userId = (int) $user->ID;
        $deleted = wp_delete_user($userId, $reassignTo);

        if (! $deleted) {
            return $this->log('user', $login, 'failed', "מחיקת המשתמש #{$userId} נכשלה.");
        }

        $roles = implode(', ', (array) $user->roles);

        return $this->log('user', $login, 'removed', sprintf(
            'משתמש #%d (%s) נמחק. תוכן שהיה שלו הועבר למנהל #%s לבדיקה.',
            $userId,
            $isAdmin ? 'מנהל' : ($roles !== '' ? $roles : 'ללא תפקיד'),
            $reassignTo === null ? '—' : $reassignTo,
        ));
    }

    /**
     * Deactivate and delete a quarantined plugin. Returns the logged action, or
     * null when it is not installed.
     *
     * @return array<string, mixed>|null
     */
    public function purgePlugin(string $slug): ?array
    {
        if (! in_array($slug, self::PLUGINS, true)) {
            return null;
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
            return $this->log('plugin', $slug, 'removed', 'התוסף כובה ונמחק מהשרת.');
        }

        // delete_plugins() needs a writable filesystem and only knows about
        // plugins with a header. Files dropped by hand have neither, so the
        // directory is removed directly — bounded to this hard-coded slug
        // inside the plugins directory, never to a path anybody passed in.
        if (is_dir($directory) && $this->removeDirectory($directory)) {
            return $this->log('plugin', $slug, 'removed', 'התוסף כובה ותיקיית הקבצים שלו נמחקה.');
        }

        return $this->log('plugin', $slug, $files !== [] ? 'deactivated' : 'failed', is_dir($directory)
            ? 'התוסף כובה אך מחיקת הקבצים נכשלה (הרשאות כתיבה) — הקבצים עדיין על השרת ויש להסירם ידנית.'
            : 'לא ניתן היה להסיר את התוסף.');
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

        foreach (self::USERS as $login) {
            if (get_user_by('login', $login) instanceof WP_User) {
                $usersPresent[] = $login;
            }
        }

        $pluginsPresent = [];

        foreach (self::PLUGINS as $slug) {
            if (is_dir(WP_PLUGIN_DIR.'/'.$slug)) {
                $pluginsPresent[] = $slug;
            }
        }

        return [
            'version' => MULTIOTO_AGENT_VERSION,
            'quarantine' => ['users' => self::USERS, 'plugins' => self::PLUGINS],
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
    private function log(string $kind, string $target, string $result, string $detail): array
    {
        $id = $this->lastId() + 1;

        $entry = [
            'id' => $id,
            'at' => gmdate('c'),
            'kind' => $kind,
            'target' => $target,
            'result' => $result,
            'detail' => $detail,
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
        error_log(sprintf('[multioto-agent] guard %s %s: %s — %s', $kind, $target, $result, $detail));

        return $entry;
    }

    private function isQuarantinedLogin(string $login): bool
    {
        return in_array(strtolower(trim($login)), self::USERS, true);
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
