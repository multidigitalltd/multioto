<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use Illuminate\Support\Str;

/**
 * Turns a change the assistant wants to make into an offer the owner can read.
 *
 * The model chooses WHAT to change; it never writes what the owner is shown.
 * Every proposal is validated here, the thing it touches is read live from the
 * site, and the preview is built from what the SITE said — the product's real
 * name, the order's real status — so "כן" is consent to the change that will
 * actually run, not to the model's description of it.
 *
 * Two further rules hold for every proposal:
 *
 *  - The target must have been SEEN. An id the model did not get back from a
 *    read in this same turn is refused, so a half-remembered or invented id can
 *    never become a change. "Look it up first" costs one call.
 *  - What cannot be undone says so before the owner approves — a note emailed
 *    to a buyer, a cancelled subscription, a new user — instead of after.
 *
 * A refusal comes back as `error`, worded for the model: it is what lets the
 * model correct itself or explain to the owner, rather than a dead end.
 */
class SiteActionProposer
{
    /** The plugin version that understands name/short_description on a product. */
    private const PRODUCT_TEXT_FIELDS_SINCE = '1.8.0';

    public const ORDER_STATUSES = [
        'pending' => 'ממתינה לתשלום',
        'processing' => 'בטיפול',
        'on-hold' => 'בהמתנה',
        'completed' => 'הושלמה',
        'cancelled' => 'בוטלה',
        'refunded' => 'הוחזרה',
        'failed' => 'נכשלה',
    ];

    /** The order statuses the bot may move an order TO. */
    public const ORDER_TARGETS = ['pending', 'processing', 'on-hold', 'completed', 'cancelled'];

    public const SUBSCRIPTION_STATUSES = [
        'active' => 'פעיל',
        'on-hold' => 'מושהה',
        'cancelled' => 'מבוטל',
        'pending-cancel' => 'יבוטל בסוף התקופה',
        'expired' => 'פג תוקף',
        'pending' => 'ממתין',
    ];

    /** The subscription statuses the bot may set. */
    public const SUBSCRIPTION_TARGETS = ['active', 'on-hold', 'cancelled', 'pending-cancel'];

    public const POST_STATUSES = [
        'publish' => 'מפורסם',
        'draft' => 'טיוטה',
        'private' => 'פרטי',
        'pending' => 'ממתין לאישור',
        'future' => 'מתוזמן',
    ];

    /** Never `administrator` — the plugin refuses it too, but it is not offered here at all. */
    public const ROLES = [
        'subscriber' => 'מנוי',
        'customer' => 'לקוח',
        'contributor' => 'תורם',
        'author' => 'כותב',
        'editor' => 'עורך',
        'shop_manager' => 'מנהל חנות',
    ];

    private const STOCK_STATUSES = [
        'instock' => 'במלאי',
        'outofstock' => 'אזל מהמלאי',
        'onbackorder' => 'בהזמנה מראש',
    ];

    private const PRODUCT_FIELDS = [
        'name' => 'שם',
        'short_description' => 'תיאור קצר',
        'regular_price' => 'מחיר רגיל',
        'sale_price' => 'מחיר מבצע',
        'sale_from' => 'תחילת המבצע',
        'sale_to' => 'סיום המבצע',
        'stock_quantity' => 'מלאי',
        'stock_status' => 'מצב מלאי',
        'status' => 'סטטוס',
    ];

    /** Every proposal tool, by name. */
    private const PROPOSALS = [
        'propose_product_update', 'propose_product_create', 'propose_order_status', 'propose_order_note',
        'propose_subscription_status', 'propose_post_create', 'propose_post_update', 'propose_text_edit',
        'propose_user_create', 'propose_user_role', 'propose_coupon',
        'propose_comment_moderation', 'propose_term_create', 'propose_item_terms', 'propose_fields_update',
        'propose_menu_item_add', 'propose_menu_item_update', 'propose_menu_item_remove',
        'propose_trash', 'propose_coupon_expire', 'propose_cache_flush',
        'propose_plugin_update', 'propose_theme_update', 'propose_plugin_toggle', 'propose_media_delete',
    ];

    /**
     * Plugins that are never switched off from a phone: the connection itself,
     * the shop, the page builder, and anything that guards the site. Switching
     * one off is how a site loses its checkout, its pages or its protection in
     * a single "כן".
     */
    private const CRITICAL_PLUGINS = '/^(multioto-agent|woocommerce|woocommerce-subscriptions|elementor|elementor-pro)\//';

    private const SECURITY_PLUGIN_NAMES = '/security|firewall|wordfence|sucuri|solid|ithemes|limit.?login|two.?factor|2fa|recaptcha/i';

    /** How many plugin updates one "כן" may carry — each one is checked on its own. */
    private const MAX_UPDATES = 10;

    private const COMMENT_STATUSES = ['approve' => 'מאושרת', 'hold' => 'ממתינה לאישור', 'spam' => 'ספאם', 'trash' => 'בפח'];

    /** How much of a long text the preview quotes before saying how much more there is. */
    private const PREVIEW_TEXT = 1200;

    public function __construct(private McpClient $mcp, private SiteAgentToolbox $toolbox) {}

    /**
     * The proposal tools this site can carry out, in the model's tool format.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(Site $site): array
    {
        $out = [];

        foreach ($this->catalogue() as [$name, $pluginTool, $description, $properties, $required]) {
            if ($this->toolbox->siteHas($site, $pluginTool)) {
                $out[] = [
                    'name' => $name,
                    'description' => $description,
                    'input_schema' => array_filter(['type' => 'object', 'properties' => $properties === [] ? (object) [] : $properties, 'required' => $required],
                        fn ($value): bool => $value !== []),
                ];
            }
        }

        return $out;
    }

    /**
     * Every plugin tool a proposal leads to — for the coverage check that keeps
     * the bot in step with what the plugin can do. Read off the same catalogue
     * the definitions come from, so the two cannot disagree.
     *
     * @return list<string>
     */
    public function pluginTools(): array
    {
        // One proposal switches plugins both ways; its row names only one.
        return array_values(array_unique([...array_column($this->catalogue(), 1), 'wp_plugin_activate']));
    }

    /**
     * name, plugin tool, description, properties, required — one row per proposal.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: list<string>}>
     */
    private function catalogue(): array
    {
        return [
            ['propose_product_update', 'wc_product_update',
                'הצעה לעדכן מוצר קיים: שם, תיאור קצר, מחיר רגיל, מחיר מבצע (ריק = סיום המבצע), תאריכי מבצע (YYYY-MM-DD), כמות במלאי, מצב מלאי (instock/outofstock/onbackorder), סטטוס (publish/draft/private). רק השדות שמשתנים.',
                ['product_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'short_description' => ['type' => 'string'],
                    'regular_price' => ['type' => 'string'], 'sale_price' => ['type' => 'string'], 'sale_from' => ['type' => 'string'],
                    'sale_to' => ['type' => 'string'], 'stock_quantity' => ['type' => 'integer'], 'stock_status' => ['type' => 'string'],
                    'status' => ['type' => 'string']], ['product_id']],
            ['propose_product_create', 'wc_product_create',
                'הצעה ליצור מוצר חדש: שם, מחיר, מחיר מבצע, תיאור קצר ומלא, מק"ט, כמות במלאי, קטגוריות מוצרים קיימות (שמות, מ-find_terms עם product_cat) ו-publish=true כדי לפרסם מיד. בלי publish הוא נוצר כטיוטה. תמונה — בעל האתר שולח אותה אחרי שהמוצר נוצר, עם שם המוצר.',
                ['name' => ['type' => 'string'], 'regular_price' => ['type' => 'string'], 'sale_price' => ['type' => 'string'],
                    'short_description' => ['type' => 'string'], 'description' => ['type' => 'string'], 'sku' => ['type' => 'string'],
                    'stock_quantity' => ['type' => 'integer'], 'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'publish' => ['type' => 'boolean']], ['name']],
            ['propose_order_status', 'wc_order_status_set',
                'הצעה לשנות סטטוס הזמנה ל-processing / on-hold / completed / cancelled / pending. החזר כספי אינו אפשרי מכאן.',
                ['order_id' => ['type' => 'integer', 'description' => 'מספר ההזמנה'], 'status' => ['type' => 'string'], 'note' => ['type' => 'string', 'description' => 'הערה פנימית אופציונלית']],
                ['order_id', 'status']],
            ['propose_order_note', 'wc_order_note_add',
                'הצעה להוסיף הערה להזמנה. to_customer=true שולח אותה לקונה באימייל.',
                ['order_id' => ['type' => 'integer'], 'note' => ['type' => 'string'], 'to_customer' => ['type' => 'boolean']], ['order_id', 'note']],
            ['propose_subscription_status', 'wcs_subscription_status_set',
                'הצעה להשהות (on-hold), לחדש (active), לבטל בסוף התקופה (pending-cancel) או לבטל מיד (cancelled) מנוי מתחדש.',
                ['subscription_id' => ['type' => 'integer'], 'status' => ['type' => 'string']], ['subscription_id', 'status']],
            ['propose_post_create', 'wp_content_create',
                'הצעה ליצור פוסט או עמוד. type = post (ברירת מחדל) / page / סוג מותאם; status = draft (ברירת מחדל) או publish. content בטקסט רגיל, פסקאות מופרדות בשורה ריקה.',
                ['type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'],
                    'excerpt' => ['type' => 'string'], 'status' => ['type' => 'string']], ['title', 'content']],
            ['propose_post_update', 'wp_content_update',
                'הצעה לשנות כותרת, סטטוס (publish/draft/private) או תקציר של פוסט/עמוד קיים לפי id. לשינוי טקסט בתוך התוכן — propose_text_edit או edit_page_text.',
                ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'status' => ['type' => 'string'], 'excerpt' => ['type' => 'string']], ['id']],
            ['propose_text_edit', 'wp_content_update',
                'הצעה לשנות טקסט בתוך פוסט מפורסם לפי id: action=replace מחליף את find (ציטוט מדויק שמופיע פעם אחת בתוכן, כפי שהוחזר ב-get_content) ב-text; action=append מוסיף את text בסוף. לא לעמודי אלמנטור — להם edit_page_text.',
                ['id' => ['type' => 'integer'], 'action' => ['type' => 'string', 'enum' => ['replace', 'append']],
                    'find' => ['type' => 'string'], 'text' => ['type' => 'string']], ['id', 'action', 'text']],
            ['propose_user_create', 'wp_user_create',
                'הצעה להוסיף משתמש לאתר. role אחד מ: '.implode(', ', array_keys(self::ROLES)).'. לעולם לא מנהל. WordPress שולח לו קישור לקביעת סיסמה.',
                ['email' => ['type' => 'string'], 'first_name' => ['type' => 'string'], 'last_name' => ['type' => 'string'], 'role' => ['type' => 'string']],
                ['email', 'role']],
            ['propose_user_role', 'wp_user_role_set',
                'הצעה לשנות תפקיד של משתמש קיים. user_id ו-email כפי שהוחזרו ב-find_users. role אחד מ: '.implode(', ', array_keys(self::ROLES)).'.',
                ['user_id' => ['type' => 'integer'], 'email' => ['type' => 'string'], 'role' => ['type' => 'string']], ['user_id', 'email', 'role']],
            ['propose_coupon', 'wc_coupon_create',
                'הצעה ליצור קופון. type = percent / fixed_cart / fixed_product; amount; אופציונלי expires (YYYY-MM-DD), minimum_amount, usage_limit.',
                ['code' => ['type' => 'string'], 'type' => ['type' => 'string'], 'amount' => ['type' => 'string'], 'expires' => ['type' => 'string'],
                    'minimum_amount' => ['type' => 'string'], 'usage_limit' => ['type' => 'integer']], ['code', 'amount']],
            ['propose_comment_moderation', 'wp_comment_moderate',
                'הצעה לטפל בתגובה: status = approve (אישור) / hold (החזרה להמתנה) / spam / trash (לפח). comment_id מתוך find_comments.',
                ['comment_id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => array_keys(self::COMMENT_STATUSES)]], ['comment_id', 'status']],
            ['propose_term_create', 'wp_term_create',
                'הצעה ליצור קטגוריה או תגית חדשה: taxonomy (למשל category, post_tag, product_cat), name, ואופציונלי parent (מזהה קטגוריית אב).',
                ['taxonomy' => ['type' => 'string'], 'name' => ['type' => 'string'], 'parent' => ['type' => 'integer']], ['taxonomy', 'name']],
            ['propose_item_terms', 'wp_post_terms_set',
                'הצעה לשייך פוסט, עמוד או מוצר לקטגוריות/תגיות קיימות לפי שמן: id, taxonomy, terms (שמות מדויקים), mode = add (הוספה, ברירת מחדל) או replace (החלפת כל הרשימה).',
                ['id' => ['type' => 'integer'], 'taxonomy' => ['type' => 'string'], 'terms' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'mode' => ['type' => 'string', 'enum' => ['add', 'replace']]], ['id', 'taxonomy', 'terms']],
            ['propose_fields_update', 'wp_fields_update',
                'הצעה לעדכן שדות מותאמים (ACF וכדומה) בפריט: id ו-fields = אובייקט מפתח→ערך טקסט/מספר. רק מפתחות שקיימים בפריט או בהגדרת השדות (field_schema).',
                ['id' => ['type' => 'integer'], 'fields' => ['type' => 'object']], ['id', 'fields']],
            ['propose_menu_item_add', 'wp_menu_item_add',
                'הצעה להוסיף פריט לתפריט: menu (שם או מזהה מתוך list_menus), title, ואחד מ: page_id (עמוד קיים) או url. אופציונלי parent_id (פריט הורה).',
                ['menu' => ['type' => 'string'], 'title' => ['type' => 'string'], 'page_id' => ['type' => 'integer'], 'url' => ['type' => 'string'], 'parent_id' => ['type' => 'integer']], ['menu', 'title']],
            ['propose_menu_item_update', 'wp_menu_item_update',
                'הצעה לשנות טקסט או קישור של פריט קיים בתפריט: item_id מתוך list_menus, title ו/או url.',
                ['item_id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'url' => ['type' => 'string']], ['item_id']],
            ['propose_menu_item_remove', 'wp_menu_item_unlink',
                'הצעה להסיר פריט מתפריט (העמוד עצמו נשאר): item_id מתוך list_menus.',
                ['item_id' => ['type' => 'integer']], ['item_id']],
            ['propose_trash', 'wp_content_trash',
                'הצעה להעביר פוסט או עמוד לפח לפי id. הפיך — אפשר לבטל.',
                ['id' => ['type' => 'integer']], ['id']],
            ['propose_coupon_expire', 'wc_coupon_expire',
                'הצעה לסיים קופון היום (הוא לא נמחק): code מתוך list_coupons.',
                ['code' => ['type' => 'string']], ['code']],
            ['propose_plugin_update', 'wp_plugin_update',
                'הצעה לעדכן תוספים שיש להם עדכון (לפי plugin_list). plugins = שמות או קבצי התוספים, או ["all"] לכל מה שממתין לעדכון (עד 10). אחרי כל עדכון נבדק שהאתר עולה.',
                ['plugins' => ['type' => 'array', 'items' => ['type' => 'string']]], ['plugins']],
            ['propose_theme_update', 'wp_theme_update',
                'הצעה לעדכן תבנית שיש לה עדכון: stylesheet מתוך list_themes. אחרי העדכון נבדק שהאתר עולה.',
                ['stylesheet' => ['type' => 'string']], ['stylesheet']],
            ['propose_plugin_toggle', 'wp_plugin_deactivate',
                'הצעה להפעיל (active=true) או לכבות (active=false) תוסף מותקן: plugin = שם או קובץ מתוך list_plugins. תוספים קריטיים (החנות, אלמנטור, אבטחה, תוסף החיבור) אינם נכבים מכאן.',
                ['plugin' => ['type' => 'string'], 'active' => ['type' => 'boolean']], ['plugin', 'active']],
            ['propose_media_delete', 'wp_media_delete',
                'הצעה למחוק קובץ מספריית המדיה לפי attachment_id מתוך find_media. מחיקה סופית — אין ביטול.',
                ['attachment_id' => ['type' => 'integer']], ['attachment_id']],
            ['propose_cache_flush', 'wp_cache_flush',
                'הצעה לנקות את המטמון של האתר — כשבעל האתר אומר ששינוי לא מופיע באתר.',
                [], []],
        ];
    }

    /**
     * Is this one of the proposal tools — by exact name.
     *
     * A closed list, not "starts with propose_": the name comes from the model,
     * and a name resolved into a method call must never reach anything but the
     * methods written for it.
     */
    public function isProposal(string $name): bool
    {
        return in_array($name, self::PROPOSALS, true);
    }

    /**
     * Validate a proposal against the live site and build its offer.
     *
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen  ids the reads of this turn returned
     * @return array{plan: array<string, mixed>, preview: string}|array{error: string}
     */
    public function propose(Site $site, string $name, array $input, array $seen): array
    {
        if (! $this->isProposal($name)) {
            return $this->error("אין כלי בשם {$name}.");
        }

        try {
            return $this->{Str::camel($name)}($site, $input, $seen);
        } catch (\Throwable $e) {
            // The site answered with an error, or did not answer: the model is
            // told, so it can say so — never a half-built offer.
            return $this->error('לא הצלחתי לקרוא את הנתונים מהאתר: '.Str::limit($e->getMessage(), 300));
        }
    }

    // --- Products ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeProductUpdate(Site $site, array $input, array $seen): array
    {
        $productId = (int) ($input['product_id'] ?? 0);

        if (! $this->wasSeen($productId, $seen)) {
            return $this->unseen('המוצר', 'find_products');
        }

        $fields = [];

        foreach (array_keys(self::PRODUCT_FIELDS) as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null) {
                $fields[$field] = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
            }
        }

        if ($fields === []) {
            return $this->error('לא צוין שום שדה לשינוי.');
        }

        if ((isset($fields['name']) || isset($fields['short_description'])) && ! $this->pluginAtLeast($site, self::PRODUCT_TEXT_FIELDS_SINCE)) {
            return $this->error('שינוי שם או תיאור של מוצר דורש עדכון של תוסף הסוכן באתר. מחיר, מבצע ומלאי אפשר לשנות כבר עכשיו.');
        }

        if (($problem = $this->productFieldProblem($fields)) !== null) {
            return $this->error($problem);
        }

        $product = $this->json($site, 'wc_product_get', ['product_id' => $productId]);

        if (! isset($product['id'])) {
            return $this->error("המוצר {$productId} לא נמצא בחנות.");
        }

        // A field already at the requested value is not a change, and showing
        // "100 ← 100" in a preview is a preview nobody can read.
        $fields = array_filter($fields, fn ($value, string $field): bool => (string) ($product[$field] ?? '') !== (string) $value, ARRAY_FILTER_USE_BOTH);

        if ($fields === []) {
            return $this->error('המוצר כבר במצב המבוקש — אין מה לשנות.');
        }

        $regular = (string) ($fields['regular_price'] ?? $product['regular_price'] ?? '');
        $sale = (string) ($fields['sale_price'] ?? $product['sale_price'] ?? '');

        if ($sale !== '' && $regular !== '' && $this->agorot($sale) >= $this->agorot($regular)) {
            return $this->error("מחיר המבצע ({$sale}) חייב להיות נמוך מהמחיר הרגיל ({$regular}).");
        }

        $name = (string) $product['name'];
        $lines = ["🛒 מוצר: {$name}"];

        foreach ($fields as $field => $value) {
            $lines[] = $this->productLine($field, $product[$field] ?? null, $value);
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_PRODUCT,
                'product_id' => $productId,
                'product_name' => $name,
                'fields' => $fields,
                'current' => array_intersect_key($product, $fields),
                'summary' => "עדכון המוצר {$name}",
            ],
            'preview' => implode("\n", $lines),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeProductCreate(Site $site, array $input, array $seen): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 200) {
            return $this->error('חסר שם למוצר, או שהוא ארוך מ-200 תווים.');
        }

        $fields = array_filter([
            'name' => $name,
            'regular_price' => trim((string) ($input['regular_price'] ?? '')),
            'short_description' => trim((string) ($input['short_description'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'sku' => trim((string) ($input['sku'] ?? '')),
        ], fn (string $value): bool => $value !== '');

        $extra = $this->newProductExtras($input, $fields['regular_price'] ?? null);

        if (is_string($extra)) {
            return $this->error($extra);
        }

        $categories = $this->productCategories($site, (array) ($input['categories'] ?? []));

        if (is_string($categories)) {
            return $this->error($categories);
        }

        $publish = ($extra['status'] ?? null) === 'publish';

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_PRODUCT_CREATE,
                'fields' => $fields,
                'extra' => $extra,
                'category_ids' => array_keys($categories),
                'summary' => "יצירת המוצר {$name}",
            ],
            'preview' => implode("\n", array_filter([
                "🛒 מוצר חדש: {$name}",
                isset($fields['regular_price']) ? "מחיר: {$fields['regular_price']} ₪" : null,
                isset($extra['sale_price']) ? "מחיר מבצע: {$extra['sale_price']} ₪" : null,
                isset($fields['sku']) ? "מק\"ט: {$fields['sku']}" : null,
                isset($extra['stock_quantity']) ? "במלאי: {$extra['stock_quantity']}" : null,
                $categories !== [] ? 'קטגוריות: '.implode(', ', $categories) : null,
                isset($fields['short_description']) ? 'תיאור קצר: "'.$this->quote($fields['short_description']).'"' : null,
                isset($fields['description']) ? 'תיאור: "'.$this->quote($fields['description']).'"' : null,
                '',
                $publish
                    ? 'המוצר יפורסם באתר מיד וייפתח לרכישה.'
                    : 'המוצר ייווצר כטיוטה, ולא יוצג באתר עד שתבקשו לפרסם אותו.',
                'לתמונה: אחרי שהמוצר נוצר, שלחו אותה כאן עם שם המוצר.',
            ], fn (?string $line): bool => $line !== null)),
        ];
    }

    /**
     * What a new product carries beyond what the plugin's create takes: sale
     * price, stock and whether it goes live. Applied by an update right after
     * the create, so the plugin's create stays a draft-only primitive.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|int>|string the extras, or why they are refused
     */
    private function newProductExtras(array $input, ?string $regular): array|string
    {
        $extra = [];
        $sale = trim((string) ($input['sale_price'] ?? ''));

        foreach (array_filter([$regular, $sale]) as $price) {
            if (! $this->isPrice($price)) {
                return 'מחיר חייב להיות מספר, עם עד שתי ספרות אחרי הנקודה.';
            }
        }

        if ($sale !== '') {
            if ($regular === null || $this->agorot($sale) >= $this->agorot($regular)) {
                return 'מחיר מבצע צריך מחיר רגיל, והוא חייב להיות נמוך ממנו.';
            }

            $extra['sale_price'] = $sale;
        }

        if (isset($input['stock_quantity'])) {
            $stock = filter_var($input['stock_quantity'], FILTER_VALIDATE_INT);

            if ($stock === false || $stock < 0) {
                return 'כמות במלאי חייבת להיות מספר שלם, 0 ומעלה.';
            }

            $extra['stock_quantity'] = $stock;
        }

        if (filter_var($input['publish'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            // A product live without a price is a product nobody can buy.
            if ($regular === null) {
                return 'כדי לפרסם מוצר צריך מחיר. בלי מחיר אפשר ליצור אותו כטיוטה.';
            }

            $extra['status'] = 'publish';
        }

        return $extra;
    }

    /**
     * Existing product categories by name, as id => name.
     *
     * Only categories the shop already has: a typo would otherwise become a
     * new category on the live menu, and the plugin refuses unknown names at
     * execution time anyway — better said in the preview than after the "כן".
     *
     * @param  array<int, mixed>  $names
     * @return array<int, string>|string the categories, or why they are refused
     */
    private function productCategories(Site $site, array $names): array|string
    {
        $names = array_values(array_unique(array_filter(array_map(fn ($name): string => trim((string) $name), $names))));

        if (count($names) > 10) {
            return 'עד 10 קטגוריות למוצר.';
        }

        $found = [];

        foreach ($names as $name) {
            $terms = (array) ($this->json($site, 'wp_term_list', ['taxonomy' => 'product_cat', 'search' => $name, 'limit' => 20])['terms'] ?? []);
            $match = collect($terms)->first(fn ($term): bool => mb_strtolower(trim((string) data_get($term, 'name'))) === mb_strtolower($name));

            if ($match === null) {
                return "אין באתר קטגוריית מוצרים בשם \"{$name}\". אפשר ליצור אותה קודם (propose_term_create) או לבחור קיימת (find_terms).";
            }

            $found[(int) data_get($match, 'id')] = (string) data_get($match, 'name');
        }

        return $found;
    }

    // --- Orders --------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeOrderStatus(Site $site, array $input, array $seen): array
    {
        $number = (int) ($input['order_id'] ?? 0);
        $status = $this->bareStatus((string) ($input['status'] ?? ''));

        if (! $this->wasSeen($number, $seen)) {
            return $this->unseen('ההזמנה', 'find_orders');
        }

        if (! in_array($status, self::ORDER_TARGETS, true)) {
            return $this->error('אפשר להעביר הזמנה רק ל: '.implode(', ', self::ORDER_TARGETS).'. החזר כספי נעשה בניהול האתר בלבד.');
        }

        $order = $this->json($site, 'wc_order_get', ['order_id' => $number]);

        if (! isset($order['id'], $order['status'])) {
            return $this->error("הזמנה {$number} לא נמצאה.");
        }

        $from = (string) $order['status'];

        if ($from === $status) {
            return $this->error('ההזמנה כבר בסטטוס '.$this->orderLabel($status).'.');
        }

        if ($from === 'refunded') {
            return $this->error('ההזמנה הוחזרה כספית, ולכן הסטטוס שלה אינו משתנה מכאן.');
        }

        $note = trim((string) ($input['note'] ?? ''));
        $orderNumber = (string) ($order['number'] ?? $order['id']);

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_ORDER_STATUS,
                'order_id' => (int) $order['id'],
                'order_number' => $orderNumber,
                'from' => $from,
                'to' => $status,
                'note' => $note,
                'summary' => "הזמנה #{$orderNumber}: {$from} → {$status}",
            ],
            'preview' => implode("\n", array_filter([
                $this->orderHeading($order),
                'סטטוס: '.$this->orderLabel($from).' ← '.$this->orderLabel($status),
                $note !== '' ? 'הערה פנימית: "'.$this->quote($note).'"' : null,
                match ($status) {
                    'completed' => 'WooCommerce ישלח ללקוח אימייל שההזמנה הושלמה.',
                    'cancelled' => 'הפריטים יחזרו למלאי. ביטול אינו מחזיר כסף — החזר כספי נעשה בניהול האתר.',
                    'processing' => 'אם ההזמנה לא נשלחה עדיין ללקוח כ"בטיפול", WooCommerce ישלח לו אימייל.',
                    default => null,
                },
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeOrderNote(Site $site, array $input, array $seen): array
    {
        $number = (int) ($input['order_id'] ?? 0);
        $note = trim((string) ($input['note'] ?? ''));
        $toCustomer = filter_var($input['to_customer'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $this->wasSeen($number, $seen)) {
            return $this->unseen('ההזמנה', 'find_orders');
        }

        if ($note === '' || mb_strlen($note) > 2000) {
            return $this->error('ההערה ריקה או ארוכה מ-2000 תווים.');
        }

        $order = $this->json($site, 'wc_order_get', ['order_id' => $number]);

        if (! isset($order['id'])) {
            return $this->error("הזמנה {$number} לא נמצאה.");
        }

        $orderNumber = (string) ($order['number'] ?? $order['id']);

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_ORDER_NOTE,
                'order_id' => (int) $order['id'],
                'order_number' => $orderNumber,
                'note' => $note,
                'to_customer' => $toCustomer,
                'summary' => "הערה להזמנה #{$orderNumber}",
            ],
            'preview' => implode("\n", [
                $this->orderHeading($order),
                ($toCustomer ? 'הודעה ללקוח' : 'הערה פנימית').':',
                '"'.$note.'"',
                '',
                $toCustomer
                    ? '⚠️ תישלח ללקוח באימייל מיד, ואי אפשר להחזיר אותה.'
                    : 'תיראה רק בניהול ההזמנה, לא ללקוח.',
            ]),
        ];
    }

    // --- Subscriptions -------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeSubscriptionStatus(Site $site, array $input, array $seen): array
    {
        $id = (int) ($input['subscription_id'] ?? 0);
        $status = $this->bareStatus((string) ($input['status'] ?? ''));

        if (! $this->wasSeen($id, $seen)) {
            return $this->unseen('המנוי', 'find_subscriptions');
        }

        if (! in_array($status, self::SUBSCRIPTION_TARGETS, true)) {
            return $this->error('אפשר להעביר מנוי רק ל: '.implode(', ', self::SUBSCRIPTION_TARGETS).'.');
        }

        $subscription = $this->json($site, 'wcs_subscription_get', ['subscription_id' => $id]);

        if (! isset($subscription['id'], $subscription['status'])) {
            return $this->error("מנוי {$id} לא נמצא.");
        }

        $from = (string) $subscription['status'];

        if ($from === $status) {
            return $this->error('המנוי כבר '.$this->subscriptionLabel($status).'.');
        }

        if (in_array($from, ['cancelled', 'expired'], true)) {
            return $this->error('המנוי '.$this->subscriptionLabel($from).' ולא ניתן להחזיר אותו לפעילות. מנוי חדש נפתח בהזמנה חדשה.');
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_SUBSCRIPTION_STATUS,
                'subscription_id' => $id,
                'from' => $from,
                'to' => $status,
                'summary' => "מנוי #{$id}: {$from} → {$status}",
            ],
            'preview' => implode("\n", array_filter([
                "🔁 מנוי #{$id} — ".trim((string) ($subscription['customer'] ?? '')).', '
                    .($subscription['total'] ?? '').' '.($subscription['currency'] ?? ''),
                'סטטוס: '.$this->subscriptionLabel($from).' ← '.$this->subscriptionLabel($status),
                match ($status) {
                    'cancelled' => '⚠️ ביטול מיידי הוא סופי — מנוי שבוטל אי אפשר לחדש, וגם "בטל" לא יחזיר אותו.',
                    'pending-cancel' => 'המנוי יסתיים בסוף התקופה ששולמה, ולא יחויב שוב.',
                    'on-hold' => 'החיובים האוטומטיים ייעצרו עד שתחדשו את המנוי.',
                    'active' => 'החיובים האוטומטיים יחזרו לפי מועד החיוב הבא'
                        .(isset($subscription['next_payment']) ? " ({$subscription['next_payment']})" : '').'.',
                    default => null,
                },
            ])),
        ];
    }

    // --- Content -------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposePostCreate(Site $site, array $input, array $seen): array
    {
        $type = strtolower(trim((string) ($input['type'] ?? 'post'))) ?: 'post';
        $title = trim((string) ($input['title'] ?? ''));
        $content = trim((string) ($input['content'] ?? ''));
        $excerpt = trim((string) ($input['excerpt'] ?? ''));
        $status = (string) ($input['status'] ?? 'draft');

        if (preg_match('/^[a-z0-9_\-]{1,20}$/', $type) !== 1) {
            return $this->error('סוג תוכן לא תקין.');
        }

        if ($title === '' || mb_strlen($title) > 200) {
            return $this->error('חסרה כותרת, או שהיא ארוכה מ-200 תווים.');
        }

        if ($content === '' || mb_strlen($content) > 20000) {
            return $this->error('התוכן ריק או ארוך מ-20,000 תווים.');
        }

        if (! in_array($status, ['draft', 'publish'], true)) {
            return $this->error('status חייב להיות draft או publish.');
        }

        // What goes live has to be what the owner read. A preview quotes only
        // the beginning of a long text, so a long text is created as a draft —
        // to be read in full on the site and published from there or with
        // propose_post_update — never published on a "כן" to a fragment.
        $shortened = $status === 'publish' && mb_strlen($content) > self::PREVIEW_TEXT;

        if ($shortened) {
            $status = 'draft';
        }

        $kind = $type === 'page' ? 'עמוד' : ($type === 'post' ? 'פוסט' : "פריט ({$type})");

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_POST_CREATE,
                'fields' => array_filter(compact('type', 'title', 'content', 'excerpt', 'status'), fn (string $v): bool => $v !== ''),
                'summary' => "יצירת {$kind}: {$title}",
            ],
            'preview' => implode("\n", array_filter([
                "📝 {$kind} חדש: \"{$title}\"",
                'סטטוס: '.self::POST_STATUSES[$status].($status === 'publish' ? ' — יופיע באתר מיד' : ' — לא יופיע באתר עד שתפרסמו'),
                $shortened ? 'התוכן ארוך מכדי להציג כאן במלואו, ולכן הוא ייווצר כטיוטה. קראו אותו באתר, ואז בקשו ממני לפרסם.' : null,
                $excerpt !== '' ? 'תקציר: "'.$this->quote($excerpt).'"' : null,
                'תוכן:',
                '"'.$this->quote($content).'"',
            ], fn (?string $line): bool => $line !== null)),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposePostUpdate(Site $site, array $input, array $seen): array
    {
        $id = (int) ($input['id'] ?? 0);

        if (! $this->wasSeen($id, $seen)) {
            return $this->unseen('הפריט', 'find_content');
        }

        $fields = array_filter([
            'title' => isset($input['title']) ? trim((string) $input['title']) : null,
            'status' => isset($input['status']) ? trim((string) $input['status']) : null,
            'excerpt' => isset($input['excerpt']) ? trim((string) $input['excerpt']) : null,
        ], fn (?string $value): bool => $value !== null);

        if (isset($fields['title']) && ($fields['title'] === '' || mb_strlen($fields['title']) > 200)) {
            return $this->error('כותרת ריקה או ארוכה מ-200 תווים.');
        }

        if (isset($fields['status']) && ! in_array($fields['status'], ['publish', 'draft', 'private'], true)) {
            return $this->error('status חייב להיות publish, draft או private.');
        }

        $post = $this->json($site, 'wp_content_get', ['id' => $id]);

        if (! isset($post['id'])) {
            return $this->error("פריט התוכן {$id} לא נמצא.");
        }

        $fields = array_filter($fields, fn (string $value, string $field): bool => (string) ($post[$field] ?? '') !== $value, ARRAY_FILTER_USE_BOTH);

        if ($fields === []) {
            return $this->error('הפריט כבר במצב המבוקש — אין מה לשנות.');
        }

        $title = (string) ($post['title'] ?? '');
        $lines = ["📄 {$title}"];

        foreach ($fields as $field => $value) {
            $lines[] = match ($field) {
                'title' => 'כותרת: "'.$title.'" ← "'.$value.'"',
                'status' => 'סטטוס: '.$this->postLabel((string) ($post['status'] ?? '')).' ← '.$this->postLabel($value)
                    .($value === 'publish' ? ' — יופיע באתר' : ' — יוסר מהאתר'),
                default => 'תקציר: "'.$this->quote($value).'"',
            };
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_POST_UPDATE,
                'post_id' => $id,
                'post_title' => $title,
                'fields' => $fields,
                'current' => array_intersect_key($post, $fields),
                'summary' => "עדכון {$title}",
            ],
            'preview' => implode("\n", $lines),
        ];
    }

    /**
     * A text change inside a post, carried out by the page path that already
     * re-reads the content and refuses a page that moved.
     *
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeTextEdit(Site $site, array $input, array $seen): array
    {
        $id = (int) ($input['id'] ?? 0);
        $action = (string) ($input['action'] ?? '');
        $find = (string) ($input['find'] ?? '');
        $text = trim((string) ($input['text'] ?? ''));

        if (! $this->wasSeen($id, $seen)) {
            return $this->unseen('הפריט', 'find_content');
        }

        if (! in_array($action, ['replace', 'append'], true) || $text === '') {
            return $this->error('action חייב להיות replace או append, ו-text אינו יכול להיות ריק.');
        }

        $post = $this->json($site, 'wp_content_get', ['id' => $id]);

        if (! isset($post['id'], $post['content'])) {
            return $this->error("פריט התוכן {$id} לא נמצא.");
        }

        if ((bool) ($post['built_with_elementor'] ?? false)) {
            return $this->error('העמוד בנוי באלמנטור — לשינוי טקסט בו השתמשו ב-edit_page_text.');
        }

        if (($post['status'] ?? '') !== 'publish') {
            return $this->error('אפשר לערוך כך רק פריט מפורסם. לטיוטה — אפשר לפרסם קודם עם propose_post_update.');
        }

        // The quote must be there exactly once, in the content as the site has
        // it — the same test the execution repeats. A quote the model could not
        // reproduce, or one that appears twice, is a guess about where to edit.
        if ($action === 'replace' && ($find === '' || mb_substr_count((string) $post['content'], $find) !== 1)) {
            return $this->error('הטקסט להחלפה (find) חייב להופיע בתוכן בדיוק פעם אחת, כפי שהוא מופיע ב-get_content.');
        }

        $title = (string) ($post['title'] ?? '');

        return [
            'plan' => [
                'operation' => $action === 'replace' ? SiteAgentRequest::OP_REPLACE : SiteAgentRequest::OP_APPEND,
                'page_id' => $id,
                'page_title' => $title,
                'find' => $action === 'replace' ? $find : null,
                'text' => $text,
                'summary' => "עריכת טקסט ב{$title}",
            ],
            'preview' => $action === 'replace'
                ? implode("\n", ["📄 {$title}", 'להחליף את:', '"'.$find.'"', '', 'ב:', '"'.$text.'"'])
                : implode("\n", ["📄 {$title}", 'להוסיף בסוף:', '"'.$text.'"']),
        ];
    }

    // --- Users ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeUserCreate(Site $site, array $input, array $seen): array
    {
        $email = Str::lower(trim((string) ($input['email'] ?? '')));
        $role = (string) ($input['role'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->error('כתובת האימייל אינה תקינה.');
        }

        if (! array_key_exists($role, self::ROLES)) {
            return $this->error('תפקיד חייב להיות אחד מ: '.implode(', ', array_keys(self::ROLES)).'. מנהל אתר לא מוסיפים מכאן.');
        }

        $fields = array_filter([
            'email' => $email,
            'first_name' => trim((string) ($input['first_name'] ?? '')),
            'last_name' => trim((string) ($input['last_name'] ?? '')),
            'role' => $role,
        ], fn (string $value): bool => $value !== '');

        $name = trim(($fields['first_name'] ?? '').' '.($fields['last_name'] ?? ''));

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_USER_CREATE,
                'fields' => $fields,
                'summary' => "הוספת המשתמש {$email}",
            ],
            'preview' => implode("\n", array_filter([
                "👤 משתמש חדש: {$email}",
                $name !== '' ? "שם: {$name}" : null,
                'תפקיד: '.self::ROLES[$role],
                '',
                'WordPress ישלח לו אימייל עם קישור לקביעת סיסמה.',
                'הסרת משתמש אינה נעשית מכאן — אם תתחרטו, נעשה זאת בניהול האתר.',
            ], fn (?string $line): bool => $line !== null)),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeUserRole(Site $site, array $input, array $seen): array
    {
        $userId = (int) ($input['user_id'] ?? 0);
        $role = (string) ($input['role'] ?? '');

        if (! $this->wasSeen($userId, $seen)) {
            return $this->unseen('המשתמש', 'find_users');
        }

        if (! array_key_exists($role, self::ROLES)) {
            return $this->error('תפקיד חייב להיות אחד מ: '.implode(', ', array_keys(self::ROLES)).'. הרשאת מנהל אינה ניתנת מכאן.');
        }

        $user = $this->findUser($site, $userId, (string) ($input['email'] ?? ''));

        if ($user === null) {
            return $this->error('המשתמש לא נמצא. חפשו אותו שוב עם find_users ושלחו את user_id ואת email שלו.');
        }

        $roles = array_values((array) ($user['roles'] ?? []));

        if (in_array('administrator', $roles, true) || ($user['editable'] ?? true) === false) {
            return $this->error('זה מנהל האתר — התפקיד שלו אינו משתנה מכאן.');
        }

        if (count($roles) > 1) {
            return $this->error('למשתמש יש כמה תפקידים, ושינוי מכאן היה מוחק את השאר. זה נעשה ידנית בניהול האתר.');
        }

        $from = (string) ($roles[0] ?? '');

        if ($from === $role) {
            return $this->error('למשתמש כבר יש את התפקיד הזה.');
        }

        $label = trim((string) ($user['display_name'] ?? '')) ?: (string) ($user['login'] ?? '');

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_USER_ROLE,
                'user_id' => $userId,
                'email' => (string) ($user['email'] ?? ''),
                'from' => $from,
                'to' => $role,
                'summary' => "שינוי תפקיד ל-{$label}",
            ],
            'preview' => implode("\n", [
                "👤 {$label} ({$user['email']})",
                'תפקיד: '.(self::ROLES[$from] ?? ($from !== '' ? $from : 'ללא')).' ← '.self::ROLES[$role],
            ]),
        ];
    }

    // --- Coupons -------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeCoupon(Site $site, array $input, array $seen): array
    {
        $code = Str::lower(trim((string) ($input['code'] ?? '')));
        $type = (string) ($input['type'] ?? 'percent');
        $amount = trim((string) ($input['amount'] ?? ''));
        $expires = trim((string) ($input['expires'] ?? ''));
        $minimum = trim((string) ($input['minimum_amount'] ?? ''));
        $usage = (int) ($input['usage_limit'] ?? 0);

        if (preg_match('/^[\p{L}\p{N}_\-]{3,40}$/u', $code) !== 1) {
            return $this->error('קוד קופון: 3 עד 40 אותיות, ספרות, מקף או קו תחתון.');
        }

        if (! in_array($type, ['percent', 'fixed_cart', 'fixed_product'], true)) {
            return $this->error('type חייב להיות percent, fixed_cart או fixed_product.');
        }

        if (! $this->isPrice($amount) || $this->agorot($amount) <= 0 || ($type === 'percent' && $this->agorot($amount) > 10000)) {
            return $this->error('גובה ההנחה אינו תקין (באחוזים: עד 100).');
        }

        if ($expires !== '' && ! $this->isFutureDate($expires)) {
            return $this->error('תאריך התפוגה חייב להיות תאריך עתידי בפורמט YYYY-MM-DD.');
        }

        if ($minimum !== '' && ! $this->isPrice($minimum)) {
            return $this->error('סכום המינימום אינו מספר תקין.');
        }

        $fields = array_filter([
            'code' => $code,
            'type' => $type,
            'amount' => $amount,
            'expires' => $expires,
            'minimum_amount' => $minimum,
            'usage_limit' => $usage > 0 ? $usage : null,
        ], fn ($value): bool => $value !== '' && $value !== null);

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_COUPON,
                'fields' => $fields,
                'summary' => "יצירת הקופון {$code}",
            ],
            'preview' => implode("\n", array_filter([
                '🏷️ קופון חדש: '.Str::upper($code),
                'הנחה: '.match ($type) {
                    'percent' => "{$amount}%",
                    'fixed_cart' => "{$amount} ₪ על כל הסל",
                    default => "{$amount} ₪ לכל מוצר",
                },
                $expires !== '' ? "בתוקף עד: {$expires}" : 'ללא תאריך תפוגה',
                $minimum !== '' ? "בקנייה מעל {$minimum} ₪" : null,
                $usage > 0 ? "עד {$usage} שימושים" : null,
            ], fn (?string $line): bool => $line !== null)),
        ];
    }

    // --- Comments, categories, fields ---------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeCommentModeration(Site $site, array $input, array $seen): array
    {
        $id = (int) ($input['comment_id'] ?? 0);
        $status = (string) ($input['status'] ?? '');

        if (! $this->wasSeen($id, $seen)) {
            return $this->unseen('התגובה', 'find_comments');
        }

        if (! array_key_exists($status, self::COMMENT_STATUSES)) {
            return $this->error('status חייב להיות approve, hold, spam או trash. מחיקה סופית אינה אפשרית מכאן.');
        }

        // Asked for by id; an older plugin ignores that and answers with the
        // newest page, which is searched as before.
        $comment = collect((array) ($this->json($site, 'wp_comment_list', ['status' => 'all', 'id' => $id, 'limit' => 50])['comments'] ?? []))
            ->first(fn ($item): bool => (int) ($item['id'] ?? 0) === $id);

        if ($comment === null) {
            return $this->error('לא הצלחתי לקרוא את התגובה הזו מהאתר. אם היא ישנה, ייתכן שתוסף הסוכן באתר צריך עדכון.');
        }

        $from = (string) ($comment['status'] ?? '');

        if ($from === $status) {
            return $this->error('התגובה כבר '.self::COMMENT_STATUSES[$status].'.');
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_COMMENT,
                'comment_id' => $id,
                'from' => $from,
                'to' => $status,
                'summary' => "תגובה #{$id}: {$from} → {$status}",
            ],
            'preview' => implode("\n", [
                '💬 תגובה של '.trim((string) ($comment['author'] ?? '')).' על "'.($comment['post_title'] ?? '').'":',
                '"'.$this->quote((string) ($comment['text'] ?? '')).'"',
                'מצב: '.(self::COMMENT_STATUSES[$from] ?? $from).' ← '.self::COMMENT_STATUSES[$status],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeTermCreate(Site $site, array $input, array $seen): array
    {
        $taxonomy = strtolower(trim((string) ($input['taxonomy'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $parent = (int) ($input['parent'] ?? 0);

        if (preg_match('/^[a-z0-9_\-]{1,32}$/', $taxonomy) !== 1 || $name === '' || mb_strlen($name) > 100) {
            return $this->error('צריך taxonomy תקין (למשל category או product_cat) ושם של עד 100 תווים.');
        }

        if ($parent > 0 && ! $this->wasSeen($parent, $seen)) {
            return $this->unseen('קטגוריית האב', 'find_terms');
        }

        // An existing name is a duplicate in waiting — the shop would then
        // carry two "מבצעים" and nobody could tell which one a product is in.
        $existing = collect((array) ($this->json($site, 'wp_term_list', ['taxonomy' => $taxonomy, 'search' => $name, 'limit' => 20])['terms'] ?? []))
            ->first(fn ($term): bool => mb_strtolower((string) ($term['name'] ?? '')) === mb_strtolower($name));

        if ($existing !== null) {
            return $this->error("כבר קיימת \"{$name}\" (מזהה ".($existing['id'] ?? '?').'). אפשר לשייך אליה עם propose_item_terms.');
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_TERM_CREATE,
                'fields' => array_filter(['taxonomy' => $taxonomy, 'name' => $name, 'parent' => $parent > 0 ? $parent : null]),
                'summary' => "קטגוריה חדשה: {$name}",
            ],
            'preview' => implode("\n", array_filter([
                "🏷️ קטגוריה חדשה: \"{$name}\" ({$taxonomy})",
                $parent > 0 ? "בתוך קטגוריה #{$parent}" : null,
                'אין ביטול אוטומטי ליצירת קטגוריה — קטגוריה ריקה אינה מוצגת באתר, ואפשר למחוק אותה בניהול.',
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeItemTerms(Site $site, array $input, array $seen): array
    {
        $id = (int) ($input['id'] ?? 0);
        $taxonomy = strtolower(trim((string) ($input['taxonomy'] ?? '')));
        $names = array_values(array_filter(array_map(fn ($name): string => trim((string) $name), (array) ($input['terms'] ?? []))));
        $mode = (string) ($input['mode'] ?? 'add');

        if (! $this->wasSeen($id, $seen)) {
            return $this->unseen('הפריט', 'find_content או find_products');
        }

        if ($names === [] || ! in_array($mode, ['add', 'replace'], true)) {
            return $this->error('צריך לפחות קטגוריה אחת, ו-mode = add או replace.');
        }

        $current = $this->json($site, 'wp_post_terms_get', ['id' => $id, 'taxonomy' => $taxonomy]);

        if (! isset($current['term_ids'])) {
            return $this->error('לא הצלחתי לקרוא את הקטגוריות של הפריט. ודאו שה-taxonomy נכון (list_taxonomies).');
        }

        $before = (array) ($current['terms'] ?? []);
        $after = $mode === 'replace' ? $names : array_values(array_unique([...$before, ...$names]));

        if ($after == $before) {
            return $this->error('הפריט כבר משויך בדיוק לקטגוריות האלה.');
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_POST_TERMS,
                'id' => $id,
                'taxonomy' => $taxonomy,
                'terms' => $names,
                'mode' => $mode,
                'current_ids' => array_map('intval', (array) $current['term_ids']),
                'summary' => "שיוך פריט #{$id} ל-{$taxonomy}",
            ],
            'preview' => implode("\n", [
                "🗂️ פריט #{$id} — {$taxonomy}",
                'עכשיו: '.($before !== [] ? implode(', ', $before) : '—'),
                'אחרי: '.implode(', ', $after),
                'קטגוריה שאינה קיימת לא תיווצר — השיוך ייכשל ותצטרכו ליצור אותה קודם.',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeFieldsUpdate(Site $site, array $input, array $seen): array
    {
        $id = (int) ($input['id'] ?? 0);
        $fields = (array) ($input['fields'] ?? []);

        if (! $this->wasSeen($id, $seen)) {
            return $this->unseen('הפריט', 'find_content');
        }

        if ($fields === [] || count($fields) > 10) {
            return $this->error('צריך בין שדה אחד לעשרה.');
        }

        foreach ($fields as $key => $value) {
            if (! is_string($key) || str_starts_with($key, '_') || ! (is_scalar($value) || $value === null)) {
                return $this->error("השדה {$key} אינו שדה שאפשר לעדכן מכאן (רק ערכי טקסט או מספר, לא שדות פנימיים או רשימות).");
            }
        }

        $post = $this->json($site, 'wp_content_get', ['id' => $id]);

        if (! isset($post['id'])) {
            return $this->error("פריט התוכן {$id} לא נמצא.");
        }

        $current = (array) ($post['fields'] ?? []);
        $known = array_keys($current);

        // A key the item does not have may still be defined for its type.
        // Anything else would create a field nobody reads.
        if (array_diff(array_keys($fields), $known) !== []) {
            $schema = (array) $this->json($site, 'wp_fields_schema', ['type' => (string) ($post['type'] ?? 'post')]);
            $known = [...$known, ...array_column(isset($schema['fields']) ? (array) $schema['fields'] : $schema, 'key')];
        }

        $unknown = array_diff(array_keys($fields), $known);

        if ($unknown !== []) {
            return $this->error('השדות האלה לא מוגדרים לפריט: '.implode(', ', $unknown).'. בדקו את השמות עם field_schema.');
        }

        // A field that holds a list (checkboxes, relationships, a gallery) can
        // be neither shown in a preview nor put back by an undo from here.
        foreach (array_keys($fields) as $key) {
            if (isset($current[$key]) && ! is_scalar($current[$key])) {
                return $this->error("השדה {$key} מכיל רשימה או ערך מורכב — אותו משנים בניהול האתר.");
            }
        }

        $fields = array_map(fn ($value): string => (string) $value, $fields);
        $lines = ['🧩 '.($post['title'] ?? "פריט #{$id}")];

        foreach ($fields as $key => $value) {
            $was = $current[$key] ?? '';
            $lines[] = "{$key}: ".(is_scalar($was) && (string) $was !== '' ? (string) $was : '—').' ← '.($value !== '' ? $value : '—');
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_FIELDS,
                'id' => $id,
                'fields' => $fields,
                // What the owner saw, so the execution can refuse when somebody
                // changed the same field in wp-admin since.
                'current' => array_map(fn ($value): string => is_scalar($value) ? (string) $value : '',
                    array_intersect_key($current, $fields) + array_fill_keys(array_keys($fields), '')),
                'summary' => 'עדכון שדות ב'.($post['title'] ?? "#{$id}"),
            ],
            'preview' => implode("\n", $lines),
        ];
    }

    // --- Menus ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeMenuItemAdd(Site $site, array $input, array $seen): array
    {
        $menu = trim((string) ($input['menu'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $pageId = (int) ($input['page_id'] ?? 0);
        $url = trim((string) ($input['url'] ?? ''));
        $parent = (int) ($input['parent_id'] ?? 0);

        if ($menu === '' || $title === '' || mb_strlen($title) > 80) {
            return $this->error('צריך menu ו-title של עד 80 תווים.');
        }

        if (($pageId > 0) === ($url !== '')) {
            return $this->error('צריך אחד בדיוק: page_id (עמוד קיים) או url.');
        }

        if ($pageId > 0 && ! $this->wasSeen($pageId, $seen)) {
            return $this->unseen('העמוד', 'find_content');
        }

        if ($url !== '' && ! preg_match('#^(https?://|/)#', $url)) {
            return $this->error('url חייב להתחיל ב-https:// או ב-/.');
        }

        $menus = (array) $this->json($site, 'wp_menu_list', []);
        $target = $this->menuNamed($menus, $menu);

        if ($target === null) {
            return $this->error("אין תפריט בשם {$menu}. התפריטים: ".implode(', ', array_column($menus, 'menu')).'.');
        }

        if ($parent > 0 && ! in_array($parent, array_column((array) ($target['items'] ?? []), 'item_id'), true)) {
            return $this->error('פריט ההורה אינו בתפריט הזה.');
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_MENU_ADD,
                'fields' => array_filter(['menu' => (string) $target['menu_id'], 'title' => $title, 'page_id' => $pageId ?: null,
                    'url' => $url ?: null, 'parent_id' => $parent ?: null]),
                'summary' => "פריט חדש בתפריט {$target['menu']}: {$title}",
            ],
            'preview' => implode("\n", array_filter([
                "🧭 תפריט \"{$target['menu']}\" — פריט חדש: \"{$title}\"",
                $pageId > 0 ? "מקשר לעמוד #{$pageId}" : "מקשר ל: {$url}",
                $parent > 0 ? "תחת פריט #{$parent}" : null,
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeMenuItemUpdate(Site $site, array $input, array $seen): array
    {
        $itemId = (int) ($input['item_id'] ?? 0);
        $fields = array_filter([
            'title' => isset($input['title']) ? trim((string) $input['title']) : null,
            'url' => isset($input['url']) ? trim((string) $input['url']) : null,
        ], fn ($value): bool => $value !== null && $value !== '');

        if (! $this->wasSeen($itemId, $seen)) {
            return $this->unseen('פריט התפריט', 'list_menus');
        }

        if ($fields === []) {
            return $this->error('צריך title ו/או url.');
        }

        if (isset($fields['url']) && ! preg_match('#^(https?://|/)#', $fields['url'])) {
            return $this->error('url חייב להתחיל ב-https:// או ב-/.');
        }

        [$menu, $item] = $this->menuItem($site, $itemId);

        if ($item === null) {
            return $this->error("פריט התפריט {$itemId} לא נמצא.");
        }

        $current = $this->menuState($item);
        $fields = array_filter($fields, fn (string $value, string $key): bool => $current[$key] !== $value, ARRAY_FILTER_USE_BOTH);

        if ($fields === []) {
            return $this->error('הפריט כבר כזה.');
        }

        $lines = ["🧭 תפריט \"{$menu}\""];

        foreach ($fields as $key => $value) {
            $lines[] = ($key === 'title' ? 'טקסט' : 'קישור').": \"{$current[$key]}\" ← \"{$value}\"";
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_MENU_UPDATE,
                'item_id' => $itemId,
                'fields' => $fields,
                'current' => array_intersect_key($current, $fields),
                'summary' => "עדכון פריט תפריט #{$itemId}",
            ],
            'preview' => implode("\n", $lines),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeMenuItemRemove(Site $site, array $input, array $seen): array
    {
        $itemId = (int) ($input['item_id'] ?? 0);

        if (! $this->wasSeen($itemId, $seen)) {
            return $this->unseen('פריט התפריט', 'list_menus');
        }

        [$menu, $item] = $this->menuItem($site, $itemId);

        if ($item === null) {
            return $this->error("פריט התפריט {$itemId} לא נמצא.");
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_MENU_REMOVE,
                'item_id' => $itemId,
                // What the owner was shown — removal has no undo, so it runs
                // only on the item exactly as they saw it.
                'current' => $this->menuState($item),
                'summary' => "הסרת \"{$item['title']}\" מהתפריט {$menu}",
            ],
            'preview' => implode("\n", [
                "🧭 להסיר מהתפריט \"{$menu}\": \"{$item['title']}\"",
                'העמוד עצמו נשאר באתר. אין ביטול אוטומטי — אם תתחרטו, אוסיף את הפריט מחדש.',
            ]),
        ];
    }

    // --- Trash, coupons, cache -----------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeTrash(Site $site, array $input, array $seen): array
    {
        $id = (int) ($input['id'] ?? 0);

        if (! $this->wasSeen($id, $seen)) {
            return $this->unseen('הפריט', 'find_content');
        }

        $post = $this->json($site, 'wp_content_get', ['id' => $id]);

        if (! isset($post['id'])) {
            return $this->error("פריט התוכן {$id} לא נמצא.");
        }

        $title = (string) ($post['title'] ?? '');
        // Only a plugin that can take it back out of the trash is promised an undo.
        $restorable = $this->toolbox->siteHas($site, 'wp_content_restore');

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_TRASH,
                'id' => $id,
                'title' => $title,
                'status' => (string) ($post['status'] ?? ''),
                'restorable' => $restorable,
                'summary' => "העברה לפח: {$title}",
            ],
            'preview' => implode("\n", [
                "🗑️ להעביר לפח: \"{$title}\" (".$this->postLabel((string) ($post['status'] ?? '')).')',
                ($post['status'] ?? '') === 'publish' ? 'הוא ייעלם מהאתר מיד.' : 'הוא אינו מוצג באתר ממילא.',
                $restorable
                    ? 'אפשר להחזיר אותו — "בטל" יוציא אותו מהפח למצב שהיה בו.'
                    : 'אפשר לשחזר אותו מהפח בניהול האתר (ביטול מכאן ידרוש עדכון של תוסף הסוכן).',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeCouponExpire(Site $site, array $input, array $seen): array
    {
        $code = Str::lower(trim((string) ($input['code'] ?? '')));

        $coupon = collect((array) $this->json($site, 'wc_coupon_list', ['limit' => 100]))
            ->first(fn ($item): bool => Str::lower((string) ($item['code'] ?? '')) === $code);

        if ($coupon === null) {
            return $this->error("לא מצאתי קופון בקוד {$code}. בדקו עם list_coupons.");
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_COUPON_EXPIRE,
                'code' => $code,
                // No undo, so it runs only against the expiry the owner saw.
                'expires' => (string) ($coupon['expires'] ?? ''),
                'summary' => "סיום הקופון {$code}",
            ],
            'preview' => implode("\n", [
                '🏷️ לסיים את הקופון '.Str::upper($code).' היום',
                'תוקף נוכחי: '.($coupon['expires'] ?? 'ללא').' · שימושים עד כה: '.($coupon['usage_count'] ?? 0),
                'הקופון לא נמחק, והזמנות שהשתמשו בו ממשיכות להציג את ההנחה. אין ביטול אוטומטי — קופון חדש אפשר ליצור בכל עת.',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeCacheFlush(Site $site, array $input, array $seen): array
    {
        return [
            'plan' => ['operation' => SiteAgentRequest::OP_CACHE_FLUSH, 'summary' => 'ניקוי מטמון'],
            'preview' => implode("\n", [
                '🧹 לנקות את המטמון של האתר',
                'לא משנה שום תוכן. האתר עשוי להיטען לאט יותר בדקה הראשונה.',
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $menus
     * @return array<string, mixed>|null
     */
    private function menuNamed(array $menus, string $menu): ?array
    {
        foreach ($menus as $candidate) {
            if ((string) ($candidate['menu_id'] ?? '') === $menu || mb_strtolower((string) ($candidate['menu'] ?? '')) === mb_strtolower($menu)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A menu item as the checks compare it: everything that, changed by somebody
     * else, makes it a different item from the one the owner was shown.
     *
     * @param  array<string, mixed>  $item
     * @return array{title: string, url: string, parent_id: string, order: string}
     */
    private function menuState(array $item): array
    {
        return [
            'title' => (string) ($item['title'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'parent_id' => (string) ($item['parent_id'] ?? '0'),
            'order' => (string) ($item['order'] ?? '0'),
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>|null} the menu's name and the item */
    private function menuItem(Site $site, int $itemId): array
    {
        foreach ((array) $this->json($site, 'wp_menu_list', []) as $menu) {
            foreach ((array) ($menu['items'] ?? []) as $item) {
                if ((int) ($item['item_id'] ?? 0) === $itemId) {
                    return [(string) ($menu['menu'] ?? ''), $item];
                }
            }
        }

        return ['', null];
    }

    // --- Plugins, themes, media ---------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposePluginUpdate(Site $site, array $input, array $seen): array
    {
        $asked = array_values(array_filter(array_map(fn ($name): string => trim((string) $name), (array) ($input['plugins'] ?? []))));
        $all = array_map('strtolower', $asked) === ['all'];

        $pending = array_values(array_filter((array) $this->json($site, 'wp_plugin_list', []),
            fn ($plugin): bool => is_array($plugin) && ($plugin['update_available'] ?? false) === true));

        if ($pending === []) {
            return $this->error('אין כרגע תוספים שממתינים לעדכון.');
        }

        $chosen = $all ? $pending : array_values(array_filter($pending, fn (array $plugin): bool => $this->named($plugin, $asked)));

        if ($chosen === []) {
            return $this->error('אף אחד מהתוספים שביקשתם אינו ממתין לעדכון. ממתינים: '.implode(', ', array_column($pending, 'name')).'.');
        }

        if (count($chosen) > self::MAX_UPDATES) {
            return $this->error('יותר מ-'.self::MAX_UPDATES.' תוספים בבת אחת — בחרו עד '.self::MAX_UPDATES.'.');
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_PLUGIN_UPDATE,
                'plugins' => array_map(fn (array $plugin): array => [
                    'file' => (string) $plugin['plugin'],
                    'name' => (string) ($plugin['name'] ?? $plugin['plugin']),
                    'version' => (string) ($plugin['version'] ?? ''),
                ], $chosen),
                'summary' => 'עדכון '.count($chosen).' תוספים',
            ],
            'preview' => implode("\n", [
                '🔌 לעדכן '.count($chosen).' תוספים:',
                ...array_map(fn (array $plugin): string => '• '.($plugin['name'] ?? $plugin['plugin']).' (עכשיו '.($plugin['version'] ?? '?').')', $chosen),
                '',
                'אחד אחרי השני, ואחרי כל אחד נבדק שהאתר עולה. אם לא — עוצרים מיד והצוות שלנו מקבל התראה.',
                'אין ביטול אוטומטי לעדכון, ולא נלקח גיבוי של האתר מכאן.',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeThemeUpdate(Site $site, array $input, array $seen): array
    {
        $stylesheet = trim((string) ($input['stylesheet'] ?? ''));

        $theme = collect((array) $this->json($site, 'wp_theme_list', []))
            ->first(fn ($item): bool => is_array($item) && ((string) ($item['stylesheet'] ?? '') === $stylesheet || mb_strtolower((string) ($item['name'] ?? '')) === mb_strtolower($stylesheet)));

        if ($theme === null) {
            return $this->error("התבנית {$stylesheet} אינה מותקנת. בדקו עם list_themes.");
        }

        if (($theme['update_available'] ?? false) !== true) {
            return $this->error('לתבנית הזו אין עדכון ממתין.');
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_THEME_UPDATE,
                'stylesheet' => (string) $theme['stylesheet'],
                'name' => (string) ($theme['name'] ?? $theme['stylesheet']),
                'summary' => 'עדכון התבנית '.($theme['name'] ?? $theme['stylesheet']),
            ],
            'preview' => implode("\n", array_filter([
                '🎨 לעדכן את התבנית '.($theme['name'] ?? $theme['stylesheet']).' (עכשיו '.($theme['version'] ?? '?').')',
                ($theme['active'] ?? false) ? 'זו התבנית הפעילה — העיצוב של כל האתר נשען עליה.' : null,
                'אחרי העדכון נבדק שהאתר עולה; אם לא — הצוות מקבל התראה מיד. אין ביטול אוטומטי, ושינויים שנעשו ישירות בקבצי התבנית יידרסו.',
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposePluginToggle(Site $site, array $input, array $seen): array
    {
        $asked = trim((string) ($input['plugin'] ?? ''));
        $active = filter_var($input['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($asked === '' || $active === null) {
            return $this->error('צריך plugin ו-active (true להפעלה, false לכיבוי).');
        }

        $plugin = collect((array) $this->json($site, 'wp_plugin_list', []))
            ->first(fn ($item): bool => is_array($item) && $this->named($item, [$asked]));

        if ($plugin === null) {
            return $this->error("התוסף {$asked} אינו מותקן. בדקו עם list_plugins.");
        }

        $file = (string) $plugin['plugin'];
        $name = (string) ($plugin['name'] ?? $file);

        if ((bool) ($plugin['active'] ?? false) === $active) {
            return $this->error("התוסף {$name} כבר ".($active ? 'פעיל' : 'כבוי').'.');
        }

        if (! $active && (preg_match(self::CRITICAL_PLUGINS, $file) === 1 || preg_match(self::SECURITY_PLUGIN_NAMES, $name.' '.$file) === 1)) {
            return $this->error("את {$name} לא מכבים מכאן — הוא מחזיק את החנות, את העמודים, את האבטחה או את החיבור לבוט. זה נעשה מול הצוות.");
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_PLUGIN_TOGGLE,
                'plugin' => $file,
                'name' => $name,
                'from' => ! $active,
                'to' => $active,
                'summary' => ($active ? 'הפעלת' : 'כיבוי').' התוסף '.$name,
            ],
            'preview' => implode("\n", [
                '🔌 '.($active ? 'להפעיל' : 'לכבות').' את התוסף '.$name,
                $active
                    ? 'תוסף שמופעל מתחיל לרוץ מיד בכל עמוד באתר.'
                    : '⚠️ כל מה באתר שתלוי בו יפסיק לעבוד — טפסים, כפתורים או עמודים שהוא מציג.',
                'אחרי השינוי נבדק שהאתר עולה; אם לא — השינוי מוחזר מיד. אפשר גם לכתוב "בטל".',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $seen
     */
    private function proposeMediaDelete(Site $site, array $input, array $seen): array
    {
        $id = (int) ($input['attachment_id'] ?? 0);

        if (! $this->wasSeen($id, $seen)) {
            return $this->unseen('הקובץ', 'find_media');
        }

        $media = (array) $this->json($site, 'wp_media_list', ['limit' => 100]);
        $item = collect((array) ($media['items'] ?? $media['media'] ?? $media))
            ->first(fn ($file): bool => is_array($file) && (int) ($file['id'] ?? 0) === $id);

        if ($item === null) {
            return $this->error('לא מצאתי את הקובץ בין 100 הקבצים האחרונים. חפשו אותו עם find_media לפי שם.');
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_MEDIA_DELETE,
                'attachment_id' => $id,
                'summary' => 'מחיקת הקובץ '.($item['title'] ?? "#{$id}"),
            ],
            'preview' => implode("\n", array_filter([
                '🗑️ למחוק לצמיתות מספריית המדיה: '.($item['title'] ?? "#{$id}"),
                isset($item['url']) ? (string) $item['url'] : null,
                '⚠️ אין ביטול ואין פח — הקובץ נמחק מהשרת. אם הוא מופיע בתוך טקסט של עמוד, שם תופיע תמונה שבורה.',
                'קובץ שמשמש כתמונה ראשית של עמוד או מוצר לא יימחק.',
            ])),
        ];
    }

    /**
     * Is this plugin the one asked for — by its file, its folder or its name?
     *
     * @param  array<string, mixed>  $plugin
     * @param  list<string>  $asked
     */
    private function named(array $plugin, array $asked): bool
    {
        $file = mb_strtolower((string) ($plugin['plugin'] ?? ''));
        $name = mb_strtolower((string) ($plugin['name'] ?? ''));

        foreach ($asked as $wanted) {
            $wanted = mb_strtolower($wanted);

            if ($wanted !== '' && ($wanted === $file || $wanted === $name || $wanted === strtok($file, '/'))) {
                return true;
            }
        }

        return false;
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * The plugin tool's answer, decoded.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function json(Site $site, string $tool, array $arguments): array
    {
        $decoded = json_decode($this->mcp->textContent($this->mcp->callTool($site, $tool, $arguments)), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The user with this id, found through the email the list is searchable by.
     *
     * @return array<string, mixed>|null
     */
    private function findUser(Site $site, int $userId, string $email): ?array
    {
        if ($email === '') {
            return null;
        }

        $users = (array) ($this->json($site, 'wp_user_list', ['search' => $email, 'limit' => 20])['users'] ?? []);

        foreach ($users as $user) {
            if ((int) ($user['id'] ?? 0) === $userId) {
                return (array) $user;
            }
        }

        return null;
    }

    /** @param list<int> $seen */
    private function wasSeen(int $id, array $seen): bool
    {
        return $id > 0 && in_array($id, $seen, true);
    }

    /** @return array{error: string} */
    private function unseen(string $what, string $tool): array
    {
        return $this->error("{$what} עם המזהה הזה לא הופיע באף קריאה בסבב הזה. חפשו אותו קודם עם {$tool}, ורק אז הציעו את השינוי.");
    }

    /** @return array{error: string} */
    private function error(string $message): array
    {
        return ['error' => $message];
    }

    /** @param array<string, mixed> $fields */
    private function productFieldProblem(array $fields): ?string
    {
        foreach (['regular_price', 'sale_price'] as $price) {
            if (isset($fields[$price]) && $fields[$price] !== '' && ! $this->isPrice((string) $fields[$price])) {
                return self::PRODUCT_FIELDS[$price].' חייב להיות מספר, עם עד שתי ספרות אחרי הנקודה.';
            }
        }

        if (array_key_exists('regular_price', $fields) && $fields['regular_price'] === '') {
            return 'מחיר רגיל אינו יכול להיות ריק.';
        }

        foreach (['sale_from', 'sale_to'] as $date) {
            if (isset($fields[$date]) && $fields[$date] !== '' && ! $this->isDate((string) $fields[$date])) {
                return self::PRODUCT_FIELDS[$date].' חייב להיות תאריך בפורמט YYYY-MM-DD.';
            }
        }

        if (isset($fields['stock_quantity']) && (filter_var($fields['stock_quantity'], FILTER_VALIDATE_INT) === false || (int) $fields['stock_quantity'] < 0)) {
            return 'כמות במלאי חייבת להיות מספר שלם, אפס או יותר.';
        }

        if (isset($fields['stock_status']) && ! array_key_exists((string) $fields['stock_status'], self::STOCK_STATUSES)) {
            return 'מצב מלאי חייב להיות instock, outofstock או onbackorder.';
        }

        if (isset($fields['status']) && ! in_array($fields['status'], ['publish', 'draft', 'private'], true)) {
            return 'סטטוס מוצר חייב להיות publish, draft או private.';
        }

        if (array_key_exists('name', $fields) && ($fields['name'] === '' || mb_strlen((string) $fields['name']) > 200)) {
            return 'שם מוצר ריק או ארוך מ-200 תווים.';
        }

        return null;
    }

    private function productLine(string $field, mixed $before, mixed $after): string
    {
        $label = self::PRODUCT_FIELDS[$field];
        $show = fn (mixed $value): string => match ($field) {
            'stock_status' => self::STOCK_STATUSES[(string) $value] ?? (string) $value,
            'status' => self::POST_STATUSES[(string) $value] ?? (string) $value,
            default => ($value === null || $value === '') ? '—' : (string) $value,
        };

        if ($field === 'sale_price' && $after === '') {
            return 'סיום המבצע (המחיר חוזר למחיר הרגיל)';
        }

        if ($field === 'short_description') {
            return "{$label} חדש: \"".$this->quote((string) $after).'"';
        }

        return "{$label}: ".$show($before).' ← '.$show($after);
    }

    /** @param array<string, mixed> $order */
    private function orderHeading(array $order): string
    {
        return '📦 הזמנה #'.($order['number'] ?? $order['id']).' — '
            .trim((string) ($order['customer'] ?? '')).', '.($order['total'] ?? '').' '.($order['currency'] ?? '');
    }

    private function orderLabel(string $status): string
    {
        return self::ORDER_STATUSES[$status] ?? $status;
    }

    private function subscriptionLabel(string $status): string
    {
        return self::SUBSCRIPTION_STATUSES[$status] ?? $status;
    }

    private function postLabel(string $status): string
    {
        return self::POST_STATUSES[$status] ?? $status;
    }

    private function bareStatus(string $status): string
    {
        $status = Str::lower(trim($status));

        return str_starts_with($status, 'wc-') ? substr($status, 3) : $status;
    }

    /** A long text quoted in full up to a point, and its remaining length named after that. */
    private function quote(string $text): string
    {
        $length = mb_strlen($text);

        return $length <= self::PREVIEW_TEXT
            ? $text
            : mb_substr($text, 0, self::PREVIEW_TEXT).' […ועוד '.($length - self::PREVIEW_TEXT).' תווים]';
    }

    private function isPrice(string $value): bool
    {
        return preg_match('/^\d{1,9}(\.\d{1,2})?$/', $value) === 1;
    }

    /**
     * A price as whole agorot, for comparing two prices without floats.
     *
     * Only ever called on a string isPrice() accepted.
     */
    private function agorot(string $price): int
    {
        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isFutureDate(string $value): bool
    {
        return $this->isDate($value) && $value >= now()->format('Y-m-d');
    }

    private function pluginAtLeast(Site $site, string $version): bool
    {
        $installed = (string) data_get($site->mcp_capabilities, 'server.version', '');

        return $installed !== '' && version_compare($installed, $version, '>=');
    }
}
