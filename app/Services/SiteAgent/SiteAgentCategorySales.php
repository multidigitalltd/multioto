<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Services\Agent\McpClient;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** One category campaign, reviewed in full and applied from the site's sealed proposal. */
class SiteAgentCategorySales
{
    public const TOOL = 'propose_category_sale';

    private const MAX_PRODUCTS = 200;

    private const PRICE_FIELDS = ['regular_price', 'sale_price', 'sale_from', 'sale_to'];

    public function __construct(private McpClient $mcp, private SiteAgentToolbox $toolbox) {}

    public static function selectors(): array
    {
        return ['category_id' => ['type' => 'integer', 'minimum' => 1],
            'include_children' => ['type' => 'boolean', 'description' => 'לכלול תתי-קטגוריות. ברירת מחדל true.']];
    }

    public static function reads(): array
    {
        return ['get_category_sale' => ['wc_category_sale_get',
            'מוצרי קטגוריית WooCommerce לצורך מבצע מתוזמן, כולל וריאציות והחרגות. category_id מתוך find_terms עם product_cat. include_children=true כולל תתי-קטגוריות. המחירים המלאים יופיעו בהצעה ובקישור לבדיקה לפני אישור.',
            self::selectors(), ['category_id']]];
    }

    public static function pluginTools(): array
    {
        return ['wc_category_sale_prepare', 'wc_category_sale_apply', 'wc_category_sale_revert'];
    }

    public static function reference(array $selector): string
    {
        return 'category-sale:'.($selector['category_id'] ?? '').':'.(($selector['include_children'] ?? true) ? '1' : '0');
    }

    public function definitions(Site $site): array
    {
        if (! $this->available($site)) {
            return [];
        }

        return [['name' => self::TOOL, 'description' => 'הצעת מבצע לכל מוצרי קטגוריה, כולל וריאציות: אחוז הנחה (percent) או סכום הנחה מהמחיר הרגיל במטבע החנות (fixed), עם מועד סיום חובה ושעת התחלה אופציונלית לפי שעון האתר. קראו get_category_sale באותו סבב. replace_existing=true רק כשבעל האתר ביקש להחליף מבצעים קיימים; ההחלפה תוצג באישור. שום מחיר אינו משתנה לפני אישור.',
            'input_schema' => ['type' => 'object', 'properties' => self::selectors() + [
                'discount_type' => ['type' => 'string', 'enum' => ['percent', 'fixed']],
                'discount_value' => ['type' => 'string', 'description' => 'מספר חיובי עם עד שתי ספרות אחרי הנקודה; percent עד 100.'],
                'starts_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:mm לפי שעון האתר. השמטה = התחלה מיידית.'],
                'ends_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:mm לפי שעון האתר; חובה.'],
                'replace_existing' => ['type' => 'boolean', 'description' => 'ברירת מחדל false; אינו עוקף את האישור להצגת מבצעים שיוחלפו.'],
            ], 'required' => ['category_id', 'discount_type', 'discount_value', 'ends_at'], 'additionalProperties' => false]]];
    }

    /** Strip execution tokens before the model sees a read or acquires a scoped reference. */
    public static function modelRead(mixed $data, array $arguments): array
    {
        try {
            $selector = self::selector($arguments);
            if (! is_array($data) || ! self::matches($data, $selector) || ! is_array($data['products'] ?? null)
                || ! array_is_list($data['products']) || count($data['products']) > self::MAX_PRODUCTS) {
                throw new InvalidArgumentException('האתר החזיר רשימת מבצע לא תקינה. קראו שוב את הקטגוריה.');
            }
            $safe = array_intersect_key($data, array_flip(['category', 'include_children', 'products', 'excluded', 'timezone', 'currency']));
            $safe['product_count'] = count($data['products']);
            $safe['excluded_count'] = count((array) ($data['excluded'] ?? []));
            $text = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (mb_strlen($text) > 24000) {
                $safe = array_intersect_key($safe, array_flip(['category', 'include_children', 'timezone', 'currency', 'product_count', 'excluded_count']));
                $safe['notice'] = 'הרשימה גדולה: מוצג סיכום בלבד. ההצעה תצרף קישור מאובטח לכל המוצרים, המחירים וההחרגות לפני אישור.';
                $text = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }

            return ['content' => $text, 'is_error' => false, 'ids' => [self::reference($selector)]];
        } catch (\Throwable $e) {
            return ['content' => 'לא ניתן לקרוא את מבצע הקטגוריה: '.Str::limit($e->getMessage(), 250), 'is_error' => true, 'ids' => []];
        }
    }

    public function propose(Site $site, array $input, array $seen): array
    {
        try {
            $this->assertAllowed($site);
            $selector = self::selector($input);
            if (! in_array(self::reference($selector), $seen, true)) {
                throw new InvalidArgumentException('קראו קודם get_category_sale עבור הקטגוריה והבחירה בתתי-קטגוריות בסבב הנוכחי.');
            }
            $terms = $this->terms($input);
            $read = $this->call($site, 'wc_category_sale_get', $selector);
            if (! self::matches($read, $selector) || ! $this->snapshot($read['snapshot'] ?? null)) {
                throw new InvalidArgumentException('האתר לא החזיר קטגוריה וצילום מצב תואמים.');
            }
            $products = $this->products($read['products'] ?? null);
            if ($products === []) {
                throw new InvalidArgumentException('בקטגוריה אין מוצרים מתאימים למבצע.');
            }
            $offer = $this->call($site, 'wc_category_sale_prepare', $selector + $terms + ['expected' => $read['snapshot']]);
            if (($offer['changed'] ?? false) !== true || ! self::matches($offer, $selector)
                || ($offer['expected'] ?? null) !== $read['snapshot'] || ! $this->snapshot($offer['prepared'] ?? null)) {
                throw new InvalidArgumentException('לא התקבלה הצעת מבצע חתומה ותואמת. לא נשמרה הצעה לאישור.');
            }
            $review = $this->review($read, $offer, $selector, $terms, $products);
            $preview = $this->preview($site, $review, true);
            if (mb_strlen($preview) > 3500) {
                $preview = $this->preview($site, $review, false);
            }
            if (mb_strlen($preview) > 3500) {
                throw new InvalidArgumentException('תיאור הקטגוריה ארוך מדי להצעת אישור. קצרו את שם הקטגוריה ונסו שוב.');
            }

            return ['plan' => ['operation' => SiteAgentRequest::OP_CATEGORY_SALE, 'arguments' => $selector,
                'expected' => $offer['expected'], 'prepared' => $offer['prepared'],
                'summary' => 'מבצע קטגוריה: '.$review['category']['name'], 'target_title' => $review['category']['name'],
                'category_sale_review' => $review], 'preview' => $preview];
        } catch (\Throwable $e) {
            return ['error' => Str::limit($e->getMessage(), 400)];
        }
    }

    public function apply(Site $site, SiteAgentRequest $request): array
    {
        try {
            $this->assertAllowed($site);
            $plan = (array) $request->plan;
            if (($plan['operation'] ?? null) !== SiteAgentRequest::OP_CATEGORY_SALE
                || ! $this->snapshot($plan['expected'] ?? null) || ! $this->snapshot($plan['prepared'] ?? null)
                || ! is_array($plan['category_sale_review'] ?? null)) {
                throw new InvalidArgumentException('הצעת מבצע הקטגוריה אינה תקינה.');
            }
            $selector = self::selector((array) ($plan['arguments'] ?? []));
            $result = $this->call($site, 'wc_category_sale_apply', $selector + ['expected' => $plan['expected'], 'prepared' => $plan['prepared']]);
            if (($result['changed'] ?? false) !== true || ! $this->snapshot($result['before'] ?? null) || ! $this->snapshot($result['after'] ?? null)) {
                throw new InvalidArgumentException('לא התקבל אישור ביצוע מלא עם מידע לשחזור. יש לקרוא את מצב המבצע מחדש.');
            }

            return ['ok' => true, 'reason' => null, 'message' => null,
                'restore' => ['kind' => 'category_sale', 'arguments' => $selector, 'before' => $result['before'], 'after' => $result['after']],
                'done' => 'מבצע הקטגוריה נשמר לפי המועדים שאושרו.'];
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    public function revert(Site $site, array $restore): array
    {
        try {
            $this->assertAllowed($site);
            if (! $this->snapshot($restore['before'] ?? null) || ! $this->snapshot($restore['after'] ?? null)) {
                throw new InvalidArgumentException('אין צילום שחזור תקין למבצע.');
            }
            $selector = self::selector((array) ($restore['arguments'] ?? []));
            $result = $this->call($site, 'wc_category_sale_revert', $selector + ['expected' => $restore['after'], 'restore' => $restore['before']]);
            if (($result['changed'] ?? false) !== true) {
                throw new InvalidArgumentException(SiteChangeApplier::STALE);
            }

            return ['ok' => true, 'reason' => null, 'message' => null, 'restore' => null];
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    private static function selector(array $input): array
    {
        if (! is_int($input['category_id'] ?? null) || $input['category_id'] < 1
            || (array_key_exists('include_children', $input) && ! is_bool($input['include_children']))) {
            throw new InvalidArgumentException('נדרשים מזהה קטגוריה חיובי ובחירה תקינה בתתי-קטגוריות.');
        }

        return ['category_id' => $input['category_id'], 'include_children' => $input['include_children'] ?? true];
    }

    private static function matches(array $data, array $selector): bool
    {
        return ($data['category']['id'] ?? null) === $selector['category_id']
            && ($data['include_children'] ?? null) === $selector['include_children'];
    }

    private function terms(array $input): array
    {
        $type = $input['discount_type'] ?? null;
        $value = $input['discount_value'] ?? null;
        if (! in_array($type, ['percent', 'fixed'], true) || ! is_string($value)
            || ! preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('יש לציין percent או fixed והנחה כמספר חיובי עם עד שתי ספרות אחרי הנקודה.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $amount = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($amount <= 0 || ($type === 'percent' && $amount > 10000)) {
            throw new InvalidArgumentException('ההנחה חייבת להיות חיובית; אחוז ההנחה אינו יכול לעלות על 100.');
        }
        if (! self::localDate($input['ends_at'] ?? null) || (isset($input['starts_at']) && ! self::localDate($input['starts_at']))) {
            throw new InvalidArgumentException('מועדי המבצע חייבים להיות בפורמט YYYY-MM-DD HH:mm לפי שעון האתר, עם מועד סיום מפורש.');
        }
        if (isset($input['starts_at']) && $input['starts_at'] >= $input['ends_at']) {
            throw new InvalidArgumentException('מועד סיום המבצע חייב להיות מאוחר ממועד ההתחלה.');
        }
        if (array_key_exists('replace_existing', $input) && ! is_bool($input['replace_existing'])) {
            throw new InvalidArgumentException('replace_existing חייב להיות true או false.');
        }

        return array_filter(['discount_type' => $type, 'discount_value' => $value,
            'starts_at' => $input['starts_at'] ?? null, 'ends_at' => $input['ends_at'],
            'replace_existing' => $input['replace_existing'] ?? false], fn ($value): bool => $value !== null);
    }

    private static function localDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d H:i') === $value;
    }

    /** Index complete product rows; never approve duplicate, omitted or arbitrary entries. */
    private function products(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MAX_PRODUCTS) {
            throw new InvalidArgumentException('רשימת המוצרים אינה תקינה או עולה על 200 פריטי מחיר. יש לבחור תת-קטגוריה.');
        }
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || $row['id'] < 1 || isset($out[$row['id']])
                || ! is_string($row['name'] ?? null) || trim($row['name']) === '') {
                throw new InvalidArgumentException('האתר החזיר מוצר לא תקין או כפול.');
            }
            foreach (self::PRICE_FIELDS as $key) {
                if (! array_key_exists($key, $row)) {
                    throw new InvalidArgumentException('לא התקבלו כל המחירים והמועדים של המוצר.');
                }
            }
            foreach (['sale_from', 'sale_to'] as $key) {
                if ($row[$key] !== null && (! is_int($row[$key]) || $row[$key] < 0)) {
                    throw new InvalidArgumentException('האתר החזיר חותמת זמן לא תקינה למבצע.');
                }
            }
            foreach (['regular_price', 'sale_price'] as $key) {
                if (($row[$key] !== '' || $key === 'regular_price')
                    && (! is_string($row[$key]) || ! preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', $row[$key]))) {
                    throw new InvalidArgumentException('האתר החזיר מחיר לא תקין.');
                }
            }
            $out[$row['id']] = $row;
        }

        return $out;
    }

    private function review(array $read, array $offer, array $selector, array $terms, array $products): array
    {
        $before = $this->products($offer['before'] ?? null);
        $after = $this->products($offer['after'] ?? null);
        $expectedIds = array_keys($products);
        sort($expectedIds);
        foreach ([$before, $after] as $rows) {
            $ids = array_keys($rows);
            sort($ids);
            if ($ids !== $expectedIds) {
                throw new InvalidArgumentException('רשימת מוצרי המבצע השתנתה בין הקריאה להצעה. קראו את הקטגוריה מחדש.');
            }
        }
        $schedule = $offer['schedule'] ?? null;
        if (! is_array($schedule) || ! self::localDate($schedule['starts_at'] ?? null) || ! self::localDate($schedule['ends_at'] ?? null)
            || ($schedule['ends_at'] ?? null) !== $terms['ends_at']
            || (isset($terms['starts_at']) && $schedule['starts_at'] !== $terms['starts_at'])
            || ! is_string($schedule['timezone'] ?? null) || $schedule['timezone'] === ''
            || ($read['timezone'] ?? null) !== $schedule['timezone'] || ($read['currency'] ?? null) !== ($offer['currency'] ?? null)) {
            throw new InvalidArgumentException('האתר לא החזיר מועדי מבצע ואזור זמן תואמים.');
        }
        $timezone = new DateTimeZone($schedule['timezone']);
        $startsAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $schedule['starts_at'], $timezone)->getTimestamp();
        $endsAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $schedule['ends_at'], $timezone)->getTimestamp();
        if ($endsAt <= $startsAt) {
            throw new InvalidArgumentException('מועד סיום המבצע אינו מאוחר מההתחלה.');
        }
        $items = [];
        foreach ($products as $id => $product) {
            $old = array_intersect_key($before[$id], array_flip(self::PRICE_FIELDS));
            $next = array_intersect_key($after[$id], array_flip(self::PRICE_FIELDS));
            if ($old !== array_intersect_key($product, array_flip(self::PRICE_FIELDS)) || $next['regular_price'] !== $old['regular_price']) {
                throw new InvalidArgumentException('המחיר הרגיל או מצב המבצע השתנו בזמן הכנת ההצעה.');
            }
            if ($old['sale_price'] !== '' && ($old['sale_to'] === null || $old['sale_to'] >= now()->timestamp) && ! $terms['replace_existing']) {
                throw new InvalidArgumentException('יש מבצע קיים. יש לבקש במפורש החלפת מבצעים קיימים ולהכין הצעה חדשה.');
            }
            $latestStart = $startsAt + (isset($terms['starts_at']) ? 0 : 59);
            if ($next['sale_from'] === null || $next['sale_from'] < $startsAt || $next['sale_from'] > $latestStart
                || $next['sale_to'] !== $endsAt - 1) {
                throw new InvalidArgumentException('מועדי אחד המוצרים אינם תואמים למבצע שהוצג.');
            }
            $items[] = ['id' => $id, 'parent_id' => (int) ($product['parent_id'] ?? 0), 'name' => $product['name'],
                'type' => (string) ($product['type'] ?? ''), 'before' => $old, 'after' => $next];
        }

        return ['category' => ['id' => $selector['category_id'], 'name' => (string) ($read['category']['name'] ?? ''), 'include_children' => $selector['include_children']],
            'schedule' => array_intersect_key($schedule, array_flip(['starts_at', 'ends_at', 'timezone'])),
            'discount_type' => $terms['discount_type'], 'discount_value' => $terms['discount_value'],
            'currency' => (string) $offer['currency'], 'replace_existing' => $terms['replace_existing'],
            'products' => $items, 'excluded' => (array) ($read['excluded'] ?? []),
            'notes' => array_values(array_filter((array) ($offer['notes'] ?? []), 'is_string'))];
    }

    private function preview(Site $site, array $review, bool $full): string
    {
        $schedule = $review['schedule'];
        $lines = ['🏷️ מבצע קטגוריה: '.$review['category']['name'], 'אתר: '.$site->domain,
            'הנחה: '.$review['discount_value'].($review['discount_type'] === 'percent' ? '%' : ' '.$review['currency']).' מהמחיר הרגיל',
            'תחילה: '.$schedule['starts_at'].' · סיום: '.$schedule['ends_at'].' ('.$schedule['timezone'].')',
            'פריטי מחיר: '.count($review['products']).' (כולל וריאציות)'.($review['category']['include_children'] ? ' · כולל תתי-קטגוריות' : ' · ללא תתי-קטגוריות')];
        if ($review['replace_existing']) {
            $lines[] = '⚠️ מבצעים קיימים יוחלפו. בסיום לא יוחזר המבצע הקודם; המוצר חוזר למחיר הרגיל.';
        }
        $lines[] = 'בסיום המבצע המחיר חוזר למחיר הרגיל; עריכה חיצונית מאוחרת לא תידרס.';
        if ($full) {
            foreach ($review['products'] as $product) {
                $old = $product['before'];
                $lines[] = $product['name'].' (#'.$product['id'].($product['type'] === 'variation' ? ', וריאציה' : '').'): רגיל '.$old['regular_price']
                    .'; מבצע קודם '.($old['sale_price'] === '' ? 'אין' : $old['sale_price']).' ← חדש '.$product['after']['sale_price'].' '.$review['currency'];
            }
            foreach ($review['excluded'] as $excluded) {
                if (is_array($excluded)) {
                    $lines[] = 'לא נכלל: '.(string) ($excluded['name'] ?? ('#'.($excluded['id'] ?? ''))).' — '.(string) ($excluded['reason'] ?? 'אינו מתאים למבצע');
                }
            }
            foreach ($review['notes'] as $note) {
                $lines[] = $note;
            }
        } else {
            $lines[] = 'החרגות: '.count($review['excluded']).'. רשימת כל המוצרים, המחירים וההחרגות תופיע בקישור המצורף. יש לבדוק את הרשימה המלאה לפני אישור.';
        }

        return implode("\n", $lines);
    }

    private function assertAllowed(Site $site): void
    {
        if (! $this->available($site)) {
            throw new InvalidArgumentException('מבצע קטגוריה דורש תוסף סוכן מעודכן עם כלי מבצעים ב-WooCommerce.');
        }
        if (! app(SiteAgentPermissions::class)->allowsOperation(SiteAgentRequest::OP_CATEGORY_SALE)) {
            throw new InvalidArgumentException(SiteAgentPermissions::refusal());
        }
    }

    private function available(Site $site): bool
    {
        foreach (['wc_category_sale_get', ...self::pluginTools()] as $tool) {
            if (! $this->toolbox->siteHas($site, $tool)) {
                return false;
            }
        }

        return true;
    }

    private function snapshot(mixed $value): bool
    {
        return is_array($value) && is_string($value['version'] ?? null) && $value['version'] !== ''
            && is_string($value['token'] ?? null) && $value['token'] !== '' && strlen($value['token']) < 1000000;
    }

    private function call(Site $site, string $tool, array $args): array
    {
        $data = json_decode($this->mcp->textContent($this->mcp->callTool($site, $tool, $args, 120)), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('האתר החזיר תשובת מבצע לא תקינה.');
        }

        return $data;
    }

    private function failure(\Throwable $error): array
    {
        $message = Str::limit($error->getMessage(), 400);

        return ['ok' => false, 'reason' => $message, 'message' => $message, 'restore' => null];
    }
}
