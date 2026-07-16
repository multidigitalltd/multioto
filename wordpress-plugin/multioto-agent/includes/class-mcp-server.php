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
            ['name' => 'wp_menu_list', 'description' => 'רשימת תפריטי הניווט באתר והפריטים בכל תפריט (מזהה פריט, טקסט, קישור, הורה, סדר).', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_menu_item_add', 'description' => 'הוספת פריט לתפריט. menu = שם או מזהה התפריט, title = טקסט הפריט, ואחד מ: url (קישור חופשי) או page_id (עמוד קיים). אופציונלי: parent_id (פריט הורה), position (מיקום).', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['menu' => ['type' => 'string'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string'], 'page_id' => ['type' => 'integer'], 'parent_id' => ['type' => 'integer'], 'position' => ['type' => 'integer']], 'required' => ['menu', 'title']]],
            ['name' => 'wp_menu_item_update', 'description' => 'עדכון פריט קיים בתפריט לפי item_id. אפשר לשנות title, url, parent_id, position (כל שדה אופציונלי).', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string'], 'parent_id' => ['type' => 'integer'], 'position' => ['type' => 'integer']], 'required' => ['item_id']]],
            ['name' => 'wp_menu_item_unlink', 'description' => 'הסרת פריט מהתפריט לפי item_id — מסיר רק את הקישור מהתפריט; העמוד/הפוסט עצמו נשאר.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer']], 'required' => ['item_id']]],
            ['name' => 'wp_content_list', 'description' => 'רשימת עמודים/פוסטים. type = page או post (ברירת מחדל page), status (any/publish/draft), search (טקסט חיפוש), limit.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'status' => ['type' => 'string'], 'search' => ['type' => 'string'], 'limit' => ['type' => 'integer']]]],
            ['name' => 'wp_content_get', 'description' => 'קריאת עמוד/פוסט מלא לפי id: כותרת, תוכן, סטטוס, סוג.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
            ['name' => 'wp_content_create', 'description' => 'יצירת עמוד/פוסט. title, content (HTML), type = page או post, status = draft או publish. אופציונלי: excerpt.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'type' => ['type' => 'string'], 'status' => ['type' => 'string'], 'excerpt' => ['type' => 'string']], 'required' => ['title']]],
            ['name' => 'wp_content_update', 'description' => 'עדכון עמוד/פוסט קיים לפי id: title, content, status, excerpt (כל שדה אופציונלי; מה שלא צוין נשמר).', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string'], 'excerpt' => ['type' => 'string']], 'required' => ['id']]],
            ['name' => 'wp_content_trash', 'description' => 'העברת עמוד/פוסט לפח לפי id (הפיך — ניתן לשחזר מהפח).', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
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
            'wp_menu_list' => $this->menuList(),
            'wp_menu_item_add' => $this->menuItemAdd($args),
            'wp_menu_item_update' => $this->menuItemUpdate($args),
            'wp_menu_item_unlink' => $this->menuItemUnlink($args),
            'wp_content_list' => $this->contentList($args),
            'wp_content_get' => $this->contentGet($args),
            'wp_content_create' => $this->contentCreate($args),
            'wp_content_update' => $this->contentUpdate($args),
            'wp_content_trash' => $this->contentTrash($args),
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

    // --- Navigation menus ----------------------------------------------------

    private function menuList(): string
    {
        $out = [];

        foreach (wp_get_nav_menus() as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id) ?: [];

            $out[] = [
                'menu' => $menu->name,
                'menu_id' => (int) $menu->term_id,
                'items' => array_map(static fn ($item): array => [
                    'item_id' => (int) $item->ID,
                    'title' => $item->title,
                    'url' => $item->url,
                    'parent_id' => (int) $item->menu_item_parent,
                    'order' => (int) $item->menu_order,
                ], $items),
            ];
        }

        return wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function menuItemAdd(array $args): string
    {
        $menuId = $this->resolveMenuId((string) ($args['menu'] ?? ''));
        $title = sanitize_text_field((string) ($args['title'] ?? ''));

        if ($title === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר טקסט לפריט (title).');
        }

        $data = ['menu-item-title' => $title, 'menu-item-status' => 'publish'];

        if (! empty($args['page_id'])) {
            // Link to an existing page.
            $pageId = (int) $args['page_id'];

            if (get_post_status($pageId) === false) {
                throw new Multioto_Agent_Rpc_Error(-32602, "העמוד {$pageId} לא נמצא.");
            }

            $data['menu-item-type'] = 'post_type';
            $data['menu-item-object'] = get_post_type($pageId) ?: 'page';
            $data['menu-item-object-id'] = $pageId;
        } else {
            $data['menu-item-url'] = esc_url_raw((string) ($args['url'] ?? ''));

            if ($data['menu-item-url'] === '') {
                throw new Multioto_Agent_Rpc_Error(-32602, 'יש לציין url או page_id.');
            }
        }

        if (! empty($args['parent_id'])) {
            $data['menu-item-parent-id'] = (int) $args['parent_id'];
        }
        if (isset($args['position'])) {
            $data['menu-item-position'] = (int) $args['position'];
        }

        $id = wp_update_nav_menu_item($menuId, 0, $data);

        if (is_wp_error($id)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $id->get_error_message());
        }

        return wp_json_encode(['added_item_id' => (int) $id, 'menu_id' => $menuId], JSON_UNESCAPED_UNICODE);
    }

    private function menuItemUpdate(array $args): string
    {
        $post = $this->navMenuItem((int) ($args['item_id'] ?? 0));

        // Hydrate the nav properties (url, object, object_id, type, parent) — the
        // raw post has none of them, so reading from it would blank a custom-link
        // URL or corrupt a page item's object reference on a partial update.
        $item = wp_setup_nav_menu_item($post);
        $menuId = $this->menuIdOfItem($item->ID);

        // Merge onto the item's current values so an unspecified field is kept,
        // not blanked out by a partial update.
        $data = [
            'menu-item-title' => isset($args['title']) ? sanitize_text_field((string) $args['title']) : $item->title,
            'menu-item-url' => isset($args['url']) ? esc_url_raw((string) $args['url']) : $item->url,
            'menu-item-object-id' => (int) $item->object_id,
            'menu-item-object' => $item->object,
            'menu-item-type' => $item->type,
            'menu-item-parent-id' => isset($args['parent_id']) ? (int) $args['parent_id'] : (int) $item->menu_item_parent,
            'menu-item-position' => isset($args['position']) ? (int) $args['position'] : (int) $item->menu_order,
            'menu-item-status' => 'publish',
        ];

        $id = wp_update_nav_menu_item($menuId, $item->ID, $data);

        if (is_wp_error($id)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $id->get_error_message());
        }

        return wp_json_encode(['updated_item_id' => (int) $item->ID], JSON_UNESCAPED_UNICODE);
    }

    private function menuItemUnlink(array $args): string
    {
        $item = $this->navMenuItem((int) ($args['item_id'] ?? 0));
        $itemId = (int) $item->ID;

        // Before deleting a parent, re-parent its children to this item's own
        // parent — otherwise they'd keep pointing at a nonexistent parent and the
        // menu hierarchy would break. WordPress doesn't do this for us.
        $grandparentId = (int) get_post_meta($itemId, '_menu_item_menu_item_parent', true);

        foreach ($this->childMenuItemIds($itemId) as $childId) {
            update_post_meta($childId, '_menu_item_menu_item_parent', $grandparentId);
        }

        // Deletes the nav_menu_item pointer only — the page/post it linked to is
        // untouched, so this is reversible by re-adding the item.
        if (! wp_delete_post($itemId, true)) {
            throw new Multioto_Agent_Rpc_Error(-32000, "לא ניתן להסיר את פריט התפריט {$itemId}.");
        }

        return wp_json_encode(['unlinked_item_id' => $itemId], JSON_UNESCAPED_UNICODE);
    }

    /**
     * The ids of menu items whose parent is the given item — so a removed parent
     * doesn't orphan its children.
     *
     * @return list<int>
     */
    private function childMenuItemIds(int $parentItemId): array
    {
        $children = get_posts([
            'post_type' => 'nav_menu_item',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => '_menu_item_menu_item_parent',
            'meta_value' => (string) $parentItemId,
        ]);

        return array_map('intval', (array) $children);
    }

    /** Resolve a menu by id, slug or name to its term id (or fail). */
    private function resolveMenuId(string $menu): int
    {
        $object = $menu !== '' ? wp_get_nav_menu_object($menu) : false;

        if (! $object) {
            throw new Multioto_Agent_Rpc_Error(-32602, "התפריט '{$menu}' לא נמצא.");
        }

        return (int) $object->term_id;
    }

    /** Fetch a post and confirm it is a nav menu item (or fail). */
    private function navMenuItem(int $itemId): \WP_Post
    {
        $post = $itemId > 0 ? get_post($itemId) : null;

        if (! $post || $post->post_type !== 'nav_menu_item') {
            throw new Multioto_Agent_Rpc_Error(-32602, "פריט התפריט {$itemId} לא נמצא.");
        }

        return $post;
    }

    /** The menu (term id) a given menu item belongs to. */
    private function menuIdOfItem(int $itemId): int
    {
        $terms = wp_get_object_terms($itemId, 'nav_menu');

        if (is_wp_error($terms) || empty($terms)) {
            throw new Multioto_Agent_Rpc_Error(-32602, "פריט התפריט {$itemId} לא משויך לתפריט.");
        }

        return (int) $terms[0]->term_id;
    }

    // --- Content (pages & posts) ---------------------------------------------

    private function contentList(array $args): string
    {
        $query = new WP_Query([
            'post_type' => $this->contentType($args),
            'post_status' => $this->contentStatus((string) ($args['status'] ?? 'any'), true),
            's' => sanitize_text_field((string) ($args['search'] ?? '')),
            'posts_per_page' => min(100, max(1, (int) ($args['limit'] ?? 30))),
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        $out = array_map(static fn (WP_Post $post): array => [
            'id' => $post->ID,
            'title' => $post->post_title,
            'type' => $post->post_type,
            'status' => $post->post_status,
            'modified' => $post->post_modified_gmt,
            'url' => get_permalink($post),
        ], $query->posts);

        return wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function contentGet(array $args): string
    {
        $post = $this->contentPost((int) ($args['id'] ?? 0));

        return wp_json_encode([
            'id' => $post->ID,
            'title' => $post->post_title,
            'type' => $post->post_type,
            'status' => $post->post_status,
            'excerpt' => $post->post_excerpt,
            'content' => $post->post_content,
            'url' => get_permalink($post),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function contentCreate(array $args): string
    {
        $title = sanitize_text_field((string) ($args['title'] ?? ''));

        if ($title === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסרה כותרת (title).');
        }

        // Content passes through the same sanitiser WordPress uses for the block
        // editor, so allowed HTML is kept and scripts are stripped.
        $id = wp_insert_post([
            'post_title' => $title,
            'post_content' => wp_kses_post((string) ($args['content'] ?? '')),
            'post_excerpt' => sanitize_text_field((string) ($args['excerpt'] ?? '')),
            'post_type' => $this->contentType($args),
            'post_status' => $this->contentStatus((string) ($args['status'] ?? 'draft')),
        ], true);

        if (is_wp_error($id)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $id->get_error_message());
        }

        return wp_json_encode(['created_id' => (int) $id, 'url' => get_permalink((int) $id)], JSON_UNESCAPED_UNICODE);
    }

    private function contentUpdate(array $args): string
    {
        $post = $this->contentPost((int) ($args['id'] ?? 0));

        $data = ['ID' => $post->ID];

        if (isset($args['title'])) {
            $data['post_title'] = sanitize_text_field((string) $args['title']);
        }
        if (isset($args['content'])) {
            $data['post_content'] = wp_kses_post((string) $args['content']);
        }
        if (isset($args['excerpt'])) {
            $data['post_excerpt'] = sanitize_text_field((string) $args['excerpt']);
        }
        if (isset($args['status'])) {
            $data['post_status'] = $this->contentStatus((string) $args['status']);
        }

        if (count($data) === 1) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'לא צוין שום שדה לעדכון.');
        }

        $id = wp_update_post($data, true);

        if (is_wp_error($id)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $id->get_error_message());
        }

        return wp_json_encode(['updated_id' => (int) $post->ID], JSON_UNESCAPED_UNICODE);
    }

    private function contentTrash(array $args): string
    {
        $post = $this->contentPost((int) ($args['id'] ?? 0));

        // Trash, never force-delete — a manager can restore it from the trash.
        if (! wp_trash_post($post->ID)) {
            throw new Multioto_Agent_Rpc_Error(-32000, "לא ניתן להעביר לפח את הפריט {$post->ID}.");
        }

        return wp_json_encode(['trashed_id' => (int) $post->ID], JSON_UNESCAPED_UNICODE);
    }

    /** Resolve the requested content type, restricted to pages and posts. */
    private function contentType(array $args): string
    {
        $type = strtolower(trim((string) ($args['type'] ?? 'page')));

        return in_array($type, ['page', 'post'], true) ? $type : 'page';
    }

    /** Validate a post status; blank/unknown falls back to draft (or any, for a query). */
    private function contentStatus(string $status, bool $forQuery = false): string
    {
        $status = strtolower(trim($status));
        $allowed = $forQuery ? ['any', 'publish', 'draft', 'pending', 'private'] : ['publish', 'draft', 'pending', 'private'];

        return in_array($status, $allowed, true) ? $status : ($forQuery ? 'any' : 'draft');
    }

    /** Fetch a post/page (only those types) by id, or fail. */
    private function contentPost(int $id): WP_Post
    {
        $post = $id > 0 ? get_post($id) : null;

        if (! $post || ! in_array($post->post_type, ['page', 'post'], true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, "העמוד/הפוסט {$id} לא נמצא.");
        }

        return $post;
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
