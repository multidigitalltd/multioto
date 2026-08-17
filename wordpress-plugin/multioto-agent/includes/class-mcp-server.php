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
            // A switch rather than match(): the plugin declares "Requires PHP
            // 7.4", and match() is PHP 8.0 — on an older host the file would not
            // parse at all, taking the whole SITE down, not just the agent.
            switch ($method) {
                case 'initialize':
                    $result = $this->initialize();
                    break;
                case 'tools/list':
                    $result = ['tools' => $this->toolDefinitions()];
                    break;
                case 'tools/call':
                    $result = $this->callTool((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? []));
                    break;
                default:
                    throw new Multioto_Agent_Rpc_Error(-32601, "Method not found: {$method}");
            }
        } catch (Multioto_Agent_Rpc_Error $e) {
            return $this->rpc($id, null, ['code' => $e->getCode(), 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Log the real cause to the site's error log (recoverable from
            // debug.log), and hand the panel a safe detail in `data` instead of a
            // bare "Internal error" — the endpoint is authenticated, so the class
            // + message only reach our own panel, never the public.
            error_log(sprintf(
                '[multioto-agent] %s during %s: %s in %s:%d',
                get_class($e),
                $method,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            return $this->rpc($id, null, [
                'code' => -32603,
                'message' => 'Internal error',
                'data' => sprintf('%s: %s', get_class($e), $e->getMessage()),
            ]);
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

        $tools = [
            ['name' => 'wp_health', 'description' => 'סקירת בריאות האתר: גרסאות, SSL, תוספים פעילים.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_plugin_list', 'description' => 'רשימת התוספים המותקנים והאם יש עדכון.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_theme_list', 'description' => 'רשימת התבניות (themes) המותקנות ואיזו פעילה.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_admin_list', 'description' => 'רשימת המשתמשים בעלי תפקיד מנהל (administrator): שם משתמש, אימייל ותאריך רישום.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_option_get', 'description' => 'קריאת הגדרה בטוחה מרשימה מוגדרת מראש.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]],
            ['name' => 'wp_error_log_tail', 'description' => 'שורות אחרונות מיומן השגיאות (אם מופעל).', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['lines' => ['type' => 'integer']]]],
            ['name' => 'wp_cache_flush', 'description' => 'ניקוי מטמון אובייקטים ו-OPcache.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_salts_rotate', 'description' => 'החלפת שמונת מפתחות ההצפנה (Secret Keys / Salts) ב-wp-config.php במפתחות אקראיים חדשים. התוצאה: כל המשתמשים באתר מנותקים ונדרשים להתחבר מחדש, וכל עוגיית התחברות ישנה מפסיקה להיות תקפה. אינו נוגע בסיסמאות, בתוכן או במסד הנתונים. מפתחות המוגדרים מחוץ ל-wp-config.php אינם מוחלפים והפעולה נכשלת במפורש.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_plugin_update', 'description' => 'עדכון תוסף לגרסה האחרונה לפי slug.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']], 'required' => ['plugin']]],
            ['name' => 'wp_core_update', 'description' => 'עדכון ליבת וורדפרס (WordPress core) לגרסה היציבה האחרונה. מחזיר את הגרסה לפני ואחרי. אם כבר מעודכן — לא מבצע דבר. לפני העדכון נשמרת נקודת שחזור (הגרסה הקודמת) לצורך Rollback.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_core_rollback', 'description' => 'שחזור ליבת וורדפרס לגרסה שנשמרה בנקודת השחזור לפני העדכון האחרון (או לגרסה שצוינה ב-version). מתקין מחדש את קבצי הגרסה מ-wordpress.org. שים לב: שדרוג מסד הנתונים אינו הפיך — שחזור בטוח בעיקר לעדכוני תחזוקה (minor/patch).', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['version' => ['type' => 'string']]]],
            ['name' => 'wp_plugin_activate', 'description' => 'הפעלת תוסף לפי קובץ.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']], 'required' => ['plugin']]],
            ['name' => 'wp_plugin_deactivate', 'description' => 'כיבוי תוסף לפי קובץ.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => ['type' => 'string']], 'required' => ['plugin']]],
            ['name' => 'wp_menu_list', 'description' => 'רשימת תפריטי הניווט באתר והפריטים בכל תפריט (מזהה פריט, טקסט, קישור, הורה, סדר).', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_menu_item_add', 'description' => 'הוספת פריט לתפריט. menu = שם או מזהה התפריט, title = טקסט הפריט, ואחד מ: url (קישור חופשי) או page_id (עמוד קיים). אופציונלי: parent_id (פריט הורה), position (מיקום).', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['menu' => ['type' => 'string'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string'], 'page_id' => ['type' => 'integer'], 'parent_id' => ['type' => 'integer'], 'position' => ['type' => 'integer']], 'required' => ['menu', 'title']]],
            ['name' => 'wp_menu_item_update', 'description' => 'עדכון פריט קיים בתפריט לפי item_id. אפשר לשנות title, url, parent_id, position (כל שדה אופציונלי).', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string'], 'parent_id' => ['type' => 'integer'], 'position' => ['type' => 'integer']], 'required' => ['item_id']]],
            ['name' => 'wp_menu_item_unlink', 'description' => 'הסרת פריט מהתפריט לפי item_id — מסיר רק את הקישור מהתפריט; העמוד/הפוסט עצמו נשאר.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['item_id' => ['type' => 'integer']], 'required' => ['item_id']]],
            ['name' => 'wp_post_types_list', 'description' => 'סוגי התוכן הקיימים באתר (עמודים, פוסטים וכל סוג מותאם — נכסים, פרויקטים, אירועים וכו\'): המזהה הטכני, השם בעברית, כמה פריטים יש, והאם יש לו שדות מותאמים. התחילו כאן כשהמשתמש מדבר על "נכסים" או "מוצרים" ואינכם יודעים באיזה סוג תוכן מדובר.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'wp_content_list', 'description' => 'רשימת פריטי תוכן. type = כל סוג תוכן רשום באתר (page, post, או סוג מותאם — ראו wp_post_types_list; ברירת מחדל page), status (any/publish/draft), search (טקסט חיפוש), limit.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'status' => ['type' => 'string'], 'search' => ['type' => 'string'], 'limit' => ['type' => 'integer']]]],
            ['name' => 'wp_content_get', 'description' => 'קריאת פריט תוכן מלא לפי id: כותרת, תוכן, סטטוס, סוג, השדות המותאמים שלו, והאם הוא בנוי באלמנטור (אם כן — לעריכת הטקסטים שבו יש להשתמש ב-wp_elementor_texts_get).', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
            ['name' => 'wp_content_create', 'description' => 'יצירת פריט תוכן. title, content (HTML), type = כל סוג תוכן רשום (ברירת מחדל page), status = draft או publish. אופציונלי: excerpt.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'type' => ['type' => 'string'], 'status' => ['type' => 'string'], 'excerpt' => ['type' => 'string']], 'required' => ['title']]],
            ['name' => 'wp_content_update', 'description' => 'עדכון פריט תוכן קיים לפי id: title, content, status, excerpt (כל שדה אופציונלי; מה שלא צוין נשמר). מחזיר את הערכים הקודמים לצורך ביטול. לעמוד שבנוי באלמנטור — שדה content לא ישפיע על מה שרואים; השתמשו ב-wp_elementor_text_update.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'status' => ['type' => 'string'], 'excerpt' => ['type' => 'string']], 'required' => ['id']]],
            ['name' => 'wp_fields_schema', 'description' => 'השדות המותאמים (ACF) המוגדרים לסוג תוכן: מזהה השדה, התווית בעברית, הסוג והאפשרויות. קִראו את זה לפני עדכון שדה, כדי לכתוב למפתח הנכון — כתיבה למפתח שגוי יוצרת שדה חדש שאיש אינו קורא.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string']], 'required' => ['type']]],
            ['name' => 'wp_fields_get', 'description' => 'הערכים הנוכחיים של השדות המותאמים בפריט תוכן לפי id.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
            ['name' => 'wp_fields_update', 'description' => 'עדכון שדות מותאמים בפריט תוכן. fields = אובייקט של מפתח→ערך. עובד דרך ACF כשהוא פעיל, אחרת דרך meta רגיל (JetEngine). מחזיר את הערכים הקודמים לצורך ביטול. שדות פנימיים (מתחילים בקו תחתון) חסומים.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'fields' => ['type' => 'object']], 'required' => ['id', 'fields']]],
            ['name' => 'wp_content_trash', 'description' => 'העברת עמוד/פוסט לפח לפי id (הפיך — ניתן לשחזר מהפח).', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]],
            ['name' => 'wp_file_list', 'description' => 'רשימת קבצים/תיקיות בתוך wp-content לפי path יחסי (ברירת מחדל: השורש של wp-content). לתיקון קוד — לאיתור הקובץ.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]]],
            ['name' => 'wp_file_get', 'description' => 'קריאת תוכן קובץ בתוך wp-content לפי path יחסי (לבדיקת קוד לפני תיקון).', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']]],
            ['name' => 'wp_file_put', 'description' => 'כתיבת תוכן לקובץ לתיקון קוד. path יחסי בתוך wp-content ומוגבל ל-themes/plugins/mu-plugins. קובצי PHP נבדקים תחבירית לפני שמירה. תמיד קִראו קודם עם wp_file_get ושמרו גיבוי לביטול.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['path', 'content']]],
        ];

        // Elementor tools — advertised only where Elementor is running, so a
        // site that never uses it is not offered a vocabulary it has no use for.
        if (Multioto_Agent_Elementor::active()) {
            $tools[] = ['name' => 'wp_elementor_texts_get', 'description' => 'כל הטקסטים הניתנים לעריכה בעמוד שבנוי באלמנטור, לפי id: לכל טקסט — מזהה הרכיב (widget_id), סוג הרכיב, שם השדה (setting) והטקסט הנוכחי. ברכיבים מרובי-שורות כמו אקורדיון, השדה הוא נתיב כגון tabs.0.tab_title. עמוד אלמנטור אינו שומר את התוכן שלו ב-content הרגיל, ולכן זו הדרך היחידה לראות ולערוך את מה שמופיע בו בפועל.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']]];
            $tools[] = ['name' => 'wp_elementor_text_update', 'description' => 'החלפת טקסט אחד בעמוד אלמנטור לפי widget_id ו-setting (שניהם מתוך wp_elementor_texts_get). setting אופציונלי כשלרכיב יש שדה טקסט אחד בלבד. מחזיר את הטקסט הקודם לצורך ביטול, ומרענן את ה-CSS של העמוד. שינוי מבנה, עיצוב או סדר רכיבים אינו נתמך בכוונה.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'widget_id' => ['type' => 'string'], 'text' => ['type' => 'string'], 'setting' => ['type' => 'string']], 'required' => ['id', 'widget_id', 'text']]];
        }

        // WooCommerce read tools — advertised only on stores, so a brochure site
        // never lists them. Read-only (they never change the shop), for
        // diagnosing orders and shipping conditions.
        if (class_exists('WooCommerce')) {
            $tools[] = ['name' => 'wc_order_get', 'description' => 'קריאת הזמנת WooCommerce לפי מספר: סטטוס, תאריך, פריטים, כתובות, שיטת המשלוח שנבחרה וסכומיה, קופונים וסכום כולל.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['order_id' => ['type' => 'integer']], 'required' => ['order_id']]];
            $tools[] = ['name' => 'wc_shipping_zones_list', 'description' => 'רשימת אזורי המשלוח (Shipping Zones) של WooCommerce: לכל אזור — האזורים הגאוגרפיים, ושיטות המשלוח עם התנאים שלהן (עלות, סף למשלוח חינם, דרישות).', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]];
            $tools[] = ['name' => 'wc_order_stats_get', 'description' => 'דופק המכירות: מספר ההזמנות שנוצרו בכל יום ב-N הימים האחרונים (ברירת מחדל 28), כמה מהן שולמו, ופירוט 24 השעות האחרונות לפי סטטוס. משמש לזיהוי כשל שקט — חנות שפתאום מפסיקה לקבל הזמנות.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['days' => ['type' => 'integer']]]];
        }

        // WooCommerce write tools. Separate from the read block above because
        // they are a different kind of permission: every one of them changes
        // what a customer is charged or what the shop says it has in stock.
        if (Multioto_Agent_Woo_Writer::active()) {
            $tools[] = ['name' => 'wc_product_search', 'description' => 'חיפוש מוצרים לפי טקסט חופשי (שם או מק"ט). מחזיר מזהה, שם, מק"ט, מחיר רגיל, מחיר מבצע ומלאי. השתמשו בזה כדי להפוך תיאור בדיבור ("החולצה השחורה") למזהה מוצר — וכשחוזרות כמה תוצאות, שאלו על איזה מהן מדובר במקום לנחש.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['search' => ['type' => 'string'], 'limit' => ['type' => 'integer']], 'required' => ['search']]];
            $tools[] = ['name' => 'wc_product_get', 'description' => 'פרטי מוצר מלאים לפי מזהה: מחירים, מבצע ותאריכיו, מלאי, סטטוס וקישור.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['product_id' => ['type' => 'integer']], 'required' => ['product_id']]];
            $tools[] = ['name' => 'wc_product_update', 'description' => 'עדכון מוצר לפי product_id. שדות אופציונליים: regular_price, sale_price (ריק = סיום המבצע), sale_from ו-sale_to (YYYY-MM-DD), stock_quantity, stock_status (instock/outofstock/onbackorder), status (publish/draft/private). מחזיר את המצב הקודם המלא לצורך ביטול. מחיר מבצע שאינו נמוך מהמחיר הרגיל נדחה.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['product_id' => ['type' => 'integer'], 'regular_price' => ['type' => 'string'], 'sale_price' => ['type' => 'string'], 'sale_from' => ['type' => 'string'], 'sale_to' => ['type' => 'string'], 'stock_quantity' => ['type' => 'integer'], 'stock_status' => ['type' => 'string'], 'status' => ['type' => 'string']], 'required' => ['product_id']]];
            $tools[] = ['name' => 'wc_product_create', 'description' => 'יצירת מוצר חדש — תמיד כטיוטה, לעולם לא מפורסם. name חובה; אופציונלי description, short_description, regular_price, sku. הפרסום נעשה בנפרד על ידי אדם שרואה את העמוד.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'description' => ['type' => 'string'], 'short_description' => ['type' => 'string'], 'regular_price' => ['type' => 'string'], 'sku' => ['type' => 'string']], 'required' => ['name']]];
            $tools[] = ['name' => 'wc_coupon_list', 'description' => 'רשימת הקופונים בחנות: קוד, סוג ההנחה, גובהה, תאריך תפוגה ומספר השימושים.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]]];
            $tools[] = ['name' => 'wc_coupon_create', 'description' => 'יצירת קופון. code חובה; type = percent (ברירת מחדל) / fixed_cart / fixed_product; amount חובה; אופציונלי expires (YYYY-MM-DD), minimum_amount, usage_limit. קופון באחוזים מעל 100 או בסכום אפס נדחה.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'type' => ['type' => 'string'], 'amount' => ['type' => 'number'], 'expires' => ['type' => 'string'], 'minimum_amount' => ['type' => 'string'], 'usage_limit' => ['type' => 'integer']], 'required' => ['code', 'amount']]];
            $tools[] = ['name' => 'wc_coupon_expire', 'description' => 'סיום קופון מיידי לפי code — נקבע לו תאריך תפוגה של היום. הקופון אינו נמחק, כדי שהזמנות עבר שהשתמשו בו ימשיכו להציג את ההנחה שקיבלו.', 'annotations' => $change, 'inputSchema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string']], 'required' => ['code']]];
        }

        return $tools;
    }

    /** Execute an allow-listed tool. Unknown names are rejected. */
    private function callTool(string $name, array $args): array
    {
        // A name => method map instead of match(): same allow-list guarantee,
        // and it parses on PHP 7.4 (see the note in handle()). An unknown name
        // is still rejected — nothing outside this list can ever be called.
        $handlers = [
            'wp_health' => 'health',
            'wp_plugin_list' => 'pluginList',
            'wp_theme_list' => 'themeList',
            'wp_admin_list' => 'adminList',
            'wp_option_get' => 'optionGet',
            'wp_error_log_tail' => 'errorLogTail',
            'wp_cache_flush' => 'cacheFlush',
            'wp_salts_rotate' => 'saltsRotate',
            'wp_plugin_update' => 'pluginUpdate',
            'wp_core_update' => 'coreUpdate',
            'wp_core_rollback' => 'coreRollback',
            'wp_menu_list' => 'menuList',
            'wp_menu_item_add' => 'menuItemAdd',
            'wp_menu_item_update' => 'menuItemUpdate',
            'wp_menu_item_unlink' => 'menuItemUnlink',
            'wp_post_types_list' => 'postTypesList',
            'wp_content_list' => 'contentList',
            'wp_content_get' => 'contentGet',
            'wp_content_create' => 'contentCreate',
            'wp_content_update' => 'contentUpdate',
            'wp_content_trash' => 'contentTrash',
            'wp_fields_schema' => 'fieldsSchema',
            'wp_fields_get' => 'fieldsGet',
            'wp_fields_update' => 'fieldsUpdate',
            'wp_elementor_texts_get' => 'elementorTexts',
            'wp_elementor_text_update' => 'elementorTextUpdate',
            'wc_product_search' => 'wcProductSearch',
            'wc_product_get' => 'wcProductGet',
            'wc_product_update' => 'wcProductUpdate',
            'wc_product_create' => 'wcProductCreate',
            'wc_coupon_list' => 'wcCouponList',
            'wc_coupon_create' => 'wcCouponCreate',
            'wc_coupon_expire' => 'wcCouponExpire',
            'wp_file_list' => 'fileList',
            'wp_file_get' => 'fileGet',
            'wp_file_put' => 'filePut',
            'wc_order_get' => 'wcOrderGet',
            'wc_order_stats_get' => 'wcOrderStats',
            'wc_shipping_zones_list' => 'wcShippingZones',
        ];

        // Tools whose signature takes no arguments, or a second flag.
        $noArgs = ['wp_health', 'wp_plugin_list', 'wp_theme_list', 'wp_admin_list', 'wp_cache_flush', 'wp_salts_rotate', 'wp_core_update', 'wp_menu_list', 'wc_shipping_zones_list', 'wp_post_types_list'];

        if ($name === 'wp_plugin_activate') {
            $text = $this->setPluginState($args, true);
        } elseif ($name === 'wp_plugin_deactivate') {
            $text = $this->setPluginState($args, false);
        } elseif (isset($handlers[$name])) {
            $method = $handlers[$name];
            $text = in_array($name, $noArgs, true) ? $this->{$method}() : $this->{$method}($args);
        } else {
            throw new Multioto_Agent_Rpc_Error(-32602, "Unknown tool: {$name}");
        }

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

    /** Installed themes, keyed by the stable stylesheet (directory) slug. */
    private function themeList(): string
    {
        $active = get_stylesheet();
        $out = [];

        foreach (wp_get_themes() as $slug => $theme) {
            $out[] = [
                'stylesheet' => (string) $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => ((string) $slug === $active),
            ];
        }

        return wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Administrator accounts — the panel's monitoring diffs this list so a new
     * admin (a classic compromise indicator) triggers an alert, exactly like a
     * newly-installed plugin or theme.
     */
    private function adminList(): string
    {
        $out = [];

        foreach (get_users(['role__in' => ['administrator']]) as $user) {
            $out[] = [
                'id' => (int) $user->ID,
                'login' => (string) $user->user_login,
                'email' => (string) $user->user_email,
                'registered' => (string) $user->user_registered,
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

    /**
     * The eight constants WordPress signs cookies and nonces with. Replacing
     * them is the standard way to end every session on a site at once — the
     * cleanup step after a compromise, a shared password, or a departing
     * employee.
     */
    private const SALT_CONSTANTS = [
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
    ];

    /**
     * Give wp-config.php a fresh set of secret keys.
     *
     * Everyone logged into the site is logged out, this one included — that is
     * the point of the operation, not a side effect.
     *
     * Two things are refused rather than done badly:
     *
     *  · **Keys defined outside wp-config.php.** Some hosts keep them in an
     *    included file or in the environment. Appending new defines there would
     *    change nothing (PHP keeps the first definition) while reporting
     *    success — the site would look rotated and every old cookie would still
     *    work. Detected by asking whether the constant is already defined at
     *    runtime while absent from the file, and refused outright.
     *
     *  · **A write that might land half-done.** The new file is built in full,
     *    written beside the original and moved over it in one step. Nothing
     *    truncates wp-config.php, so a failure at any point leaves the site
     *    exactly as it was — the one file that must never be half-written.
     */
    private function saltsRotate(): string
    {
        $file = $this->configFile();

        if ($file === null) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'לא נמצא הקובץ wp-config.php.');
        }

        if (! is_writable($file)) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'הקובץ wp-config.php אינו ניתן לכתיבה — יש לתקן הרשאות בשרת ולנסות שוב.');
        }

        $contents = file_get_contents($file);

        if ($contents === false || $contents === '') {
            throw new Multioto_Agent_Rpc_Error(-32000, 'קריאת wp-config.php נכשלה.');
        }

        $replaced = [];
        $appended = [];
        $elsewhere = [];

        foreach (self::SALT_CONSTANTS as $name) {
            // The line as WordPress writes it, tolerant of spacing, quote style
            // and define() vs const.
            $pattern = "/^([ \t]*(?:define\\s*\\(\\s*(['\"])".$name."\\2\\s*,\\s*)(['\"]))(?:\\\\.|[^'\"\\\\])*(\\3\\s*\\)\\s*;.*)$/m";

            if (preg_match($pattern, $contents) === 1) {
                $replaced[] = $name;

                continue;
            }

            // Present at runtime but not in this file: it comes from somewhere
            // we are not editing, and writing here would be theatre.
            if (defined($name)) {
                $elsewhere[] = $name;

                continue;
            }

            $appended[] = $name;
        }

        if ($elsewhere !== []) {
            throw new Multioto_Agent_Rpc_Error(-32000,
                'המפתחות '.implode(', ', $elsewhere).' מוגדרים מחוץ ל-wp-config.php (קובץ נכלל או משתני סביבה), '
                .'ולכן החלפה מכאן לא הייתה משנה דבר. יש להחליף אותם במקום שבו הם מוגדרים.');
        }

        $updated = $contents;

        foreach ($replaced as $name) {
            $pattern = "/^([ \t]*(?:define\\s*\\(\\s*(['\"])".$name."\\2\\s*,\\s*)(['\"]))(?:\\\\.|[^'\"\\\\])*(\\3\\s*\\)\\s*;.*)$/m";
            $updated = preg_replace_callback($pattern, function ($match) {
                return $match[1].$this->newSalt().$match[4];
            }, $updated, 1);

            if ($updated === null) {
                throw new Multioto_Agent_Rpc_Error(-32000, 'עריכת wp-config.php נכשלה — הקובץ לא שונה.');
            }
        }

        if ($appended !== []) {
            $lines = '';

            foreach ($appended as $name) {
                $lines .= "define('".$name."', '".$this->newSalt()."');\n";
            }

            $updated = $this->appendDefines($updated, $lines);
        }

        $this->writeAtomically($file, $updated);

        return wp_json_encode([
            'rotated' => $replaced,
            'added' => $appended,
            'note' => 'כל המשתמשים באתר נותקו וצריכים להתחבר מחדש. טפסים שהיו פתוחים בדפדפן יבקשו רענון.',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * A fresh secret. 64 characters from an alphabet with no quote and no
     * backslash in it, so the value can never break out of the single-quoted
     * string it is written into — the file being edited is the one that must
     * never fail to parse.
     */
    private function newSalt(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}<>~,.?/|:;';
        $last = strlen($alphabet) - 1;
        $salt = '';

        for ($i = 0; $i < 64; $i++) {
            $salt .= $alphabet[random_int(0, $last)];
        }

        return $salt;
    }

    /**
     * Put new defines where WordPress itself expects them: above the "stop
     * editing" marker, which is followed by wp-settings.php. A define written
     * after that line runs too late to sign anything.
     */
    private function appendDefines(string $contents, string $lines): string
    {
        $marker = "/^[ \t]*\\/\\*.*stop editing.*\\*\\/[ \t]*$/mi";

        if (preg_match($marker, $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1];

            return substr($contents, 0, $at).$lines.substr($contents, $at);
        }

        // No marker (a hand-written config): before the require of
        // wp-settings.php, which is the real deadline.
        $require = "/^[ \t]*require(?:_once)?[ \t]*[\\(\\s].*wp-settings\\.php.*$/mi";

        if (preg_match($require, $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1];

            return substr($contents, 0, $at).$lines.substr($contents, $at);
        }

        throw new Multioto_Agent_Rpc_Error(-32000,
            'מבנה wp-config.php אינו מוכר ולא ניתן להוסיף בו מפתחות בבטחה. יש להחליף אותם ידנית.');
    }

    /**
     * Replace a file's contents in one step.
     *
     * Written beside the original and moved over it, so the original is never
     * truncated: a failure at any point leaves the site running on the file it
     * already had. The temporary file is created private (0600) and given the
     * original's permissions and owner before the move, so the site does not
     * come back with a wp-config.php the web server cannot read.
     */
    private function writeAtomically(string $file, string $contents): void
    {
        $directory = dirname($file);
        $temporary = tempnam($directory, '.multioto-cfg');

        if ($temporary === false) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'לא ניתן ליצור קובץ זמני לצד wp-config.php — בדקו הרשאות כתיבה בתיקייה.');
        }

        $bytes = file_put_contents($temporary, $contents);

        if ($bytes === false || $bytes !== strlen($contents)) {
            @unlink($temporary);

            throw new Multioto_Agent_Rpc_Error(-32000, 'כתיבת הקובץ הזמני נכשלה — wp-config.php לא שונה.');
        }

        $permissions = fileperms($file);

        if ($permissions !== false) {
            @chmod($temporary, $permissions & 0777);
        }

        $owner = @fileowner($file);
        $group = @filegroup($file);

        if ($owner !== false) {
            @chown($temporary, $owner);
        }
        if ($group !== false) {
            @chgrp($temporary, $group);
        }

        if (! @rename($temporary, $file)) {
            @unlink($temporary);

            throw new Multioto_Agent_Rpc_Error(-32000, 'החלפת wp-config.php נכשלה — הקובץ המקורי נשאר כפי שהיה.');
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * Where wp-config.php lives — beside WordPress, or one directory up when
     * the install is nested. Mirrors WordPress's own lookup in wp-load.php,
     * including its refusal to climb into a directory that holds another
     * WordPress install.
     */
    private function configFile(): ?string
    {
        if (file_exists(ABSPATH.'wp-config.php')) {
            return ABSPATH.'wp-config.php';
        }

        $parent = dirname(rtrim(ABSPATH, '/\\'));

        if (@file_exists($parent.'/wp-config.php') && ! @file_exists($parent.'/wp-settings.php')) {
            return $parent.'/wp-config.php';
        }

        return null;
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

    /**
     * Update WordPress core to the latest stable release. Returns the version
     * before and after so the caller can confirm the change; a site already on
     * the latest version is left untouched and reported as such.
     */
    private function coreUpdate(): string
    {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/misc.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH.'wp-admin/includes/update.php';

        $before = get_bloginfo('version');

        // Refresh the core-update transient, then pick the offered "upgrade".
        wp_version_check([], true);
        $updates = get_core_updates();

        if (empty($updates) || ! is_array($updates) || ($updates[0]->response ?? '') === 'latest') {
            return "וורדפרס כבר מעודכן (גרסה {$before}). לא בוצע עדכון.";
        }

        // Save a restore point BEFORE touching the files, so wp_core_rollback can
        // reinstall exactly the version we are leaving. Not autoloaded — it is read
        // only on an explicit rollback.
        update_option('multioto_agent_core_rollback', ['version' => $before, 'time' => time()], false);

        $upgrader = new Core_Upgrader(new Automatic_Upgrader_Skin);
        $result = $upgrader->upgrade($updates[0]);

        if (is_wp_error($result)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $result->get_error_message());
        }

        // Read the new version fresh from the just-replaced version.php.
        require ABSPATH.WPINC.'/version.php';
        $after = $wp_version ?? get_bloginfo('version');

        // Core_Upgrader swaps the FILES but does not run the database-schema
        // upgrade — that normally happens when an admin visits upgrade.php. Run
        // it now (idempotent) so the site never executes new code against the old
        // schema. wp_upgrade() bumps db_version and runs the per-version steps.
        if ((int) get_option('db_version') !== (int) $wp_db_version) {
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            @wp_upgrade();
        }

        return "ליבת וורדפרס עודכנה מגרסה {$before} לגרסה {$after} (כולל שדרוג מסד הנתונים).";
    }

    /**
     * Roll WordPress core back to the version saved before the last core update
     * (the restore point), or to an explicitly given version. Reinstalls that
     * release's files from the official wordpress.org archive. Idempotent: a site
     * already on the target version is left untouched.
     *
     * Note: WordPress does not support downgrading the database schema, so this
     * is safe for maintenance (minor/patch) rollbacks; a downgrade across a major
     * release that ran DB migrations may leave the schema ahead of the files.
     */
    private function coreRollback(array $args): string
    {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/misc.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH.'wp-admin/includes/update.php';

        $current = get_bloginfo('version');

        // Explicit version wins; otherwise use the saved restore point.
        $target = trim((string) ($args['version'] ?? ''));

        if ($target === '') {
            $point = get_option('multioto_agent_core_rollback');
            $target = is_array($point) ? (string) ($point['version'] ?? '') : '';
        }

        if ($target === '' || ! preg_match('/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $target)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'אין נקודת שחזור שמורה או שהגרסה שצוינה אינה תקינה.');
        }

        if ($target === $current) {
            return "וורדפרס כבר בגרסה {$target}. לא בוצע שחזור.";
        }

        // Build a version-pinned "offer" pointing at the official release archive,
        // exactly the shape Core_Upgrader::upgrade() expects from get_core_updates().
        $package = 'https://downloads.wordpress.org/release/wordpress-'.$target.'.zip';
        $offer = (object) [
            'response' => 'upgrade',
            'download' => $package,
            'locale' => 'en_US',
            'packages' => (object) ['full' => $package, 'no_content' => false, 'new_bundled' => false, 'partial' => false, 'rollback' => false],
            'current' => $target,
            'version' => $target,
            'php_version' => '7.2.24',
            'mysql_version' => '5.5.5',
        ];

        // Force the core files to be replaced even though it is a downgrade.
        add_filter('update_feedback', '__return_false');
        $upgrader = new Core_Upgrader(new Automatic_Upgrader_Skin);
        $result = $upgrader->upgrade($offer);

        if (is_wp_error($result)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $result->get_error_message());
        }

        // A filesystem it cannot reach makes Core_Upgrader return false/null (not a
        // WP_Error). Treat that as failure — otherwise we would report success and
        // delete the only restore point while nothing actually changed.
        if ($result === false || $result === null) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'שחזור הליבה נכשל — לא ניתן היה להחליף את קבצי וורדפרס.');
        }

        require ABSPATH.WPINC.'/version.php';
        $after = $wp_version ?? get_bloginfo('version');

        // The restore point is consumed once used, so a later update stores a fresh one.
        delete_option('multioto_agent_core_rollback');

        return "ליבת וורדפרס שוחזרה מגרסה {$current} לגרסה {$after}.";
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
        $elementor = Multioto_Agent_Elementor::active()
            && Multioto_Agent_Elementor::builtWithElementor($post->ID);

        return wp_json_encode([
            'id' => $post->ID,
            'title' => $post->post_title,
            'type' => $post->post_type,
            'status' => $post->post_status,
            'excerpt' => $post->post_excerpt,
            'content' => $post->post_content,
            // Said on every read, because it decides which tool edits this page.
            // A page built with Elementor keeps its visible text elsewhere, and
            // an agent that does not know that will "successfully" edit nothing.
            'built_with_elementor' => $elementor,
            'edit_note' => $elementor
                ? 'העמוד בנוי באלמנטור — לעריכת הטקסטים שמופיעים בו יש להשתמש ב-wp_elementor_texts_get ו-wp_elementor_text_update. שדה content אינו מה שרואים.'
                : null,
            'fields' => Multioto_Agent_Fields::values($post->ID),
            'url' => get_permalink($post),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * The content types this site actually has.
     *
     * A customer says "properties" or "projects"; the site calls them
     * `property` or `md_project`. Without this the agent guesses a type name,
     * WP_Query returns nothing, and the honest answer "you have no properties"
     * is wrong.
     */
    private function postTypesList(): string
    {
        $out = [];

        foreach ($this->editableTypes() as $name) {
            $object = get_post_type_object($name);
            $counts = (array) wp_count_posts($name);

            $out[] = [
                'type' => $name,
                'label' => $object->labels->name ?? $name,
                'published' => (int) ($counts['publish'] ?? 0),
                'drafts' => (int) ($counts['draft'] ?? 0),
                'has_custom_fields' => Multioto_Agent_Fields::schema($name) !== [],
                'builtin' => (bool) $object->_builtin,
            ];
        }

        return wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // --- Custom fields (ACF / JetEngine) --------------------------------------

    private function fieldsSchema(array $args): string
    {
        $type = $this->contentType($args);
        $schema = Multioto_Agent_Fields::schema($type);

        // An empty schema has two very different causes, and the caller needs
        // to know which: no ACF on the site at all, or ACF with nothing mapped
        // to this type. The first means "read the fields with wp_fields_get and
        // work from the keys"; the second means "this type really has none".
        return wp_json_encode([
            'type' => $type,
            'acf_active' => Multioto_Agent_Fields::acfActive(),
            'fields' => $schema,
            'note' => $schema === [] && ! Multioto_Agent_Fields::acfActive()
                ? 'ACF אינו פעיל באתר. אפשר לקרוא את השדות בפועל עם wp_fields_get ולעדכן לפי המפתחות שיחזרו.'
                : null,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function fieldsGet(array $args): string
    {
        $post = $this->contentPost((int) ($args['id'] ?? 0));

        return wp_json_encode([
            'id' => $post->ID,
            'type' => $post->post_type,
            'fields' => Multioto_Agent_Fields::values($post->ID),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function fieldsUpdate(array $args): string
    {
        $post = $this->contentPost((int) ($args['id'] ?? 0));
        $fields = $args['fields'] ?? null;

        if (! is_array($fields) || $fields === []) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר fields — אובייקט של מפתח→ערך.');
        }

        $result = Multioto_Agent_Fields::update($post->ID, $fields);

        return wp_json_encode([
            'updated_id' => $post->ID,
            'updated' => $result['updated'],
            'previous' => $result['previous'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // --- Elementor (text only) ------------------------------------------------

    private function elementorTexts(array $args): string
    {
        $post = $this->contentPost((int) ($args['id'] ?? 0));

        return wp_json_encode([
            'id' => $post->ID,
            'texts' => Multioto_Agent_Elementor::texts($post->ID),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function elementorTextUpdate(array $args): string
    {
        $post = $this->contentPost((int) ($args['id'] ?? 0));
        $widgetId = trim((string) ($args['widget_id'] ?? ''));

        if ($widgetId === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר widget_id (מתוך wp_elementor_texts_get).');
        }

        if (! isset($args['text'])) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר text.');
        }

        $result = Multioto_Agent_Elementor::updateText(
            $post->ID,
            $widgetId,
            (string) $args['text'],
            isset($args['setting']) ? (string) $args['setting'] : null
        );

        return wp_json_encode([
            'updated_id' => $post->ID,
            'widget_id' => $widgetId,
            'widget' => $result['widget'],
            'setting' => $result['setting'],
            'previous' => $result['previous'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // --- WooCommerce (write) --------------------------------------------------

    private function wcProductSearch(array $args): string
    {
        $term = trim((string) ($args['search'] ?? ''));

        if ($term === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר טקסט חיפוש (search).');
        }

        return wp_json_encode(
            Multioto_Agent_Woo_Writer::search($term, (int) ($args['limit'] ?? 10)),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    private function wcProductGet(array $args): string
    {
        return wp_json_encode(
            Multioto_Agent_Woo_Writer::get((int) ($args['product_id'] ?? 0)),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    private function wcProductUpdate(array $args): string
    {
        return wp_json_encode(
            Multioto_Agent_Woo_Writer::update((int) ($args['product_id'] ?? 0), $args),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    private function wcProductCreate(array $args): string
    {
        return wp_json_encode(Multioto_Agent_Woo_Writer::create($args), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function wcCouponList(array $args): string
    {
        return wp_json_encode(
            Multioto_Agent_Woo_Writer::coupons((int) ($args['limit'] ?? 30)),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }

    private function wcCouponCreate(array $args): string
    {
        return wp_json_encode(Multioto_Agent_Woo_Writer::createCoupon($args), JSON_UNESCAPED_UNICODE);
    }

    private function wcCouponExpire(array $args): string
    {
        return wp_json_encode(
            Multioto_Agent_Woo_Writer::expireCoupon((string) ($args['code'] ?? '')),
            JSON_UNESCAPED_UNICODE
        );
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

        // Captured before anything is written: the platform stores it as the
        // snapshot behind "undo", and only the fields actually being changed
        // are included — restoring a title nobody touched would undo somebody
        // else's edit.
        $previous = [];

        foreach (['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status'] as $arg => $column) {
            if (isset($args[$arg])) {
                $previous[$arg] = $post->{$column};
            }
        }

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

        return wp_json_encode([
            'updated_id' => (int) $post->ID,
            'previous' => $previous,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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

    /**
     * Resolve the requested content type against the ones this site really has.
     *
     * An unknown type is refused by name rather than quietly falling back to
     * `page`: a request to list "properties" on a site with no such type must
     * report that, not return the pages and let somebody conclude the property
     * listings were deleted.
     */
    private function contentType(array $args): string
    {
        $type = strtolower(trim((string) ($args['type'] ?? 'page')));

        if ($type === '') {
            return 'page';
        }

        $available = $this->editableTypes();

        if (! in_array($type, $available, true)) {
            throw new Multioto_Agent_Rpc_Error(-32602,
                "סוג התוכן {$type} אינו קיים באתר. הסוגים הקיימים: ".implode(', ', $available).'.');
        }

        return $type;
    }

    /**
     * Content types an agent may touch.
     *
     * Everything with an editing screen, minus the types that are plumbing:
     * attachments (a media library edit is a file operation in disguise),
     * revisions, menu items (they have their own tools) and Elementor's own
     * template records. Products are excluded too — a product edited as a
     * generic post bypasses the price validation in the WooCommerce tools.
     *
     * @return list<string>
     */
    private function editableTypes(): array
    {
        $excluded = [
            'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
            'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part',
            'wp_global_styles', 'wp_navigation', 'elementor_library', 'e-landing-page',
            'product', 'product_variation', 'shop_order', 'shop_coupon',
        ];

        // get_post_types() returns name => name; the values are what we filter.
        return array_values(array_diff(get_post_types(['show_ui' => true], 'names'), $excluded));
    }

    /** Validate a post status; blank/unknown falls back to draft (or any, for a query). */
    private function contentStatus(string $status, bool $forQuery = false): string
    {
        $status = strtolower(trim($status));
        $allowed = $forQuery ? ['any', 'publish', 'draft', 'pending', 'private'] : ['publish', 'draft', 'pending', 'private'];

        return in_array($status, $allowed, true) ? $status : ($forQuery ? 'any' : 'draft');
    }

    /** Fetch an editable content item by id, or fail. */
    private function contentPost(int $id): WP_Post
    {
        $post = $id > 0 ? get_post($id) : null;

        if (! $post || ! in_array($post->post_type, $this->editableTypes(), true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, "פריט התוכן {$id} לא נמצא.");
        }

        return $post;
    }

    // --- Code / files (confined to wp-content) -------------------------------

    private function fileList(array $args): string
    {
        $dir = $this->resolveContentPath((string) ($args['path'] ?? ''), true, false);

        if (! is_dir($dir)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'הנתיב אינו תיקייה.');
        }

        $entries = [];

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $dir.'/'.$name;
            $entries[] = [
                'name' => $name,
                'type' => is_dir($full) ? 'dir' : 'file',
                'size' => is_file($full) ? (int) filesize($full) : null,
            ];
        }

        return wp_json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function fileGet(array $args): string
    {
        $file = $this->resolveContentPath((string) ($args['path'] ?? ''), true, true);

        // Cap the read so a huge file can't blow up the response.
        if (filesize($file) > 512 * 1024) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'הקובץ גדול מדי לקריאה (מעל 512KB).');
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'לא ניתן לקרוא את הקובץ.');
        }

        return $contents;
    }

    private function filePut(array $args): string
    {
        $rel = (string) ($args['path'] ?? '');
        $file = $this->resolveContentPath($rel, false, true);

        // Writes are confined further, to the directories where editing code is
        // legitimate — never uploads (a PHP file there is a classic backdoor).
        $relClean = ltrim(str_replace('\\', '/', $rel), '/');
        $allowed = ['themes/', 'plugins/', 'mu-plugins/'];
        $ok = false;
        foreach ($allowed as $root) {
            if (strpos($relClean, $root) === 0) {
                $ok = true;
                break;
            }
        }
        if (! $ok) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'כתיבה מותרת רק תחת themes/ , plugins/ או mu-plugins/.');
        }

        $content = (string) ($args['content'] ?? '');

        // Syntax-check PHP before writing so a bad edit can't white-screen the
        // site. TOKEN_PARSE makes the tokenizer throw on a parse error — no
        // eval/exec involved.
        if (preg_match('/\.php$/i', $relClean)) {
            try {
                token_get_all($content, TOKEN_PARSE);
            } catch (\Throwable $e) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'שגיאת תחביר ב-PHP — הקובץ לא נשמר: '.$e->getMessage());
            }
        }

        if (! is_dir(dirname($file))) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'תיקיית היעד לא קיימת.');
        }

        if (file_put_contents($file, $content) === false) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'הכתיבה נכשלה (ייתכן שאין הרשאות).');
        }

        return wp_json_encode(['written' => $relClean, 'bytes' => strlen($content)], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Resolve a path RELATIVE TO wp-content and confirm it stays inside it —
     * blocking traversal and symlink escapes. $mustExist requires the target to
     * exist; $isFile requires it to be a regular file.
     */
    private function resolveContentPath(string $rel, bool $mustExist, bool $isFile): string
    {
        $rel = ltrim(str_replace(['\\', "\0"], '/', $rel), '/');

        if (strpos($rel, '..') !== false) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'נתיב לא חוקי.');
        }

        $base = wp_normalize_path(WP_CONTENT_DIR);
        $full = wp_normalize_path($base.'/'.$rel);

        // The lexical path must be within wp-content…
        if ($full !== $base && strpos($full, $base.'/') !== 0) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'הנתיב חייב להיות בתוך wp-content.');
        }

        $realBase = wp_normalize_path((string) realpath(WP_CONTENT_DIR));

        if ($realBase === '') {
            throw new Multioto_Agent_Rpc_Error(-32000, 'wp-content לא נמצא.');
        }

        // The REAL (symlink-resolved) path must also be within wp-content. For a
        // target that doesn't exist yet we resolve the nearest existing ancestor
        // instead — otherwise a symlinked parent pointing outside wp-content
        // could let a NEW file be written past the boundary.
        $resolveTarget = $full;
        while (! file_exists($resolveTarget)) {
            if ($mustExist) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'הקובץ/הנתיב לא נמצא.');
            }

            $parent = wp_normalize_path(dirname($resolveTarget));

            if ($parent === $resolveTarget) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'תיקיית היעד לא קיימת.');
            }

            $resolveTarget = $parent;
        }

        $real = wp_normalize_path((string) realpath($resolveTarget));

        if ($real === '' || ($real !== $realBase && strpos($real, $realBase.'/') !== 0)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'הנתיב חייב להיות בתוך wp-content.');
        }

        if ($isFile && $mustExist && ! is_file($full)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'הנתיב אינו קובץ.');
        }

        return $full;
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

    // --- WooCommerce (read-only) --------------------------------------------

    /** Guard: WooCommerce must be installed and active. */
    private function requireWoo(): void
    {
        if (! function_exists('wc_get_order') || ! class_exists('WooCommerce')) {
            throw new Multioto_Agent_Rpc_Error(-32601, 'WooCommerce אינו מותקן או אינו פעיל באתר זה.');
        }
    }

    /**
     * Map a value the team sees to an internal WooCommerce order ID. Sequential /
     * custom order-number plugins store the displayed number in order meta, so a
     * "#171690" shown to staff may differ from the internal id — resolve that
     * first, and fall back to treating the value as the internal id.
     */
    private function resolveOrderId(int $number): int
    {
        if (! function_exists('wc_get_orders')) {
            return $number;
        }

        foreach (['_order_number', '_order_number_formatted'] as $meta_key) {
            $found = wc_get_orders([
                'limit' => 1,
                'return' => 'ids',
                'meta_key' => $meta_key,
                'meta_value' => (string) $number,
            ]);

            if (! empty($found)) {
                return (int) $found[0];
            }
        }

        return $number;
    }

    /**
     * Sales pulse: how many orders were CREATED on each of the last N days, how
     * many of them were paid, plus a breakdown of the last 24 hours by status.
     *
     * The panel diffs this against the store's own baseline to catch a silent
     * failure — a shop that is "up" but has stopped taking orders (broken
     * checkout), or one still taking orders where none of them can pay
     * (broken gateway). Counts only; no customer data leaves the site.
     */
    private function wcOrderStats(array $args): string
    {
        $this->requireWoo();

        $days = isset($args['days']) ? (int) $args['days'] : 28;
        $days = max(7, min(60, $days));

        // Statuses that mean the customer actually paid.
        $paid_statuses = function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : ['processing', 'completed'];

        $since = time() - ($days * DAY_IN_SECONDS);
        $orders = wc_get_orders([
            'limit' => 5000,
            'type' => 'shop_order',
            'date_created' => '>' . gmdate('Y-m-d H:i:s', $since),
            'return' => 'objects',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $daily = [];
        for ($i = $days; $i >= 1; $i--) {
            $daily[wp_date('Y-m-d', time() - ($i * DAY_IN_SECONDS))] = ['orders' => 0, 'paid' => 0];
        }
        $daily[wp_date('Y-m-d')] = ['orders' => 0, 'paid' => 0];

        $day_ago = time() - DAY_IN_SECONDS;
        $last_24h = ['orders' => 0, 'paid' => 0, 'by_status' => []];

        foreach ((array) $orders as $order) {
            $created = $order->get_date_created();

            if (! $created) {
                continue;
            }

            $timestamp = $created->getTimestamp();
            $key = wp_date('Y-m-d', $timestamp);
            $status = $order->get_status();
            $is_paid = in_array($status, $paid_statuses, true);

            if (isset($daily[$key])) {
                $daily[$key]['orders']++;
                if ($is_paid) {
                    $daily[$key]['paid']++;
                }
            }

            if ($timestamp >= $day_ago) {
                $last_24h['orders']++;
                if ($is_paid) {
                    $last_24h['paid']++;
                }
                $last_24h['by_status'][$status] = ($last_24h['by_status'][$status] ?? 0) + 1;
            }
        }

        return wp_json_encode([
            'days' => $days,
            'daily' => $daily,
            'last_24h' => $last_24h,
            'currency' => get_woocommerce_currency(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** Read one WooCommerce order — status, items, addresses, the chosen shipping method and totals. */
    private function wcOrderGet(array $args): string
    {
        $this->requireWoo();

        $id = (int) ($args['order_id'] ?? 0);
        if ($id <= 0) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'order_id (מספר ההזמנה) נדרש.');
        }

        $order = wc_get_order($this->resolveOrderId($id));
        if (! $order) {
            throw new Multioto_Agent_Rpc_Error(-32602, "הזמנה {$id} לא נמצאה.");
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'name' => $item->get_name(),
                'qty' => $item->get_quantity(),
                'total' => $order->get_line_total($item, true),
            ];
        }

        $shipping = [];
        foreach ($order->get_shipping_methods() as $method) {
            $shipping[] = [
                'method_title' => $method->get_method_title(),
                'method_id' => $method->get_method_id(),
                'instance_id' => $method->get_instance_id(),
                'total' => $method->get_total(),
            ];
        }

        $coupons = [];
        foreach ($order->get_items('coupon') as $coupon) {
            $coupons[] = $coupon->get_code();
        }

        $created = $order->get_date_created();

        $data = [
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'date_created' => $created ? $created->date('c') : null,
            'currency' => $order->get_currency(),
            'total' => $order->get_total(),
            'shipping_total' => $order->get_shipping_total(),
            'discount_total' => $order->get_discount_total(),
            'payment_method' => $order->get_payment_method_title(),
            'customer' => trim($order->get_formatted_billing_full_name()),
            'billing' => [
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ],
            'shipping_address' => [
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ],
            'items' => $items,
            'shipping_lines' => $shipping,
            'coupons' => $coupons,
        ];

        return wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** List the store's shipping zones with their regions, methods and conditions. */
    private function wcShippingZones(): string
    {
        $this->requireWoo();

        if (! class_exists('WC_Shipping_Zones') || ! class_exists('WC_Shipping_Zone')) {
            throw new Multioto_Agent_Rpc_Error(-32601, 'ניהול אזורי המשלוח אינו זמין בגרסת ה-WooCommerce הזו.');
        }

        $zones = [];
        foreach (WC_Shipping_Zones::get_zones() as $zone) {
            $zones[] = $this->describeShippingZone(new WC_Shipping_Zone((int) $zone['id']));
        }

        // "Rest of the World" — the fallback zone (id 0), where uncovered
        // regions fall; often the reason an order gets an unexpected method.
        $zones[] = $this->describeShippingZone(new WC_Shipping_Zone(0));

        return wp_json_encode(['zones' => $zones], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** @return array<string, mixed> */
    private function describeShippingZone(WC_Shipping_Zone $zone): array
    {
        $regions = [];
        foreach ($zone->get_zone_locations() as $location) {
            $regions[] = ['type' => $location->type ?? '', 'code' => $location->code ?? ''];
        }

        $methods = [];
        foreach ($zone->get_shipping_methods(false) as $method) {
            $entry = [
                'instance_id' => $method->get_instance_id(),
                'method_id' => $method->id,
                'title' => $method->get_title(),
                'enabled' => $method->is_enabled(),
            ];

            // Report ALL of the instance's settings, not a fixed subset — a
            // method's price/availability can hinge on per-class costs
            // (class_cost_*, no_class_cost), free-shipping thresholds, or a
            // third-party method's own keys. Enumerate whatever it exposes.
            if (method_exists($method, 'init_instance_settings')) {
                $method->init_instance_settings();
            }

            $settings = [];
            foreach ((array) $method->instance_settings as $key => $value) {
                if ($value !== '' && $value !== null && $value !== false && $value !== []) {
                    $settings[$key] = $value;
                }
            }
            if ($settings !== []) {
                $entry['settings'] = $settings;
            }

            $methods[] = $entry;
        }

        return [
            'id' => $zone->get_id(),
            'name' => $zone->get_zone_name(),
            'regions' => $regions,
            'methods' => $methods,
        ];
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

/**
 * A typed JSON-RPC error carrying a protocol code. Every throw site passes
 * (code, message) — JSON-RPC order — which is the REVERSE of \Exception's
 * (message, int $code) signature; without this constructor PHP fatals with
 * "Argument #2 ($code) must be of type int, string given" on EVERY thrown
 * tool error, so tools failed with a TypeError instead of a clean RPC error.
 */
class Multioto_Agent_Rpc_Error extends \Exception {
    public function __construct( int $code, string $message ) {
        parent::__construct( $message, $code );
    }
}
