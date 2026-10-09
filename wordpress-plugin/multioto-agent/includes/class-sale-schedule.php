<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Exact WooCommerce sale boundaries, with per-product ownership and read-time fallback. */
class Multioto_Agent_Sale_Schedule
{
    private const HOOK = 'multioto_agent_sale_boundary';
    private const GROUP = 'multioto-agent-sales';
    private const OWNER = '_multioto_sale_owner';
    private const PARENTS = '_multioto_sale_campaigns';
    private static $running = false;
    private static $locks = [];

    public static function boot(): void
    {
        add_action(self::HOOK, [self::class, 'run'], 10, 2);
        foreach (['woocommerce_product_get_price', 'woocommerce_product_variation_get_price', 'woocommerce_variation_prices_price'] as $hook) {
            add_filter($hook, [self::class, 'price'], 100, 2);
        }
        foreach (['woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_sale_price'] as $hook) {
            add_filter($hook, [self::class, 'salePrice'], 100, 2);
        }
        add_filter('woocommerce_variation_prices_sale_price', [self::class, 'variationSalePrice'], 100, 2);
        add_filter('woocommerce_product_is_on_sale', [self::class, 'onSale'], 100, 2);
        add_filter('woocommerce_get_variation_prices_hash', [self::class, 'variationHash'], 100, 2);
    }

    /** Bare local dates retain the previous midnight behavior; explicit times are exact. */
    public static function parse($value, bool $end = false): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            self::fail('תאריך המבצע חייב להיות מחרוזת.');
        }
        $value = trim($value);
        $zone = self::timezone();
        $formats = ['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:iP', 'Y-m-d\TH:i:sP', 'Y-m-d H:iP', 'Y-m-d H:i:sP'];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            if (! $date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format($format) !== $value) {
                continue;
            }
            if (strpos($format, 'P') === false && self::ambiguous($date, $zone)) {
                self::fail('השעה מופיעה פעמיים במעבר שעון. יש לציין היסט UTC מפורש, למשל +02:00.');
            }
            return $date->getTimestamp();
        }
        self::fail('תאריך או שעה אינם תקינים באזור הזמן של האתר. נדרש YYYY-MM-DD HH:mm או תאריך עם היסט UTC.');
    }

    private static function ambiguous(DateTimeImmutable $date, DateTimeZone $zone): bool
    {
        $timestamp = $date->getTimestamp();
        $wall = $date->format('Y-m-d H:i:s');
        $offsets = [];
        foreach ((array) $zone->getTransitions($timestamp - 172800, $timestamp + 172800) as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }
        $matches = 0;
        $utcWall = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wall, new DateTimeZone('UTC'))->getTimestamp();
        foreach (array_keys($offsets) as $offset) {
            $candidate = (new DateTimeImmutable('@'.($utcWall - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i:s') === $wall && ++$matches > 1) {
                return true;
            }
        }
        return false;
    }

    public static function timezone(): DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(function_exists('wc_timezone_string') ? wc_timezone_string() : 'UTC');
    }

    /** ISO offset preserves even the duplicated local hour in undo snapshots. */
    public static function format(?int $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }
        $date = (new DateTimeImmutable('@'.$timestamp))->setTimezone(self::timezone());
        return $date->format('H:i:s') === '00:00:00' && ! self::ambiguous($date, self::timezone()) ? $date->format('Y-m-d') : $date->format('Y-m-d\TH:i:sP');
    }

    /** Campaign products are a list of {id,before,after}; all tuples use absolute timestamps. */
    public static function register(array $campaign): string
    {
        $id = self::id($campaign['id'] ?? null);
        $start = $campaign['starts_at'] ?? null;
        $end = $campaign['ends_at'] ?? null;
        $products = $campaign['products'] ?? null;
        if (! is_int($start) || ! is_int($end) || $end <= $start || $end <= time() || ! is_array($products) || ! $products || count($products) > 200) {
            self::fail('חלון המבצע או רשימת המוצרים אינם תקינים.');
        }
        if (get_option(self::key($id), false) !== false) {
            self::fail('מזהה מבצע זה כבר נרשם.');
        }
        $rows = [];
        $locks = [];
        try {
            foreach ($products as $row) {
                $productId = $row['id'] ?? null;
                if (! is_int($productId) || $productId < 1 || isset($rows[$productId])) {
                    self::fail('רשימת מזהי המוצרים אינה תקינה.');
                }
                if (! self::lock($productId)) {
                    self::fail('מוצר ברשימה נמצא כרגע בעדכון. יש לנסות שוב.');
                }
                $locks[] = $productId;
                $before = self::tuple($row['before'] ?? []);
                $after = self::tuple($row['after'] ?? []);
                if (($after['sale_from'] !== $start && ! ($after['sale_from'] === null && $start <= time())) || (! in_array($after['sale_to'], [$end, $end - 1], true) && ! ($end === PHP_INT_MAX && $after['sale_to'] === null)) || $after['sale_price'] === '' || $after['regular_price'] === '' || ! self::lessThan($after['sale_price'], $after['regular_price'])) {
                    self::fail('מחירי המבצע אינם תואמים לתזמון.');
                }
                if (self::conflicts([$productId])) {
                    self::fail('למוצר כבר יש מבצע מנוהל פעיל או מתוזמן.');
                }
                $product = wc_get_product($productId);
                if (! $product || ! self::matches(self::productTuple($product), $after)) {
                    self::fail('המוצר השתנה לפני רישום התזמון.');
                }
                $rows[$productId] = ['id' => $productId, 'before' => $before, 'after' => $after, 'phase' => 'pending', 'current_expected' => $after];
            }
            $state = ['id' => $id, 'starts_at' => $start, 'ends_at' => $end, 'products' => array_values($rows), 'status' => 'scheduled', 'created_at' => time()];
            if (! add_option(self::key($id), $state, '', false)) {
                self::fail('לא ניתן לשמור את תזמון המבצע.');
            }
            foreach ($rows as $productId => $row) {
                update_post_meta($productId, self::OWNER, $id);
                if (get_post_meta($productId, self::OWNER, true) !== $id) {
                    self::fail('לא ניתן לשמור בעלות על תזמון המוצר.');
                }
                self::parentIndex($productId, $id, true);
            }
            self::schedule(max(time() + 1, $start), $id, 'start');
            if ($end !== PHP_INT_MAX) {
                self::schedule($end, $id, 'end');
            }
        } catch (Throwable $error) {
            self::cancel($id, false);
            throw $error;
        } finally {
            foreach ($locks as $productId) {
                self::unlock($productId);
            }
        }
        return $id;
    }

    public static function state(string $id, bool $reconcile = true): ?array
    {
        $state = get_option(self::key(self::id($id)), null);
        if (! is_array($state)) {
            return null;
        }
        foreach ($state['products'] as &$row) {
            if (get_post_meta($row['id'], self::OWNER, true) !== $id) {
                $row['phase'] = 'superseded';
                continue;
            }
            if ($reconcile && $state['status'] !== 'cancelled') {
                $product = wc_get_product($row['id']);
                $current = $product ? self::productTuple($product) : [];
                $cleared = $row['after'];
                $cleared['sale_price'] = '';
                $cleared['sale_from'] = null;
                $cleared['sale_to'] = null;
                if ($product && self::ownedMatches($current, $row['after'], $state)) {
                    $row['current_expected'] = $current;
                    $row['phase'] = time() >= $state['starts_at'] ? 'active' : 'pending';
                } elseif ($product && time() >= $state['ends_at'] && self::matches($current, $cleared)) {
                    $row['current_expected'] = $current;
                    $row['phase'] = 'ended';
                } else {
                    $row['phase'] = 'superseded';
                }
            }
        }
        unset($row);
        if ($state['status'] !== 'cancelled') {
            $state['status'] = self::status($state);
        }
        return $state;
    }

    public static function conflicts(array $productIds): array
    {
        $conflicts = [];
        foreach ($productIds as $productId) {
            $owner = get_post_meta((int) $productId, self::OWNER, true);
            if (! is_string($owner) || $owner === '') {
                continue;
            }
            $state = self::state($owner, false);
            if (! $state || in_array($state['status'], ['cancelled', 'ended'], true)) {
                continue;
            }
            foreach ($state['products'] as $row) {
                if ($row['id'] === (int) $productId && in_array($row['phase'], ['pending', 'active'], true) && $state['ends_at'] > time()) {
                    $conflicts[] = (int) $productId;
                }
            }
        }
        return $conflicts;
    }

    /** Scheduling cancellation is separate from the provider's approved price restoration. */
    public static function cancel(string $id, bool $restore = false): void
    {
        self::id($id);
        if ($restore) {
            self::fail('שחזור המחירים דורש צילום מאושר של המבצע.');
        }
        if (function_exists('as_unschedule_all_actions')) {
            foreach (['start', 'end'] as $phase) {
                as_unschedule_all_actions(self::HOOK, [$id, $phase], self::GROUP);
            }
        }
        if (function_exists('wp_clear_scheduled_hook')) {
            foreach (['start', 'end'] as $phase) {
                wp_clear_scheduled_hook(self::HOOK, [$id, $phase]);
            }
        }
        $state = get_option(self::key($id), null);
        if (! is_array($state)) {
            return;
        }
        foreach ($state['products'] as $row) {
            if (get_post_meta($row['id'], self::OWNER, true) === $id) {
                delete_post_meta($row['id'], self::OWNER, $id);
                self::parentIndex($row['id'], $id, false);
                self::invalidate($row['id']);
            }
        }
        $state['status'] = 'cancelled';
        update_option(self::key($id), $state, false);
    }

    /** An explicit product edit supersedes only this product, never the rest of its category. */
    public static function detach(int $productId): ?array
    {
        $id = get_post_meta($productId, self::OWNER, true);
        if (! is_string($id) || $id === '') {
            return null;
        }
        $state = get_option(self::key($id), null);
        $prior = null;
        if (is_array($state)) {
            foreach ($state['products'] as &$row) {
                if ($row['id'] === $productId) {
                    $prior = $row;
                    $row['phase'] = 'superseded';
                }
            }
            unset($row);
            $state['status'] = 'partial';
            update_option(self::key($id), $state, false);
        }
        delete_post_meta($productId, self::OWNER, $id);
        self::invalidate($productId);
        return $prior ? ['id' => $id, 'row' => $prior] : null;
    }

    public static function restoreDetached(int $productId, ?array $prior): void
    {
        if (! $prior || get_post_meta($productId, self::OWNER, true)) {
            return;
        }
        $state = get_option(self::key($prior['id']), null);
        if (! is_array($state) || $state['status'] === 'cancelled') {
            return;
        }
        foreach ($state['products'] as &$row) {
            if ($row['id'] === $productId) {
                $row = $prior['row'];
            }
        }
        unset($row);
        $state['status'] = self::status($state);
        update_option(self::key($prior['id']), $state, false);
        update_post_meta($productId, self::OWNER, $prior['id']);
        self::parentIndex($productId, $prior['id'], true);
    }

    public static function run(string $id, string $phase): void
    {
        if (self::$running || ! in_array($phase, ['start', 'end'], true)) {
            return;
        }
        $state = self::state($id, false);
        if (! $state || $state['status'] === 'cancelled' || time() < $state['starts_at']) {
            return;
        }
        self::$running = true;
        $retry = false;
        try {
            foreach ($state['products'] as &$row) {
                if (in_array($row['phase'], ['superseded', 'ended'], true)) {
                    continue;
                }
                try {
                    self::withProductLocks([$row['id']], static function () use (&$row, $state, $id): void {
                        self::transition($row, $state, $id);
                    });
                } catch (Throwable $error) {
                    $retry = true;
                }
            }
            unset($row);
            $phases = array_column($state['products'], 'phase');
            $state['status'] = count(array_filter($phases, static function ($item) { return $item === 'ended'; })) === count($phases) ? 'ended' : (in_array('superseded', $phases, true) ? 'partial' : 'active');
            update_option(self::key($id), $state, false);
            if (! $retry && time() >= $state['ends_at']) {
                foreach ($state['products'] as $row) {
                    self::parentIndex($row['id'], $id, false);
                }
            }
            if ($retry) {
                self::schedule(time() + 60, $id, $phase);
            }
        } finally {
            self::$running = false;
        }
    }

    private static function status(array $state): string
    {
        $phases = array_column($state['products'], 'phase');
        if (count(array_filter($phases, static function ($phase) { return $phase === 'ended'; })) === count($phases)) {
            return 'ended';
        }
        return in_array('superseded', $phases, true) ? 'partial' : (in_array('active', $phases, true) ? 'active' : 'scheduled');
    }

    private static function transition(array &$row, array $state, string $id): void
    {
        $product = wc_get_product($row['id']);
        $expected = $row['after'];
        $current = $product ? self::productTuple($product) : [];
        $ended = time() >= $state['ends_at'];
        $cleared = $expected;
        $cleared['regular_price'] = $current['regular_price'] ?? $expected['regular_price'];
        $cleared['sale_price'] = '';
        $cleared['sale_from'] = null;
        $cleared['sale_to'] = null;
        if (! $product || get_post_meta($row['id'], self::OWNER, true) !== $id || (! self::saleOwnedMatches($current, $expected, $state) && ! ($ended && self::matches($current, $cleared)))) {
            $row['phase'] = 'superseded';
            return;
        }
        if ($ended) {
            $product->set_sale_price('');
            $product->set_date_on_sale_from(null);
            $product->set_date_on_sale_to(null);
            $product->set_price($product->get_regular_price('edit'));
        } else {
            $product->set_price(self::lessThan($product->get_sale_price('edit'), $product->get_regular_price('edit')) ? $product->get_sale_price('edit') : $product->get_regular_price('edit'));
        }
        if (! $product->save()) {
            self::fail('שמירת גבול המבצע נכשלה.');
        }
        $saved = wc_get_product($row['id']);
        $wanted = $ended ? $cleared : $current;
        $wantedPrice = $ended || ! self::lessThan($current['sale_price'], $current['regular_price']) ? $current['regular_price'] : $current['sale_price'];
        if (! $saved || ! self::matches(self::productTuple($saved), $wanted) || self::decimal((string) $saved->get_price('edit')) !== self::decimal($wantedPrice)) {
            self::fail('המוצר לא שמר את מחיר המבצע הצפוי.');
        }
        $row['phase'] = $ended ? 'ended' : 'active';
        $row['current_expected'] = $ended ? $cleared : $current;
        self::invalidate($row['id']);
    }

    public static function withProductLocks(array $ids, callable $callback)
    {
        if (class_exists('Multioto_Agent_Category_Sales') && is_callable(['Multioto_Agent_Category_Sales', 'withProductLocks'])) {
            return Multioto_Agent_Category_Sales::withProductLocks($ids, $callback);
        }
        $locked = [];
        try {
            foreach ($ids as $id) {
                if (! self::lock((int) $id)) {
                    self::fail('המוצר נמצא בעדכון אחר.');
                }
                $locked[] = (int) $id;
            }
            return $callback();
        } finally {
            foreach ($locked as $id) {
                self::unlock($id);
            }
        }
    }

    /** No global sale query: only an owned product's small campaign record is loaded. */
    private static function owned($product): ?array
    {
        if (self::$running || ! is_object($product) || ! is_callable([$product, 'get_id'])) {
            return null;
        }
        $owner = get_post_meta($product->get_id(), self::OWNER, true);
        if (! is_string($owner) || $owner === '') {
            return null;
        }
        $state = get_option(self::key($owner), null);
        if (! is_array($state) || $state['status'] === 'cancelled') {
            return null;
        }
        foreach ($state['products'] as $row) {
            if ($row['id'] !== $product->get_id() || $row['phase'] === 'superseded') {
                continue;
            }
            if (self::saleOwnedMatches(self::productTuple($product), $row['after'], $state)) {
                return ['active' => time() >= $state['starts_at'] && time() < $state['ends_at'] && self::lessThan((string) $product->get_sale_price('edit'), (string) $product->get_regular_price('edit')), 'row' => $row];
            }
        }
        return null;
    }

    public static function price($price, $product)
    {
        $owned = self::owned($product);
        if (! $owned || (is_callable([$product, 'get_changes']) && array_key_exists('price', $product->get_changes()))) {
            return $price;
        }
        return $owned['active'] ? $product->get_sale_price('edit') : $product->get_regular_price('edit');
    }

    public static function salePrice($price, $product)
    {
        $owned = self::owned($product);
        return $owned && ! $owned['active'] ? '' : $price;
    }

    public static function variationSalePrice($price, $product)
    {
        $owned = self::owned($product);
        return $owned && ! $owned['active'] ? $product->get_regular_price('edit') : $price;
    }

    public static function onSale($onSale, $product): bool
    {
        $owned = self::owned($product);
        return $owned ? $owned['active'] : (bool) $onSale;
    }

    public static function variationHash(array $hash, $product): array
    {
        $ids = get_post_meta($product->get_id(), self::PARENTS, true);
        foreach (is_array($ids) ? $ids : [] as $id) {
            $state = get_option(self::key($id), null);
            if (is_array($state) && $state['status'] !== 'cancelled') {
                $hash['multioto_'.$id] = time() < $state['starts_at'] ? 'pending' : (time() < $state['ends_at'] ? 'active' : 'ended');
            }
        }
        return $hash;
    }

    public static function productTuple($product): array
    {
        $from = $product->get_date_on_sale_from('edit');
        $to = $product->get_date_on_sale_to('edit');
        return ['regular_price' => (string) $product->get_regular_price('edit'), 'sale_price' => (string) $product->get_sale_price('edit'), 'sale_from' => $from ? $from->getTimestamp() : null, 'sale_to' => $to ? $to->getTimestamp() : null];
    }

    /** A regular-price edit does not extend a sale whose own price/dates are still owned. */
    private static function saleOwnedMatches(array $current, array $expected, array $state): bool
    {
        if (! array_key_exists('regular_price', $current)) {
            return false;
        }
        $expected['regular_price'] = $current['regular_price'];
        return self::ownedMatches($current, $expected, $state);
    }

    private static function ownedMatches(array $current, array $expected, array $state): bool
    {
        if (self::matches($current, $expected)) {
            return true;
        }
        // Woo's own scheduled-sales job may clear a start date at activation.
        if (time() >= $state['starts_at'] && $current['sale_from'] === null) {
            $expected['sale_from'] = null;
            return self::matches($current, $expected);
        }
        return false;
    }

    private static function matches(array $current, array $expected): bool
    {
        foreach (['regular_price', 'sale_price', 'sale_from', 'sale_to'] as $key) {
            if (! array_key_exists($key, $current) || ! array_key_exists($key, $expected)) {
                return false;
            }
            $a = $current[$key];
            $b = $expected[$key];
            if (strpos($key, 'price') !== false) {
                $a = self::decimal((string) $a);
                $b = self::decimal((string) $b);
            }
            if ($a !== $b) {
                return false;
            }
        }
        return true;
    }

    private static function lessThan(string $left, string $right): bool
    {
        $a = explode('.', $left, 2);
        $b = explode('.', $right, 2);
        $a[0] = ltrim($a[0], '0') ?: '0';
        $b[0] = ltrim($b[0], '0') ?: '0';
        if (strlen($a[0]) !== strlen($b[0])) {
            return strlen($a[0]) < strlen($b[0]);
        }
        if ($a[0] !== $b[0]) {
            return strcmp($a[0], $b[0]) < 0;
        }
        $places = max(strlen($a[1] ?? ''), strlen($b[1] ?? ''));
        return strcmp(str_pad($a[1] ?? '', $places, '0'), str_pad($b[1] ?? '', $places, '0')) < 0;
    }

    private static function decimal(string $value): string
    {
        return strpos($value, '.') === false ? $value : rtrim(rtrim($value, '0'), '.');
    }

    private static function tuple(array $tuple): array
    {
        foreach (['regular_price', 'sale_price'] as $key) {
            if (! isset($tuple[$key]) || ! is_string($tuple[$key]) || ($tuple[$key] !== '' && ! preg_match('/^\d+(?:\.\d+)?$/', $tuple[$key]))) {
                self::fail('צילום מחיר אינו תקין.');
            }
        }
        foreach (['sale_from', 'sale_to'] as $key) {
            if (! array_key_exists($key, $tuple) || ($tuple[$key] !== null && ! is_int($tuple[$key]))) {
                self::fail('צילום תאריך אינו תקין.');
            }
        }
        return array_intersect_key($tuple, array_flip(['regular_price', 'sale_price', 'sale_from', 'sale_to']));
    }

    private static function schedule(int $timestamp, string $id, string $phase): void
    {
        if (function_exists('as_schedule_single_action')) {
            $scheduled = as_schedule_single_action($timestamp, self::HOOK, [$id, $phase], self::GROUP, false);
            if (! $scheduled) {
                self::fail('לא ניתן לרשום משימת תזמון למבצע.');
            }
            return;
        }
        if (! function_exists('wp_schedule_single_event')) {
            self::fail('אין מנגנון תזמון זמין באתר.');
        }
        $result = wp_schedule_single_event($timestamp, self::HOOK, [$id, $phase], true);
        if ($result === false || (function_exists('is_wp_error') && is_wp_error($result))) {
            self::fail('לא ניתן לרשום אירוע מבצע.');
        }
    }

    private static function parentIndex(int $productId, string $id, bool $add): void
    {
        $product = wc_get_product($productId);
        $parent = $product && is_callable([$product, 'get_parent_id']) ? (int) $product->get_parent_id() : 0;
        if (! $parent) {
            return;
        }
        $ids = get_post_meta($parent, self::PARENTS, true);
        $ids = is_array($ids) ? $ids : [];
        $previous = $ids;
        $ids = array_values(array_diff($ids, [$id]));
        if ($add) {
            $ids[] = $id;
        }
        if ($ids === $previous) {
            return;
        }
        update_post_meta($parent, self::PARENTS, $ids);
        self::invalidate($parent);
    }

    private static function invalidate(int $productId): void
    {
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($productId);
            $product = wc_get_product($productId);
            $parent = $product && is_callable([$product, 'get_parent_id']) ? (int) $product->get_parent_id() : 0;
            if ($parent) {
                wc_delete_product_transients($parent);
                if (class_exists('WC_Product_Variable') && is_callable(['WC_Product_Variable', 'sync'])) {
                    WC_Product_Variable::sync($parent);
                }
            }
        }
    }

    private static function lock(int $productId): bool
    {
        if (isset(self::$locks[$productId])) {
            self::$locks[$productId]++;
            return true;
        }
        $key = 'multioto_sale_lock_'.$productId;
        $existing = get_option($key, false);
        if (is_int($existing) && $existing < time() - 900 && isset($GLOBALS['wpdb'])) {
            $wpdb = $GLOBALS['wpdb'];
            $removed = $wpdb->delete($wpdb->options, ['option_name' => $key, 'option_value' => (string) $existing], ['%s', '%s']);
            if ($removed && function_exists('wp_cache_delete')) {
                wp_cache_delete($key, 'options');
            }
        }
        if (! add_option($key, time(), '', false)) {
            return false;
        }
        self::$locks[$productId] = 1;
        return true;
    }

    private static function unlock(int $productId): void
    {
        if (! isset(self::$locks[$productId]) || --self::$locks[$productId] > 0) {
            return;
        }
        unset(self::$locks[$productId]);
        delete_option('multioto_sale_lock_'.$productId);
    }

    private static function id($id): string
    {
        if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $id)) {
            self::fail('מזהה המבצע אינו תקין.');
        }
        return $id;
    }

    private static function key(string $id): string
    {
        return 'multioto_sale_campaign_'.$id;
    }

    private static function fail(string $message): void
    {
        throw new Multioto_Agent_Rpc_Error(-32602, $message);
    }
}
