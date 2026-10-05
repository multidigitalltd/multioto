<?php

/**
 * Just enough WordPress to RUN the companion plugin's guard in a test.
 *
 * The guard is the one file in this repository that deletes a customer's data,
 * and from 1.7.0 part of what it deletes is named over the network. Asserting on
 * its source as text — "the file mentions the never-list" — is the kind of test
 * that passes while the check it describes sits behind a condition that is never
 * reached. The rails are worth executing.
 *
 * Only what the guard actually calls is defined here, and each stub is the
 * simplest thing that behaves like the real one for these tests. State lives in
 * $GLOBALS so a test can arrange a site and read back what was done to it.
 *
 * Loaded once per process: constants and functions cannot be declared twice.
 */
if (! defined('MULTIOTO_GUARD_STUBS')) {
    define('MULTIOTO_GUARD_STUBS', true);

    $root = sys_get_temp_dir().'/multioto-guard-'.bin2hex(random_bytes(4));

    // The guard require_once's these on the paths WordPress puts them on. They
    // only have to exist; everything inside them is stubbed below.
    @mkdir($root.'/wp-admin/includes', 0777, true);
    @mkdir($root.'/plugins', 0777, true);

    foreach (['user.php', 'plugin.php', 'file.php'] as $file) {
        file_put_contents($root.'/wp-admin/includes/'.$file, "<?php\n");
    }

    // The guard writes every action to the site's error log too. In a test run
    // that is forty lines of noise over the result, so it goes to a file.
    ini_set('error_log', $root.'/php-error.log');

    define('ABSPATH', $root.'/');
    define('WP_PLUGIN_DIR', $root.'/plugins');
    define('MULTIOTO_AGENT_VERSION', '1.7.0-test');

    /** Reset every stubbed surface to an empty site. */
    function multioto_guard_reset(): void
    {
        $GLOBALS['mg_options'] = [];
        $GLOBALS['mg_users'] = [];        // list of WP_User
        $GLOBALS['mg_deleted'] = [];      // user ids wp_delete_user() was called for
        $GLOBALS['mg_plugins'] = [];      // plugin file => header
        $GLOBALS['mg_deactivated'] = [];
        $GLOBALS['mg_delete_plugins_ok'] = true;
        $GLOBALS['mg_transients'] = [];

        foreach (glob(WP_PLUGIN_DIR.'/*') ?: [] as $path) {
            is_dir($path) ? multioto_guard_rmdir($path) : @unlink($path);
        }
    }

    function multioto_guard_rmdir(string $directory): bool
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            is_dir($path) ? multioto_guard_rmdir($path) : @unlink($path);
        }

        return @rmdir($directory);
    }

    class WP_User
    {
        public $ID;

        public $user_login;

        public $roles;

        public function __construct(int $id, string $login, array $roles = ['subscriber'])
        {
            $this->ID = $id;
            $this->user_login = $login;
            $this->roles = $roles;
        }
    }

    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['mg_options']) ? $GLOBALS['mg_options'][$name] : $default;
    }

    function update_option($name, $value, $autoload = null): bool
    {
        $GLOBALS['mg_options'][$name] = $value;

        return true;
    }

    function get_transient($name)
    {
        return $GLOBALS['mg_transients'][$name] ?? false;
    }

    function set_transient($name, $value, $ttl = 0): bool
    {
        $GLOBALS['mg_transients'][$name] = $value;

        return true;
    }

    function add_action($hook, $callback, $priority = 10, $args = 1): bool
    {
        return true;
    }

    function get_user_by($field, $value)
    {
        foreach ($GLOBALS['mg_users'] as $user) {
            if ($field === 'id' && (int) $user->ID === (int) $value) {
                return $user;
            }

            if ($field === 'login' && strtolower($user->user_login) === strtolower((string) $value)) {
                return $user;
            }
        }

        return false;
    }

    /** Administrators by id, ascending — enough for oldestOtherAdministrator(). */
    function get_users($args = [])
    {
        $exclude = array_map('intval', (array) ($args['exclude'] ?? []));
        $ids = [];

        foreach ($GLOBALS['mg_users'] as $user) {
            if (in_array('administrator', (array) $user->roles, true) && ! in_array((int) $user->ID, $exclude, true)) {
                $ids[] = (int) $user->ID;
            }
        }

        sort($ids);

        return array_slice($ids, 0, max(1, (int) ($args['number'] ?? 1)));
    }

    function wp_delete_user($id, $reassign = null): bool
    {
        $GLOBALS['mg_deleted'][] = ['id' => (int) $id, 'reassign' => $reassign];

        $GLOBALS['mg_users'] = array_values(array_filter(
            $GLOBALS['mg_users'],
            static fn (WP_User $user): bool => (int) $user->ID !== (int) $id,
        ));

        return true;
    }

    function get_plugins()
    {
        return $GLOBALS['mg_plugins'];
    }

    function deactivate_plugins($files, $silent = false): void
    {
        foreach ((array) $files as $file) {
            $GLOBALS['mg_deactivated'][] = $file;
        }
    }

    function delete_plugins($files)
    {
        if (! $GLOBALS['mg_delete_plugins_ok']) {
            return false;
        }

        foreach ((array) $files as $file) {
            $slug = strpos($file, '/') !== false ? explode('/', $file)[0] : $file;
            multioto_guard_rmdir(WP_PLUGIN_DIR.'/'.$slug);
            unset($GLOBALS['mg_plugins'][$file]);
        }

        return true;
    }

    function trailingslashit($value)
    {
        return rtrim((string) $value, '/\\').'/';
    }

    function wp_normalize_path($path)
    {
        return str_replace('\\', '/', (string) $path);
    }

    /** The filesystem fallback path, used when delete_plugins() refuses. */
    function WP_Filesystem(): bool
    {
        $GLOBALS['wp_filesystem'] = new class
        {
            public function delete($path, $recursive = false)
            {
                return multioto_guard_rmdir((string) $path);
            }
        };

        return true;
    }

    multioto_guard_reset();

    require_once __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-guard.php';
}
