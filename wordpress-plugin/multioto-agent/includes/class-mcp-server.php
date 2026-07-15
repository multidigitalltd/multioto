<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A minimal MCP (Model Context Protocol) server over the WordPress REST API.
 * The Multi Digital platform is the only client; it authenticates with the
 * per-site shared secret and may call ONLY the fixed, allow-listed tools below.
 *
 * There is deliberately NO arbitrary PHP/SQL/file execution, and no way to read
 * arbitrary options (which could hold third-party secrets) — every tool is an
 * explicit, hand-written, least-privilege operation.
 */
class Multioto_Agent_Mcp_Server
{
    private const PROTOCOL = '2025-06-18';

    /** Options that are safe to read remotely (no secrets, no PII). */
    private const READABLE_OPTIONS = [
        'blogname', 'blogdescription', 'siteurl', 'home', 'template',
        'stylesheet', 'WPLANG', 'timezone_string', 'gmt_offset',
        'default_role', 'users_can_register', 'blog_public',
    ];

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('md-agent/v1', '/mcp', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'authorize'],
        ]);
    }

    /** Authenticate the caller by the shared secret (constant-time compare). */
    public function authorize(WP_REST_Request $request): bool
    {
        $secret = Multioto_Agent_Settings::get()['mcp_secret'];

        if ($secret === '') {
            return false; // Not connected yet — refuse everything.
        }

        return hash_equals($secret, $this->presentedSecret($request));
    }

    /** The bearer token presented, tolerant of servers that strip Authorization. */
    private function presentedSecret(WP_REST_Request $request): string
    {
        $auth = (string) $request->get_header('authorization');

        if ($auth === '' && ! empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }

        // Fallback header for hosts that drop Authorization entirely.
        return (string) $request->get_header('x-md-agent-secret');
    }

    /** JSON-RPC 2.0 dispatch. */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        $id = $body['id'] ?? null;
        $method = (string) ($body['method'] ?? '');
        $params = (array) ($body['params'] ?? []);

        // Notifications (no id) get an empty acknowledgement.
        if ($id === null) {
            return new WP_REST_Response(null, 202);
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'tools/list' => ['tools' => $this->toolDefinitions()],
                'tools/call' => $this->callTool((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? [])),
                default => throw new Multioto_Agent_Rpc_Error(-32601, "Method not found: {$method}"),
            };
        } catch (Multioto_Agent_Rpc_Error $e) {
            return $this->rpc($id, null, ['code' => $e->getCode(), 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->rpc($id, null, ['code' => -32603, 'message' => 'Internal error']);
        }

        return $this->rpc($id, $result);
    }

    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL,
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => 'multioto-agent', 'version' => MULTIOTO_AGENT_VERSION],
        ];
    }

    /**
     * The fixed tool catalog. Each tool carries MCP behaviour annotations
     * (readOnlyHint / destructiveHint) — the machine-verifiable signal the
     * platform trusts to classify risk, so a tool's NAME is never used as a
     * security control.
     */
    private function toolDefinitions(): array
    {
        $read = ['readOnlyHint' => true, 'destructiveHint' => false];
        $change = ['readOnlyHint' => false, 'destructiveHint' => false];

        return [
            ['name' => 'wp_health', 'description' => 'סקירת בריאות האתר: גרסאות, SSL, תוספים פעילים.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_plugin_list', 'description' => 'רשימת התוספים המותקנים והאם יש עדכון.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_option_get', 'description' => 'קריאת הגדרה בטוחה מרשימה מוגדרת מראש.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]],
            ['name' => 'wp_error_log_tail', 'description' => 'שורות אחרונות מיומן השגיאות (אם מופעל).', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['lines' => ['type' => 'integer']]]],
            ['name' => 'wp_cache_flush', 'description' => 'ניקוי מטמון אובייקטים ו-OPcache.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_plugin_update', 'description' => 'עדכון תוסף לגרסה האחרונה לפי slug.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']], 'required' => ['plugin']]],
            ['name' => 'wp_plugin_activate', 'description' => 'הפעלת תוסף לפי קובץ.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']], 'required' => ['plugin']]],
            ['name' => 'wp_plugin_deactivate', 'description' => 'כיבוי תוסף לפי קובץ.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']], 'required' => ['plugin']]],
        ];
    }

    /** Execute an allow-listed tool. Unknown names are rejected. */
    private function callTool(string $name, array $args): array
    {
        $text = match ($name) {
            'wp_health' => $this->health(),
            'wp_plugin_list' => $this->pluginList(),
            'wp_option_get' => $this->optionGet($args),
            'wp_error_log_tail' => $this->errorLogTail($args),
            'wp_cache_flush' => $this->cacheFlush(),
            'wp_plugin_update' => $this->pluginUpdate($args),
            'wp_plugin_activate' => $this->setPluginState($args, true),
            'wp_plugin_deactivate' => $this->setPluginState($args, false),
            default => throw new Multioto_Agent_Rpc_Error(-32602, "Unknown tool: {$name}"),
        };

        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    // --- Tools ---------------------------------------------------------------

    private function health(): string
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $data = [
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'is_ssl' => is_ssl(),
            'home' => home_url(),
            'active_theme' => wp_get_theme()->get('Name'),
            'active_plugins' => count((array) get_option('active_plugins', [])),
            'total_plugins' => count(get_plugins()),
        ];

        return wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function pluginList(): string
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        $active = (array) get_option('active_plugins', []);
        $out = [];

        foreach (get_plugins() as $file => $meta) {
            $out[] = [
                'plugin' => $file,
                'name' => $meta['Name'] ?? $file,
                'version' => $meta['Version'] ?? '',
                'active' => in_array($file, $active, true),
                'update_available' => isset($updates->response[$file]),
            ];
        }

        return wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function optionGet(array $args): string
    {
        $name = sanitize_key((string) ($args['name'] ?? ''));

        if (! in_array($name, self::READABLE_OPTIONS, true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, "Option '{$name}' is not readable.");
        }

        return wp_json_encode([$name => get_option($name)], JSON_UNESCAPED_UNICODE);
    }

    private function errorLogTail(array $args): string
    {
        $lines = min(200, max(10, (int) ($args['lines'] ?? 100)));
        $path = ini_get('error_log') ?: WP_CONTENT_DIR.'/debug.log';

        if (! is_string($path) || $path === '' || ! @is_readable($path)) {
            return '(אין יומן שגיאות זמין)';
        }

        $content = @file($path, FILE_IGNORE_NEW_LINES);

        if ($content === false) {
            return '(לא ניתן לקרוא את היומן)';
        }

        return implode("\n", array_slice($content, -$lines));
    }

    private function cacheFlush(): string
    {
        wp_cache_flush();

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return 'המטמון נוקה.';
    }

    private function pluginUpdate(array $args): string
    {
        $plugin = $this->normalizePluginFile((string) ($args['plugin'] ?? ''));

        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';

        wp_update_plugins();

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin);
        $result = $upgrader->upgrade($plugin);

        if (is_wp_error($result)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $result->get_error_message());
        }

        if ($result === false || $result === null) {
            return "לא נמצא עדכון עבור {$plugin} (ייתכן שהוא כבר מעודכן).";
        }

        return "התוסף {$plugin} עודכן.";
    }

    private function setPluginState(array $args, bool $activate): string
    {
        $plugin = $this->normalizePluginFile((string) ($args['plugin'] ?? ''));

        require_once ABSPATH.'wp-admin/includes/plugin.php';

        if ($activate) {
            $result = activate_plugin($plugin);

            if (is_wp_error($result)) {
                throw new Multioto_Agent_Rpc_Error(-32000, $result->get_error_message());
            }

            return "התוסף {$plugin} הופעל.";
        }

        deactivate_plugins($plugin);

        return "התוסף {$plugin} כובה.";
    }

    /**
     * Validate a plugin file identifier and confirm it is actually installed —
     * so a caller can only act on real, existing plugins, never traverse paths.
     */
    private function normalizePluginFile(string $plugin): string
    {
        $plugin = ltrim(str_replace(['\\', "\0"], '', $plugin), '/');

        if ($plugin === '' || strpos($plugin, '..') !== false) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'Invalid plugin identifier.');
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $installed = array_keys(get_plugins());

        // Accept either the full "dir/file.php" or a bare slug that matches one.
        if (in_array($plugin, $installed, true)) {
            return $plugin;
        }

        foreach ($installed as $file) {
            if (strpos($file, $plugin.'/') === 0 || $file === $plugin.'.php') {
                return $file;
            }
        }

        throw new Multioto_Agent_Rpc_Error(-32602, "Plugin '{$plugin}' is not installed.");
    }

    // --- JSON-RPC framing ----------------------------------------------------

    private function rpc($id, ?array $result, ?array $error = null): WP_REST_Response
    {
        $payload = ['jsonrpc' => '2.0', 'id' => $id];

        if ($error !== null) {
            $payload['error'] = $error;
        } else {
            $payload['result'] = $result;
        }

        return new WP_REST_Response($payload, 200);
    }
}

/** A typed JSON-RPC error carrying a protocol code. */
class Multioto_Agent_Rpc_Error extends \Exception {}
