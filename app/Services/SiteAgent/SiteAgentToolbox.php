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
            'דוח מכירות ל-N הימים האחרונים (ברירת מחדל 30): הזמנות ששולמו, ברוטו, החזרים, נטו, ממוצע, מוצרים מובילים והשוואה לתקופה הקודמת.',
            ['days' => ['type' => 'integer']], []],
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
            'לידים מטפסי האתר (Elementor, Contact Form 7, WPForms, Gravity Forms, Fluent Forms): טופס, תאריך והשדות שמולאו. ברירת מחדל 30 ימים.',
            ['days' => ['type' => 'integer'], 'search' => ['type' => 'string'], 'limit' => ['type' => 'integer']], []],
    ];

    /**
     * Keys whose values identify something on the site. Every id a read returns
     * under one of these is remembered for the rest of the turn, and a proposal
     * may only name an id that was seen — see SiteAgentAssistant.
     */
    private const ID_KEYS = ['id', 'order_id', 'product_id', 'subscription_id', 'user_id', 'number', 'created_id', 'updated_id'];

    public function __construct(private McpClient $mcp) {}

    /**
     * The read tools this site can answer, in the model's tool format.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(Site $site): array
    {
        $tools = [];

        foreach (self::READS as $name => [$pluginTool, $description, $properties, $required]) {
            if (! $this->siteHas($site, $pluginTool)) {
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

    public function isRead(string $name): bool
    {
        return array_key_exists($name, self::READS);
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
        [$pluginTool, , $properties] = self::READS[$name];

        $arguments = array_intersect_key($input, $properties);

        try {
            $text = $this->mcp->textContent($this->mcp->callTool($site, $pluginTool, $arguments));
        } catch (\Throwable $e) {
            return ['content' => Str::limit($e->getMessage(), 400), 'is_error' => true, 'ids' => []];
        }

        $limit = max(1000, (int) config('siteagent.assistant.tool_result_chars', 6000));

        return [
            'content' => Str::limit($text, $limit, ' …[קוצר]'),
            'is_error' => false,
            'ids' => $this->idsIn(json_decode($text, true)),
        ];
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

        return $known === [] || in_array($pluginTool, $known, true);
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
