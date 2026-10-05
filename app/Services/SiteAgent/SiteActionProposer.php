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
    ];

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
        $tools = [
            ['propose_product_update', 'wc_product_update',
                'הצעה לעדכן מוצר קיים: שם, תיאור קצר, מחיר רגיל, מחיר מבצע (ריק = סיום המבצע), תאריכי מבצע (YYYY-MM-DD), כמות במלאי, מצב מלאי (instock/outofstock/onbackorder), סטטוס (publish/draft/private). רק השדות שמשתנים.',
                ['product_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'short_description' => ['type' => 'string'],
                    'regular_price' => ['type' => 'string'], 'sale_price' => ['type' => 'string'], 'sale_from' => ['type' => 'string'],
                    'sale_to' => ['type' => 'string'], 'stock_quantity' => ['type' => 'integer'], 'stock_status' => ['type' => 'string'],
                    'status' => ['type' => 'string']], ['product_id']],
            ['propose_product_create', 'wc_product_create',
                'הצעה ליצור מוצר חדש. נוצר תמיד כטיוטה; כדי לפרסם — propose_product_update עם status=publish אחרי שנוצר.',
                ['name' => ['type' => 'string'], 'regular_price' => ['type' => 'string'], 'short_description' => ['type' => 'string'],
                    'description' => ['type' => 'string'], 'sku' => ['type' => 'string']], ['name']],
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
        ];

        $out = [];

        foreach ($tools as [$name, $pluginTool, $description, $properties, $required]) {
            if ($this->toolbox->siteHas($site, $pluginTool)) {
                $out[] = [
                    'name' => $name,
                    'description' => $description,
                    'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => $required],
                ];
            }
        }

        return $out;
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

        if (isset($fields['regular_price']) && ! $this->isPrice($fields['regular_price'])) {
            return $this->error('מחיר חייב להיות מספר, עם עד שתי ספרות אחרי הנקודה.');
        }

        return [
            'plan' => [
                'operation' => SiteAgentRequest::OP_PRODUCT_CREATE,
                'fields' => $fields,
                'summary' => "יצירת המוצר {$name}",
            ],
            'preview' => implode("\n", array_filter([
                "🛒 מוצר חדש: {$name}",
                isset($fields['regular_price']) ? "מחיר: {$fields['regular_price']} ₪" : null,
                isset($fields['sku']) ? "מק\"ט: {$fields['sku']}" : null,
                isset($fields['short_description']) ? 'תיאור קצר: "'.$this->quote($fields['short_description']).'"' : null,
                isset($fields['description']) ? 'תיאור: "'.$this->quote($fields['description']).'"' : null,
                '',
                'המוצר ייווצר כטיוטה, ולא יוצג באתר עד שתבקשו לפרסם אותו.',
            ], fn (?string $line): bool => $line !== null)),
        ];
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
