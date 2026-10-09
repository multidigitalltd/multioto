<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Services\Agent\McpClient;
use Illuminate\Support\Str;

/**
 * What the assistant may READ from a customer's site, and how.
 *
 * A closed list. Each tool the model sees maps onto exactly one tool of the
 * companion plugin, and only the arguments named here are passed on — whatever
 * else the model puts in its call is dropped, so it can never reach a plugin
 * tool, or a plugin argument, that this list did not choose to expose.
 *
 * Nothing here changes the site. Changes are proposals, built and previewed by
 * SiteActionProposer and carried out only after the owner says yes.
 *
 * A tool is offered only where the site's plugin actually has the tool behind
 * it — a brochure site is not offered orders, a site on an older plugin is not
 * offered leads — so the model is never handed a vocabulary that can only fail.
 */
class SiteAgentToolbox
{
    /**
     * name => [plugin tool, description, properties, required].
     *
     * @var array<string, array{0: string, 1: string, 2: array<string, array<string, mixed>>, 3: list<string>}>
     */
    private const READS = [
        'find_orders' => ['wc_order_list',
            'הזמנות אחרונות, מהחדשה לישנה: מספר, סטטוס, תאריך, סכום, לקוח, טלפון, אימייל ופריטים. סינון לפי status, days, ו-search (שם, טלפון, אימייל או מספר הזמנה).',
            ['status' => ['type' => 'string', 'description' => 'processing / on-hold / completed / pending / cancelled / any'],
                'days' => ['type' => 'integer'], 'search' => ['type' => 'string'], 'limit' => ['type' => 'integer']], []],
        'get_order' => ['wc_order_get',
            'פרטי הזמנה אחת לפי מספר: פריטים, כתובות, משלוח, קופונים וסכומים.',
            ['order_id' => ['type' => 'integer', 'description' => 'מספר ההזמנה']], ['order_id']],
        'sales_report' => ['wc_sales_report',
            'דוח מכירות ל-N הימים האחרונים (ברירת מחדל 30), או לתקופה סגורה עם from ו-to (YYYY-MM-DD): הזמנות ששולמו, ברוטו, החזרים, נטו, ממוצע, מוצרים מובילים והשוואה לתקופה הקודמת.',
            ['days' => ['type' => 'integer'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string']], []],
        'sales_pulse' => ['wc_order_stats_get',
            'כמה הזמנות נוצרו ושולמו בכל יום ב-N הימים האחרונים, ופירוט 24 השעות האחרונות לפי סטטוס.',
            ['days' => ['type' => 'integer']], []],
        'find_products' => ['wc_product_search',
            'חיפוש מוצרים לפי שם או מק"ט: מזהה, שם, מחירים, מלאי וסטטוס.',
            ['search' => ['type' => 'string'], 'limit' => ['type' => 'integer'], 'page' => ['type' => 'integer']], ['search']],
        'get_product' => ['wc_product_get',
            'פרטי מוצר אחד לפי מזהה.',
            ['product_id' => ['type' => 'integer']], ['product_id']],
        'list_coupons' => ['wc_coupon_list',
            'הקופונים בחנות: קוד, סוג, גובה ההנחה, תפוגה ושימושים.',
            ['limit' => ['type' => 'integer']], []],
        'find_subscriptions' => ['wcs_subscription_list',
            'מנויים מתחדשים (WooCommerce Subscriptions): סטטוס, לקוח, סכום, מחזור חיוב ותאריך החיוב הבא. סינון לפי status ו-search.',
            ['status' => ['type' => 'string'], 'search' => ['type' => 'string'], 'limit' => ['type' => 'integer']], []],
        'get_subscription' => ['wcs_subscription_get',
            'פרטי מנוי אחד לפי מזהה.',
            ['subscription_id' => ['type' => 'integer']], ['subscription_id']],
        'find_content' => ['wp_content_list',
            'פוסטים, עמודים או כל סוג תוכן אחר: מזהה, כותרת, סטטוס, תאריך עדכון וקישור. type = post / page / סוג מותאם.',
            ['type' => ['type' => 'string'], 'status' => ['type' => 'string'], 'search' => ['type' => 'string'], 'limit' => ['type' => 'integer']], []],
        'get_content' => ['wp_content_get',
            'פריט תוכן אחד לפי מזהה: כותרת, תוכן מלא, סטטוס, והאם הוא בנוי באלמנטור.',
            ['id' => ['type' => 'integer']], ['id']],
        'list_content_types' => ['wp_post_types_list',
            'סוגי התוכן שקיימים באתר (פוסטים, עמודים, וסוגים מותאמים כמו נכסים או פרויקטים).',
            [], []],
        'find_users' => ['wp_user_list',
            'משתמשי האתר: מזהה, שם משתמש, אימייל, שם תצוגה, תפקידים ותאריך הרשמה. סינון לפי search ו-role.',
            ['search' => ['type' => 'string'], 'role' => ['type' => 'string'], 'limit' => ['type' => 'integer']], []],
        'find_leads' => ['wp_lead_list',
            'לידים מטפסי האתר (Elementor, Contact Form 7, WPForms, Gravity Forms, Fluent Forms): טופס, תאריך והשדות שמולאו. ברירת מחדל 30 ימים; לתקופה סגורה — from ו-to (YYYY-MM-DD).',
            ['days' => ['type' => 'integer'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'],
                'search' => ['type' => 'string'], 'limit' => ['type' => 'integer']], []],
        'find_comments' => ['wp_comment_list',
            'תגובות באתר. status = hold (ממתינות לאישור, ברירת מחדל) / approve / spam / all; post_id לתגובות של פריט אחד.',
            ['status' => ['type' => 'string'], 'post_id' => ['type' => 'integer'], 'search' => ['type' => 'string'], 'limit' => ['type' => 'integer']], []],
        'find_media' => ['wp_media_list',
            'קבצים בספריית המדיה: מזהה, כותרת, כתובת, סוג וטקסט חלופי.',
            ['search' => ['type' => 'string'], 'mime_type' => ['type' => 'string'], 'limit' => ['type' => 'integer']], []],
        'list_menus' => ['wp_menu_list',
            'תפריטי הניווט באתר והפריטים בכל אחד.',
            [], []],
        'list_taxonomies' => ['wp_taxonomy_list',
            'סוגי הקטגוריות באתר (קטגוריות, תגיות, קטגוריות מוצרים וכו\').',
            ['type' => ['type' => 'string']], []],
        'find_terms' => ['wp_term_list',
            'הקטגוריות/התגיות בטקסונומיה אחת (taxonomy, למשל category או product_cat) וכמה פריטים בכל אחת.',
            ['taxonomy' => ['type' => 'string'], 'search' => ['type' => 'string'], 'limit' => ['type' => 'integer']], ['taxonomy']],
        'get_fields' => ['wp_fields_get',
            'השדות המותאמים של פריט תוכן (ACF וכדומה) לפי id.',
            ['id' => ['type' => 'integer']], ['id']],
        'get_page_texts' => ['wp_elementor_texts_get',
            'הטקסטים שמופיעים בפועל בעמוד שבנוי באלמנטור, לפי id.',
            ['id' => ['type' => 'integer']], ['id']],
        'get_item_terms' => ['wp_post_terms_get',
            'הקטגוריות/התגיות שמשויכות כרגע לפריט (פוסט, עמוד או מוצר) בטקסונומיה אחת — למשל category או product_cat.',
            ['id' => ['type' => 'integer'], 'taxonomy' => ['type' => 'string']], ['id', 'taxonomy']],
        'field_schema' => ['wp_fields_schema',
            'השדות המותאמים שמוגדרים לסוג תוכן (type): מפתח, תווית, סוג ואפשרויות. קִראו לפני הצעה לעדכן שדה.',
            ['type' => ['type' => 'string']], ['type']],
        'list_themes' => ['wp_theme_list',
            'התבניות (themes) המותקנות ואיזו פעילה.',
            [], []],
        'shipping_zones' => ['wc_shipping_zones_list',
            'אזורי המשלוח של החנות: אזורים, שיטות משלוח, מחירים וסף למשלוח חינם.',
            [], []],
        'site_health' => ['wp_health',
            'מצב האתר: גרסאות וורדפרס ו-PHP, SSL ותוספים פעילים.',
            [], []],
        'site_errors' => ['wp_error_log_tail',
            'השורות האחרונות ביומן השגיאות של האתר — כשבעל האתר שואל למה משהו לא עובד או למה האתר איטי. סכמו במילים פשוטות; לעולם אל תצטטו שורות גולמיות.',
            ['lines' => ['type' => 'integer']], []],
        'list_plugins' => ['wp_plugin_list',
            'התוספים המותקנים באתר והאם יש להם עדכון.',
            [], []],
    ];

    /**
     * Keys whose values identify something on the site. Every id a read returns
     * under one of these is remembered for the rest of the turn, and a proposal
     * may only name an id that was seen — see SiteAgentAssistant.
     */
    private const ID_KEYS = ['id', 'order_id', 'product_id', 'subscription_id', 'user_id', 'number', 'created_id', 'updated_id',
        'item_id', 'comment_id', 'term_id', 'menu_id', 'post_id'];

    public function __construct(private McpClient $mcp) {}

    /**
     * The read tools this site can answer, in the model's tool format.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(Site $site): array
    {
        $tools = [];

        foreach (self::reads() as $name => [$pluginTool, $description, $properties, $required]) {
            if (! $this->siteHas($site, $pluginTool) || ! app(SiteAgentPermissions::class)->allowsTool($name)) {
                continue;
            }

            $tools[] = [
                'name' => $name,
                'description' => $description,
                'input_schema' => array_filter([
                    'type' => 'object',
                    'properties' => $properties === [] ? (object) [] : $properties,
                    'required' => $required,
                ], fn ($value): bool => $value !== []),
            ];
        }

        return $tools;
    }

    /**
     * Every plugin tool a read can reach — for the coverage check that keeps
     * the bot in step with what the plugin can do.
     *
     * @return list<string>
     */
    public static function pluginTools(): array
    {
        return array_values(array_unique(array_column(self::reads(), 0)));
    }

    public function isRead(string $name): bool
    {
        return array_key_exists($name, self::reads());
    }

    private static function reads(): array
    {
        return self::READS + SiteAgentExtendedCatalogue::reads() + SiteAgentAcfActions::reads() + SiteAgentCategorySales::reads() + SiteAgentLearnDashActions::reads();
    }

    /**
     * Run one read and return what the model gets back.
     *
     * The plugin's error is passed on as the result rather than thrown: "הזמנה
     * 999 לא נמצאה" is exactly what the model needs to answer the owner, and a
     * read that fails is not a reason to abandon the whole conversation.
     *
     * @param  array<string, mixed>  $input
     * @return array{content: string, is_error: bool, ids: list<int>}
     */
    public function read(Site $site, string $name, array $input): array
    {
        [$pluginTool, , $properties] = self::reads()[$name];

        if (! app(SiteAgentPermissions::class)->allowsTool($name)) {
            return ['content' => SiteAgentPermissions::refusal(), 'is_error' => true, 'ids' => []];
        }
        if (str_starts_with($pluginTool, 'ld_') && ! $this->siteHas($site, $pluginTool)) {
            return ['content' => 'נדרשת סריקת יכולות עדכנית ותוסף סוכן 1.11.0 ומעלה עם LearnDash פעיל.', 'is_error' => true, 'ids' => []];
        }

        $arguments = array_intersect_key($input, $properties);

        try {
            $text = $this->mcp->textContent($this->mcp->callTool($site, $pluginTool, $arguments));
        } catch (\Throwable $e) {
            return ['content' => str_starts_with($pluginTool, 'ld_') ? 'לא ניתן לקרוא את מידע LearnDash באתר. נסו לקרוא שוב לאחר בדיקת החיבור והיכולות.' : Str::limit($e->getMessage(), 400), 'is_error' => true, 'ids' => []];
        }

        $limit = max(1000, (int) config('siteagent.assistant.tool_result_chars', 6000));

        if ($pluginTool === 'wp_error_log_tail') {
            $text = $this->redact($text);
        }

        $data = json_decode($text, true);
        if (str_starts_with($pluginTool, 'ld_')) {
            return SiteAgentLearnDashActions::modelRead($pluginTool, $data, $arguments);
        }
        if ($pluginTool === 'wc_category_sale_get') {
            return SiteAgentCategorySales::modelRead($data, $arguments);
        }
        $ids = str_starts_with($pluginTool, 'jet_cct_')
            ? $this->cctReferences($pluginTool, $data, $arguments)
            : $this->idsIn($data);
        if (str_starts_with($pluginTool, 'wp_acf_')) {
            if (! is_array($data)) {
                return ['content' => 'האתר החזיר מידע ACF לא תקין. יש לנסות לקרוא מחדש.', 'is_error' => true, 'ids' => []];
            }
            $limit = min(48000, max(24000, $limit));
            // ACF identifiers belong to a specific context and field, never a
            // generic post/user id. Sealed state stays outside model context.
            $ids = [];
            if (is_array($data)) {
                if ($pluginTool === 'wp_acf_get' && is_array($data['target'] ?? null)) {
                    foreach ((array) ($data['fields'] ?? []) as $field) {
                        if (is_array($field) && is_string($field['key'] ?? null)
                            && array_key_exists($field['key'], (array) ($data['values'] ?? []))) {
                            $ids[] = SiteAgentAcfActions::reference($data['target'], $field['key']);
                        }
                    }
                }
                unset($data['snapshots'], $data['target']['acf_id']);
                foreach ($data['pages'] ?? [] as $index => $page) {
                    unset($data['pages'][$index]['acf_id']);
                }
                $text = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }
        if ($pluginTool === 'wp_seo_get' && is_array($data) && isset($data['id'])
            && in_array($data['provider'] ?? '', ['yoast', 'rank_math'], true)) {
            $ids[] = 'seo:'.$data['provider'].':'.$data['id'];
        }
        if (in_array($pluginTool, ['wp_theme_list', 'wp_theme_active_get'], true) && is_array($data)) {
            $themes = $pluginTool === 'wp_theme_list' ? $data : [['stylesheet' => data_get($data, 'values.stylesheet')]];
            foreach ($themes as $theme) {
                if (is_array($theme) && is_string($theme['stylesheet'] ?? null) && $theme['stylesheet'] !== '') {
                    $ids[] = 'theme:'.$theme['stylesheet'];
                }
            }
        }

        if (str_starts_with($pluginTool, 'wp_acf_') && mb_strlen($text) > $limit) {
            return ['content' => 'מידע ACF גדול מדי לקריאה מלאה. בחרו field_key יחיד; אם גם השדה לבדו גדול מדי, יש לצמצם אותו באתר לפני עריכה דרך הבוט.', 'is_error' => true, 'ids' => []];
        }

        return [
            'content' => Str::limit($text, $limit, ' …[קוצר]'),
            'is_error' => false,
            'ids' => $ids,
        ];
    }

    /** CCT ids belong to separate tables and must never authorize a WordPress id. */
    private function cctReferences(string $tool, mixed $data, array $arguments): array
    {
        if (! is_array($data)) {
            return [];
        }

        if ($tool === 'jet_cct_types') {
            return array_values(array_map(fn (array $type): string => 'cct-type:'.($type['type'] ?? $type['slug'] ?? ''),
                array_filter((array) ($data['types'] ?? []), 'is_array')));
        }

        $records = $tool === 'jet_cct_get' ? [$data] : (array) ($data['items'] ?? []);
        $refs = [];
        foreach ($records as $record) {
            if (is_array($record) && (int) ($record['id'] ?? 0) > 0
                && ($record['type'] ?? '') === ($arguments['type'] ?? null)) {
                $refs[] = 'cct:'.$record['type'].':'.$record['id'];
            }
        }

        return $refs;
    }

    /**
     * Does this site's plugin have the tool?
     *
     * A site whose tool list was never read is given the benefit of the doubt:
     * the plugin answers an unknown tool with a plain error, which the model
     * then passes on, and refusing everything until a sync ran would make a
     * freshly connected site look broken.
     */
    public function siteHas(Site $site, string $pluginTool): bool
    {
        $known = collect((array) data_get($site->mcp_capabilities, 'tools', []))
            ->pluck('name')
            ->filter()
            ->all();

        if (str_starts_with($pluginTool, 'ld_')) {
            $version = data_get($site->mcp_capabilities, 'server.version');

            return is_string($version) && version_compare($version, '1.11.0', '>=')
                && in_array($pluginTool, $known, true);
        }

        return $known === [] || in_array($pluginTool, $known, true);
    }

    /**
     * An error log with the server's internals taken out.
     *
     * A log names absolute paths, database hosts, sometimes a query with a
     * value in it. The owner needs "the contact-form plugin fails on line 80",
     * not where the files live on the server — and none of it should travel to
     * the model or into a WhatsApp transcript.
     */
    private function redact(string $log): string
    {
        $patterns = [
            // A plugin's or theme's file: keep which plugin, drop the server path.
            '#(?:/[\w.\-]+)*/(plugins|themes)/([\w.\-]+)/(?:[\w.\-]+/)*([\w.\-]+\.php)#u' => '$1/$2/$3',
            // Any other absolute path: keep the file name, drop where it lives.
            '#(?<![\w.\-])(?:/[\w.\-]+)+/([\w.\-]+\.php)#u' => '…/$1',
            '#(?<![\w.\-])(?:/[\w.\-]+){2,}/?#u' => '…',
            // Emails, IP addresses, and anything shaped like a key or token.
            '/[\w.+\-]+@[\w\-]+\.[\w.\-]+/u' => '[email]',
            '/\b\d{1,3}(?:\.\d{1,3}){3}\b/' => '[ip]',
            '/\b[A-Za-z0-9_\-]{32,}\b/' => '[…]',
            '/(password|passwd|pwd|secret|token|key)\s*[=:]\s*\S+/i' => '$1=[…]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $log);
    }

    /**
     * Every identifying number anywhere in a read result.
     *
     * @return list<int>
     */
    private function idsIn(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $ids = [];

        array_walk_recursive($data, function ($value, $key) use (&$ids): void {
            if (in_array($key, self::ID_KEYS, true) && is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        });

        return array_values(array_unique($ids));
    }
}
