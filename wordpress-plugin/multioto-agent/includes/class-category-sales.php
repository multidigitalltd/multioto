<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Fixed-membership category promotions, with exact monetary arithmetic and guarded rollback. */
class Multioto_Agent_Category_Sales
{
    private const MAX_PRODUCTS = 200;

    private const MAX_TOKEN_BYTES = 500000;

    public static function definitions(): array
    {
        $selector = ['category_id' => ['type' => 'integer', 'minimum' => 1], 'include_children' => ['type' => 'boolean', 'default' => true]];
        $definitions = [];
        foreach (['get', 'prepare', 'apply', 'revert'] as $action) {
            $properties = $selector;
            $required = ['category_id'];
            if ($action !== 'get') {
                $properties['expected'] = ['type' => 'object'];
                $required[] = 'expected';
            }
            if ($action === 'prepare') {
                $properties += ['discount_type' => ['type' => 'string', 'enum' => ['percent', 'fixed']], 'discount_value' => ['type' => 'string'], 'starts_at' => ['type' => 'string'], 'ends_at' => ['type' => 'string'], 'replace_existing' => ['type' => 'boolean', 'default' => false]];
                $required = array_merge($required, ['discount_type', 'discount_value', 'ends_at']);
            }
            if ($action === 'apply' || $action === 'revert') {
                $key = $action === 'apply' ? 'prepared' : 'restore';
                $properties[$key] = ['type' => 'object'];
                $required[] = $key;
            }
            $definitions[] = ['name' => 'wc_category_sale_'.$action, 'description' => 'מבצע מתוזמן למוצרים הקיימים בקטגוריה; אישור לפני שינוי, מחיר רגיל נשמר ושחזור מוגן משינויים חיצוניים.', 'annotations' => ['readOnlyHint' => in_array($action, ['get', 'prepare'], true), 'destructiveHint' => false], 'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false]];
        }

        return $definitions;
    }

    public static function handles(string $name): bool
    {
        return in_array($name, ['wc_category_sale_get', 'wc_category_sale_prepare', 'wc_category_sale_apply', 'wc_category_sale_revert'], true);
    }

    public static function call(string $name, array $args): array
    {
        if (! self::handles($name) || ! function_exists('wc_get_product')) {
            self::fail('WooCommerce או כלי המבצע אינם זמינים.');
        }
        $selector = self::selector($args);
        if ($name === 'wc_category_sale_get') {
            $state = self::capture($selector);

            return self::publicState($state) + ['snapshot' => self::seal($state)];
        }
        $expected = self::open($args['expected'] ?? []);
        self::bind($expected, $selector);
        if ($name === 'wc_category_sale_prepare') {
            self::assertFresh($selector, $expected);

            return self::prepare($selector, $expected, $args);
        }
        if ($name === 'wc_category_sale_apply') {
            $proposal = self::open($args['prepared'] ?? []);
            self::bind($proposal, $selector);
            if (($proposal['purpose'] ?? '') !== 'proposal' || ($proposal['expected_version'] ?? '') !== ($args['expected']['version'] ?? null)) {
                self::fail('הצעת המבצע אינה תואמת לצילום שאושר.');
            }

            return self::withProductLocks(array_column($expected['products'], 'id'), static function () use ($selector, $expected, $proposal): array {
                return self::apply($selector, $expected, $proposal);
            });
        }
        $restore = self::open($args['restore'] ?? []);
        self::bind($restore, $selector);

        return self::withProductLocks(array_column($restore['products'], 'id'), static function () use ($selector, $expected, $restore): array {
            return self::revert($selector, $expected, $restore);
        });
    }

    private static function selector(array $args): array
    {
        if (! is_int($args['category_id'] ?? null) || $args['category_id'] < 1 || (isset($args['include_children']) && ! is_bool($args['include_children']))) {
            self::fail('נדרשים מזהה קטגוריה תקין ובחירת תתי־קטגוריות מפורשת.');
        }

        return ['category_id' => $args['category_id'], 'include_children' => $args['include_children'] ?? true];
    }

    private static function capture(array $selector): array
    {
        $category = get_term($selector['category_id'], 'product_cat');
        if (! $category || is_wp_error($category) || ($category->taxonomy ?? '') !== 'product_cat') {
            self::fail('קטגוריית מוצרים קיימת לא נמצאה.');
        }
        $ids = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => self::MAX_PRODUCTS + 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false, 'tax_query' => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => [$selector['category_id']], 'include_children' => $selector['include_children']]]]);
        if (count($ids) > self::MAX_PRODUCTS) {
            self::tooLarge();
        }
        $products = [];
        $excluded = [];
        $visited = 0;
        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);
            if (! $product || $product->get_status() !== 'publish') {
                self::fail('תכולת הקטגוריה השתנתה בזמן הקריאה.');
            }
            if ($product->get_type() === 'variable') {
                $children = $product->get_children();
                if (count($children) + $visited > self::MAX_PRODUCTS) {
                    self::tooLarge();
                }
                if ($children === []) {
                    $excluded[] = ['id' => (int) $id, 'name' => $product->get_name(), 'reason' => 'מוצר משתנה ללא וריאציות'];
                }
                foreach ($children as $childId) {
                    $child = wc_get_product((int) $childId);
                    $visited++;
                    if (! $child || $child->get_parent_id() !== (int) $id || $child->get_type() !== 'variation') {
                        self::fail('מבנה וריאציות המוצר השתנה בזמן הקריאה.');
                    }
                    self::includeProduct($child, $products, $excluded);
                }
            } else {
                $visited++;
                self::includeProduct($product, $products, $excluded);
            }
            if ($visited > self::MAX_PRODUCTS || count($products) + count($excluded) > self::MAX_PRODUCTS) {
                self::tooLarge();
            }
        }
        usort($products, static function (array $a, array $b): int {
            return $a['id'] <=> $b['id'];
        });
        usort($excluded, static function (array $a, array $b): int {
            return $a['id'] <=> $b['id'];
        });

        return ['purpose' => 'snapshot', 'scope' => self::scope(), 'selector' => $selector, 'category' => ['id' => $selector['category_id'], 'name' => (string) $category->name], 'products' => $products, 'excluded' => $excluded, 'currency' => get_woocommerce_currency(), 'decimals' => (int) wc_get_price_decimals(), 'timezone' => wp_timezone()->getName()];
    }

    private static function includeProduct($product, array &$products, array &$excluded): void
    {
        $reason = null;
        if ($product->get_status() !== 'publish') {
            $reason = 'הווריאציה אינה מפורסמת';
        } elseif (! in_array($product->get_type(), ['simple', 'external', 'variation'], true)) {
            $reason = 'סוג מוצר זה אינו נתמך במבצע קטגוריה';
        } elseif ($product->get_regular_price('edit') === '') {
            $reason = 'לא הוגדר מחיר רגיל';
        } elseif (self::money((string) $product->get_regular_price('edit')) === 0) {
            $reason = 'המחיר הרגיל כבר אפס';
        }
        if ($reason !== null) {
            $excluded[] = ['id' => $product->get_id(), 'name' => $product->get_name(), 'reason' => $reason];

            return;
        }
        $products[] = self::row($product);
    }

    private static function row($product): array
    {
        return ['id' => $product->get_id(), 'parent_id' => $product->get_parent_id(), 'name' => self::productName($product), 'type' => $product->get_type()] + self::tuple($product);
    }

    private static function productName($product): string
    {
        $name = (string) $product->get_name();
        if ($product->get_type() === 'variation' && function_exists('wc_get_formatted_variation')) {
            $attributes = wc_get_formatted_variation($product, true, true, false);
            if (is_string($attributes) && $attributes !== '') {
                $name .= ' — '.strip_tags($attributes);
            }
        }

        return $name;
    }

    private static function tuple($product): array
    {
        $from = $product->get_date_on_sale_from('edit');
        $to = $product->get_date_on_sale_to('edit');

        return ['regular_price' => (string) $product->get_regular_price('edit'), 'sale_price' => (string) $product->get_sale_price('edit'), 'sale_from' => $from ? $from->getTimestamp() : null, 'sale_to' => $to ? $to->getTimestamp() : null];
    }

    private static function prepare(array $selector, array $expected, array $args): array
    {
        if ($expected['currency'] !== 'ILS' || $expected['decimals'] !== 2) {
            self::fail('מבצע הקטגוריה זמין כרגע לחנות בשקלים עם שתי ספרות אחרי הנקודה.');
        }
        if ($expected['products'] === []) {
            self::fail('אין בקטגוריה מוצרים מפורסמים עם מחיר רגיל שניתן להוזיל.');
        }
        $type = $args['discount_type'] ?? '';
        if (! in_array($type, ['percent', 'fixed'], true) || ! is_string($args['discount_value'] ?? null)) {
            self::fail('נדרשים סוג הנחה וסכום עשרוני תקינים.');
        }
        $discount = self::money($args['discount_value']);
        if ($discount <= 0 || ($type === 'percent' && $discount > 10000)) {
            self::fail('ההנחה חייבת להיות חיובית ועד 100 אחוז.');
        }
        $starts = isset($args['starts_at']) && $args['starts_at'] !== '' ? self::localTime($args['starts_at']) : time();
        $ends = self::localTime($args['ends_at'] ?? null);
        if ($starts < time() - 60 || $ends <= $starts || $ends <= time() || $ends > time() + 366 * DAY_IN_SECONDS) {
            self::fail('יש לבחור התחלה מעכשיו וסיום מאוחר ממנה, בטווח של שנה.');
        }
        if (isset($args['replace_existing']) && ! is_bool($args['replace_existing'])) {
            self::fail('החלפת מבצע קיים דורשת בחירה מפורשת.');
        }
        self::assertNoOwnership(array_column($expected['products'], 'id'));
        $replace = $args['replace_existing'] ?? false;
        $replaced = [];
        $after = [];
        foreach ($expected['products'] as $row) {
            if ($row['sale_price'] !== '' && ($row['sale_to'] === null || $row['sale_to'] >= time())) {
                if (! $replace) {
                    self::fail('יש מוצרים עם מבצע פעיל או עתידי. יש לאשר במפורש החלפת מבצעים קיימים.');
                }
                $replaced[] = $row['id'];
            }
            $regular = self::money($row['regular_price']);
            if ($type === 'fixed' && $discount > $regular) {
                self::fail('סכום ההנחה גבוה מהמחיר הרגיל של אחד המוצרים. לא בוצע שינוי.');
            }
            $sale = $type === 'percent' ? intdiv($regular * (10000 - $discount) + 5000, 10000) : $regular - $discount;
            if ($sale >= $regular) {
                self::fail('ההנחה קטנה מדי למחיר של אחד המוצרים לאחר עיגול לאגורות.');
            }
            $row['sale_price'] = self::formatMoney($sale);
            $row['sale_from'] = $starts;
            $row['sale_to'] = $ends - 1;
            $after[] = $row;
        }
        $proposal = ['purpose' => 'proposal', 'proposal_id' => bin2hex(random_bytes(16)), 'scope' => self::scope(), 'selector' => $selector, 'expected_version' => $args['expected']['version'], 'products' => $after, 'starts_at' => $starts, 'ends_at' => $ends, 'replaced' => $replaced];
        $notes = ['המבצע חל רק על המוצרים והווריאציות ברשימה; מוצרים חדשים לא יצורפו אוטומטית.', 'בסיום יוסרו מחיר המבצע ותאריכיו והמוצרים יחזרו למחיר הרגיל.'];
        if ($replaced !== []) {
            $notes[] = 'המבצע יחליף במפורש מבצעים קיימים ב־'.count($replaced).' מוצרים; הם לא יחזרו אוטומטית בסיום. ביטול ידני יכול לשחזר אותם כל עוד לא חל שינוי נוסף.';
        }

        return self::publicState($expected) + ['before' => $expected['products'], 'after' => $after, 'expected' => $args['expected'], 'prepared' => self::seal($proposal), 'changed' => true, 'schedule' => ['starts_at' => wp_date('Y-m-d H:i', $starts, wp_timezone()), 'ends_at' => wp_date('Y-m-d H:i', $ends, wp_timezone()), 'timezone' => $expected['timezone']], 'notes' => $notes];
    }

    private static function apply(array $selector, array $expected, array $proposal): array
    {
        $id = 'category_'.$proposal['proposal_id'];
        $existing = Multioto_Agent_Sale_Schedule::state($id);
        if (is_array($existing)) {
            return self::recoverApplied($expected, $proposal, $id, $existing);
        }
        self::assertFresh($selector, $expected);
        self::assertNoOwnership(array_column($expected['products'], 'id'));
        if ($proposal['ends_at'] <= time()) {
            self::fail('מועד סיום המבצע חלף; נדרשת הצעה חדשה.');
        }
        $before = $expected;
        $before['campaign_id'] = $id;
        $touched = [];
        try {
            foreach ($proposal['products'] as $row) {
                $old = self::byId($expected['products'], $row['id']);
                self::assertProduct($old);
                $touched[] = $old;
                self::write($row, $old);
            }
            $campaignProducts = [];
            $actual = [];
            foreach ($proposal['products'] as $row) {
                $product = wc_get_product($row['id']);
                self::assertProduct($row);
                $actual[] = self::row($product);
                $campaignProducts[] = ['id' => $row['id'], 'before' => self::prices(self::byId($expected['products'], $row['id'])), 'after' => self::prices($row)];
            }
            Multioto_Agent_Sale_Schedule::register(['id' => $id, 'product_ids' => array_column($actual, 'id'), 'products' => $campaignProducts, 'starts_at' => $proposal['starts_at'], 'ends_at' => $proposal['ends_at'], 'category_id' => $selector['category_id'], 'include_children' => $selector['include_children']]);
            $after = $expected;
            $after['products'] = $actual;
            $after['campaign_id'] = $id;

            return ['changed' => true, 'campaign_id' => $id, 'before' => self::seal($before), 'after' => self::seal($after)];
        } catch (Throwable $error) {
            $cancelled = true;
            try {
                Multioto_Agent_Sale_Schedule::cancel($id, false);
            } catch (Throwable $cancelError) {
                $cancelled = false;
            }
            self::compensate($touched, $proposal['products']);
            if (! $cancelled) {
                self::fail('המחירים שוחזרו, אך ניקוי תזמון המבצע דורש בדיקה בלוח הבקרה.');
            }
            self::fail('המבצע לא הוחל במלואו; מחירי המוצרים שוחזרו ולא נשאר תזמון פעיל.');
        }
    }

    /** Recover a successful apply whose transport response was lost, without writing twice. */
    private static function recoverApplied(array $expected, array $proposal, string $id, array $state): array
    {
        if (in_array($state['status'] ?? '', ['cancelled', 'partial'], true) || count($state['products'] ?? []) !== count($proposal['products'])) {
            self::fail('הצעת המבצע כבר טופלה או הוחלפה. יש ליצור הצעה חדשה.');
        }
        foreach ($proposal['products'] as $row) {
            $owned = self::byId($state['products'], $row['id']);
            $product = wc_get_product($row['id']);
            if (($owned['phase'] ?? '') === 'superseded' || ($owned['before'] ?? null) !== self::prices(self::byId($expected['products'], $row['id'])) || ($owned['after'] ?? null) !== self::prices($row) || ! $product || self::tuple($product) !== ($owned['current_expected'] ?? null)) {
                self::fail('המבצע הוחל בעבר והמחירים השתנו לאחר מכן.');
            }
        }
        $before = $expected;
        $before['campaign_id'] = $id;
        $after = $before;
        $after['products'] = $proposal['products'];

        return ['changed' => true, 'campaign_id' => $id, 'before' => self::seal($before), 'after' => self::seal($after), 'already_applied' => true];
    }

    private static function revert(array $selector, array $expected, array $restore): array
    {
        $id = $expected['campaign_id'] ?? '';
        if ($id === '' || $id !== ($restore['campaign_id'] ?? null) || array_column($expected['products'], 'id') !== array_column($restore['products'], 'id')) {
            self::fail('צילום ביטול המבצע אינו תקין.');
        }
        $state = Multioto_Agent_Sale_Schedule::state($id);
        if (is_array($state) && ($state['status'] ?? '') === 'cancelled') {
            foreach ($restore['products'] as $row) {
                $product = wc_get_product($row['id']);
                if (! $product || self::tuple($product) !== self::prices($row)) {
                    self::fail('המבצע בוטל בעבר והמחירים השתנו לאחר מכן.');
                }
            }

            return ['changed' => true, 'campaign_id' => $id, 'already_reverted' => true];
        }
        if (! is_array($state) || ($state['status'] ?? '') === 'partial') {
            self::fail('המבצע אינו בבעלות הסוכן או השתנה מאז שאושר.');
        }
        $current = [];
        foreach ($expected['products'] as $row) {
            $owned = self::byId((array) ($state['products'] ?? []), $row['id']);
            if (($owned['phase'] ?? '') === 'superseded' || ! isset($owned['current_expected'])) {
                self::fail('אחד המוצרים השתנה או הועבר למבצע אחר. לא בוצע ביטול חלקי.');
            }
            $product = wc_get_product($row['id']);
            if (! $product || self::tuple($product) !== $owned['current_expected']) {
                self::fail('מחירי אחד המוצרים השתנו לאחר המבצע. לא בוצע ביטול חלקי.');
            }
            $current[] = self::row($product);
        }
        $touched = [];
        try {
            foreach ($restore['products'] as $row) {
                self::assertProduct(self::byId($current, $row['id']));
                $touched[] = self::byId($current, $row['id']);
                self::write($row, self::byId($current, $row['id']));
            }
            Multioto_Agent_Sale_Schedule::cancel($id, false);

            return ['changed' => true, 'campaign_id' => $id];
        } catch (Throwable $error) {
            self::compensate($touched, $restore['products']);
            self::fail('ביטול המבצע נכשל; מצב המבצע הקודם שוחזר.');
        }
    }

    private static function write(array $row, array $expected): void
    {
        $product = wc_get_product($row['id']);
        if (! $product || self::tuple($product) !== self::prices($expected) || (string) $product->get_regular_price('edit') !== $row['regular_price']) {
            self::fail('מחיר המוצר או תאריכי המבצע השתנו לפני השמירה.');
        }
        $product->set_sale_price($row['sale_price']);
        $product->set_date_on_sale_from($row['sale_from']);
        $product->set_date_on_sale_to($row['sale_to']);
        $now = time();
        $active = $row['sale_price'] !== '' && self::money($row['sale_price']) < self::money($row['regular_price']) && ($row['sale_from'] === null || $row['sale_from'] <= $now) && ($row['sale_to'] === null || $row['sale_to'] >= $now);
        $product->set_price($active ? $row['sale_price'] : $row['regular_price']);
        if (! $product->save()) {
            self::fail('שמירת מחיר מוצר נכשלה.');
        }
        wc_delete_product_transients($row['id']);
        if ($row['parent_id'] > 0) {
            WC_Product_Variable::sync($row['parent_id']);
            wc_delete_product_transients($row['parent_id']);
        }
        $saved = wc_get_product($row['id']);
        if (! $saved || self::tuple($saved) !== self::prices($row)) {
            self::fail('מחירי המוצר לא נשמרו במלואם.');
        }
    }

    private static function compensate(array $rows, array $attempted): void
    {
        $failed = false;
        foreach (array_reverse($rows) as $row) {
            try {
                $product = wc_get_product($row['id']);
                if (! $product || (self::tuple($product) !== self::prices($row) && self::tuple($product) !== self::prices(self::byId($attempted, $row['id'])))) {
                    self::fail('מחיר השתנה מחוץ למבצע; השינוי החיצוני לא יידרס.');
                }
                self::write($row, self::row($product));
            } catch (Throwable $error) {
                $failed = true;
            }
        }
        if ($failed) {
            self::fail('פעולת המבצע נכשלה והשחזור האוטומטי לא הושלם. יש לבדוק את מחירי המוצרים בלוח הבקרה.');
        }
    }

    private static function assertFresh(array $selector, array $expected): void
    {
        if (($expected['purpose'] ?? '') !== 'snapshot' || self::json(self::capture($selector)) !== self::json($expected)) {
            self::fail('תכולת הקטגוריה, המחירים או הגדרות החנות השתנו. יש להפיק הצעה חדשה.');
        }
    }

    private static function assertProduct(array $row): void
    {
        $product = wc_get_product($row['id']);
        if (! $product || self::row($product) !== $row) {
            self::fail('פרטי אחד המוצרים השתנו מאז האישור.');
        }
    }

    private static function assertNoOwnership(array $ids): void
    {
        if (! class_exists('Multioto_Agent_Sale_Schedule') || Multioto_Agent_Sale_Schedule::conflicts($ids) !== []) {
            self::fail('חלק מהמוצרים כבר משויכים למבצע מתוזמן של הסוכן. יש לסיים או לבטל אותו תחילה.');
        }
    }

    private static function prices(array $row): array
    {
        return array_intersect_key($row, array_flip(['regular_price', 'sale_price', 'sale_from', 'sale_to']));
    }

    private static function byId(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }
        self::fail('חסר מוצר בצילום המבצע.');
    }

    private static function publicState(array $state): array
    {
        return ['category' => $state['category'], 'include_children' => $state['selector']['include_children'], 'products' => $state['products'], 'excluded' => $state['excluded'], 'currency' => $state['currency'], 'timezone' => $state['timezone']];
    }

    private static function money(string $value): int
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $match)) {
            self::fail('מחיר או הנחה חייבים להיות מספר חיובי עם עד שתי ספרות עשרוניות.');
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    private static function formatMoney(int $value): string
    {
        return intdiv($value, 100).'.'.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function localTime($value): int
    {
        if (! is_string($value)) {
            self::fail('נדרש מועד סיום לפי שעון האתר בפורמט YYYY-MM-DD HH:mm.');
        }
        if (class_exists('Multioto_Agent_Sale_Schedule') && is_callable(['Multioto_Agent_Sale_Schedule', 'parse'])) {
            $timestamp = Multioto_Agent_Sale_Schedule::parse($value);
            if (! is_int($timestamp)) {
                self::fail('נדרש מועד מבצע מפורש.');
            }

            return $timestamp;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, wp_timezone());
        if (! $date || $date->format('Y-m-d H:i') !== $value) {
            self::fail('מועד המבצע אינו תקין לפי אזור הזמן של האתר.');
        }

        return $date->getTimestamp();
    }

    /** Locks prevent two category campaigns with intersecting products from interleaving writes. */
    public static function withProductLocks(array $ids, callable $callback)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $owner = bin2hex(random_bytes(16));
        $locks = [];
        try {
            foreach ($ids as $id) {
                $key = '_multioto_sale_write_'.$id;
                $value = ['owner' => $owner, 'expires' => time() + 900];
                $old = get_option($key, null);
                if (is_array($old) && (int) ($old['expires'] ?? 0) < time()) {
                    self::deleteOwnedOption($key, $old);
                }
                if (! add_option($key, $value, '', false)) {
                    self::fail('פעולת מחיר אחרת מתבצעת כעת באחד המוצרים. יש לנסות שוב מאוחר יותר.');
                }
                $locks[$key] = $value;
            }

            return $callback();
        } finally {
            foreach ($locks as $key => $value) {
                self::deleteOwnedOption($key, $value);
            }
        }
    }

    private static function deleteOwnedOption(string $key, array $value): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => $key, 'option_value' => maybe_serialize($value)]);
        wp_cache_delete($key, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    private static function scope(): string
    {
        return (function_exists('home_url') ? home_url('/') : 'site').':'.(function_exists('get_current_blog_id') ? get_current_blog_id() : 1);
    }

    private static function bind(array $state, array $selector): void
    {
        if (($state['scope'] ?? '') !== self::scope() || ($state['selector'] ?? null) !== $selector) {
            self::fail('צילום המבצע אינו תואם לאתר ולקטגוריה שנבחרו.');
        }
    }

    private static function seal(array $value): array
    {
        if (! function_exists('openssl_encrypt') || ! function_exists('wp_salt')) {
            self::fail('אין הצפנה זמינה לצילום המבצע.');
        }
        $plain = self::json($value);
        if (strlen($plain) > self::MAX_TOKEN_BYTES) {
            self::tooLarge();
        }
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag, 'multioto-category-sale-v1');
        if ($encrypted === false) {
            self::fail('הצפנת המבצע נכשלה.');
        }

        return ['version' => hash_hmac('sha256', $plain, wp_salt('auth')), 'token' => base64_encode($iv.$tag.$encrypted)];
    }

    private static function open($snapshot): array
    {
        if (! is_array($snapshot) || ! is_string($snapshot['token'] ?? null) || ! is_string($snapshot['version'] ?? null) || strlen($snapshot['token']) > self::MAX_TOKEN_BYTES * 2 || ! function_exists('openssl_decrypt') || ! function_exists('wp_salt')) {
            self::fail('צילום המבצע אינו תקין.');
        }
        $bytes = base64_decode($snapshot['token'], true);
        if ($bytes === false || strlen($bytes) < 29) {
            self::fail('צילום המבצע אינו תקין.');
        }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'multioto-category-sale-v1');
        if ($plain === false || ! hash_equals(hash_hmac('sha256', $plain, wp_salt('auth')), $snapshot['version'])) {
            self::fail('אימות צילום המבצע נכשל.');
        }
        $value = json_decode($plain, true);
        if (! is_array($value)) {
            self::fail('תוכן צילום המבצע אינו תקין.');
        }

        return $value;
    }

    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            self::fail('לא ניתן לייצג את נתוני המבצע.');
        }

        return $json;
    }

    private static function tooLarge(): void
    {
        self::fail('הקטגוריה מכילה יותר מ־200 מוצרים או וריאציות לבדיקה. יש לבחור קטגוריה מצומצמת יותר; לא בוצע שינוי חלקי.');
    }

    private static function fail(string $message): void
    {
        throw new Multioto_Agent_Rpc_Error(-32602, $message);
    }
}
