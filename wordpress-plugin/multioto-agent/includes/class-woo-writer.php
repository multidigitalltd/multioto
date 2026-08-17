<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Changing a shop: prices, sales, stock and coupons.
 *
 * Everything here goes through WooCommerce's own CRUD objects rather than post
 * meta. `_price` is a derived field — WooCommerce recalculates it from the
 * regular and sale prices and from whether a scheduled sale is currently
 * running — so writing it directly produces a shop whose listed price and
 * checkout price disagree, which is the single worst bug a store can have.
 *
 * Every write returns the previous values. That is not a courtesy: the platform
 * stores them as the snapshot behind "undo", and a price change nobody can undo
 * is a price change nobody should make from a phone.
 */
class Multioto_Agent_Woo_Writer
{
    public static function active(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    /**
     * Find products by name or SKU.
     *
     * The agent is given a sentence — "the black t-shirt" — and needs an id.
     * Returning several candidates rather than a best guess is deliberate: the
     * caller asks which one instead of silently repricing the wrong shirt.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $term, int $limit = 10): array
    {
        $limit = min(50, max(1, $limit));
        $found = [];

        // SKU first, and separately: `s` searches post title and content, and a
        // SKU lives in product data. An exact SKU would otherwise come back
        // empty unless it happened to appear in the description — precisely the
        // lookup somebody does before repricing, answered with "no such
        // product" about a product that exists.
        $bySku = wc_get_product_id_by_sku(trim($term));

        if ($bySku > 0 && ($product = wc_get_product($bySku)) instanceof WC_Product) {
            $found[$bySku] = self::summary($product);
        }

        foreach (wc_get_products([
            's' => $term,
            'limit' => $limit,
            'status' => ['publish', 'draft', 'private'],
            'orderby' => 'relevance',
        ]) as $product) {
            $found[$product->get_id()] = self::summary($product);
        }

        return array_slice(array_values($found), 0, $limit);
    }

    /** @return array<string, mixed> */
    public static function get(int $productId): array
    {
        return self::summary(self::product($productId));
    }

    /**
     * Change a product, and report exactly what changed.
     *
     * A sale price with dates is one operation and not three, because a sale
     * whose price was set and whose end date was not is a discount that runs
     * forever — and it is discovered a month later, in the accounts.
     *
     * @param  array<string, mixed>  $args
     * @return array{updated_id: int, changed: array<string, mixed>, previous: array<string, mixed>}
     */
    public static function update(int $productId, array $args): array
    {
        $product = self::product($productId);
        $previous = self::summary($product);
        $changed = [];

        if (isset($args['regular_price'])) {
            $product->set_regular_price(self::price($args['regular_price'], 'regular_price'));
            $changed['regular_price'] = (string) $args['regular_price'];
        }

        if (array_key_exists('sale_price', $args)) {
            // An explicit null/empty ends the sale — the only way to say "back
            // to full price" without inventing a separate tool for it.
            $sale = $args['sale_price'] === null || $args['sale_price'] === ''
                ? ''
                : self::price($args['sale_price'], 'sale_price');

            $product->set_sale_price($sale);
            $changed['sale_price'] = $sale;

            if ($sale === '') {
                $product->set_date_on_sale_from(null);
                $product->set_date_on_sale_to(null);
            }
        }

        foreach (['sale_from' => 'set_date_on_sale_from', 'sale_to' => 'set_date_on_sale_to'] as $key => $setter) {
            if (! isset($args[$key])) {
                continue;
            }

            $date = (string) $args[$key];
            $product->{$setter}($date === '' ? null : self::date($date, $key));
            $changed[$key] = $date;
        }

        if (isset($args['stock_quantity'])) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $args['stock_quantity']);
            $changed['stock_quantity'] = (int) $args['stock_quantity'];
        }

        if (isset($args['stock_status'])) {
            $status = (string) $args['stock_status'];

            if (! in_array($status, ['instock', 'outofstock', 'onbackorder'], true)) {
                throw new Multioto_Agent_Rpc_Error(-32602,
                    'stock_status חייב להיות instock, outofstock או onbackorder.');
            }

            $product->set_stock_status($status);
            $changed['stock_status'] = $status;
        }

        if (isset($args['status'])) {
            $status = (string) $args['status'];

            if (! in_array($status, ['publish', 'draft', 'private'], true)) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'status חייב להיות publish, draft או private.');
            }

            $product->set_status($status);
            $changed['status'] = $status;
        }

        if ($changed === []) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'לא צוין שום שדה לעדכון.');
        }

        // A sale price at or above the regular price is not a discount; Woo
        // accepts it and the shop then advertises a "sale" that saves nothing.
        self::assertSaleBelowRegular($product);

        $product->save();

        return ['updated_id' => $productId, 'changed' => $changed, 'previous' => $previous];
    }

    /**
     * Create a product — always as a draft.
     *
     * Nothing an agent creates goes on sale by itself. Publishing is a separate,
     * deliberate act by a person looking at the page.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function create(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));

        if ($name === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר שם מוצר (name).');
        }

        $product = new WC_Product_Simple;
        $product->set_name(sanitize_text_field($name));
        $product->set_status('draft');
        $product->set_description(wp_kses_post((string) ($args['description'] ?? '')));
        $product->set_short_description(wp_kses_post((string) ($args['short_description'] ?? '')));

        if (isset($args['regular_price'])) {
            $product->set_regular_price(self::price($args['regular_price'], 'regular_price'));
        }

        if (isset($args['sku']) && (string) $args['sku'] !== '') {
            $product->set_sku(sanitize_text_field((string) $args['sku']));
        }

        $product->save();

        return self::summary($product) + ['created_as' => 'draft'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function coupons(int $limit = 30): array
    {
        $posts = get_posts([
            'post_type' => 'shop_coupon',
            'post_status' => ['publish', 'draft'],
            'numberposts' => min(100, max(1, $limit)),
        ]);

        return array_map(static function (WP_Post $post): array {
            $coupon = new WC_Coupon($post->ID);

            return [
                'id' => $post->ID,
                'code' => $coupon->get_code(),
                'type' => $coupon->get_discount_type(),
                'amount' => $coupon->get_amount(),
                'expires' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d') : null,
                'usage_count' => $coupon->get_usage_count(),
            ];
        }, $posts);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function createCoupon(array $args): array
    {
        $code = strtolower(trim((string) ($args['code'] ?? '')));

        if ($code === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר קוד קופון (code).');
        }

        if (wc_get_coupon_id_by_code($code) > 0) {
            throw new Multioto_Agent_Rpc_Error(-32602, "קופון בקוד {$code} כבר קיים.");
        }

        $type = (string) ($args['type'] ?? 'percent');

        if (! in_array($type, ['percent', 'fixed_cart', 'fixed_product'], true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'type חייב להיות percent, fixed_cart או fixed_product.');
        }

        $amount = (float) ($args['amount'] ?? 0);

        if ($amount <= 0 || ($type === 'percent' && $amount > 100)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'amount אינו סביר עבור סוג הקופון.');
        }

        $coupon = new WC_Coupon;
        $coupon->set_code($code);
        $coupon->set_discount_type($type);
        $coupon->set_amount($amount);

        if (isset($args['expires']) && (string) $args['expires'] !== '') {
            $coupon->set_date_expires(self::date((string) $args['expires'], 'expires'));
        }

        if (isset($args['minimum_amount'])) {
            $coupon->set_minimum_amount(self::price($args['minimum_amount'], 'minimum_amount'));
        }

        if (isset($args['usage_limit'])) {
            $coupon->set_usage_limit((int) $args['usage_limit']);
        }

        $coupon->save();

        return ['created_id' => $coupon->get_id(), 'code' => $coupon->get_code()];
    }

    /**
     * End a coupon now, by setting its expiry to today.
     *
     * Expired rather than deleted: a deleted coupon disappears from the orders
     * that used it, and "why does this old order show no discount" is then
     * unanswerable.
     *
     * @return array{coupon_id: int, code: string, previous_expiry: ?string}
     */
    public static function expireCoupon(string $code): array
    {
        $id = wc_get_coupon_id_by_code(strtolower(trim($code)));

        if ($id <= 0) {
            throw new Multioto_Agent_Rpc_Error(-32602, "לא נמצא קופון בקוד {$code}.");
        }

        $coupon = new WC_Coupon($id);
        $previous = $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d') : null;

        $coupon->set_date_expires(current_time('Y-m-d'));
        $coupon->save();

        return ['coupon_id' => $id, 'code' => $coupon->get_code(), 'previous_expiry' => $previous];
    }

    /** @return array<string, mixed> */
    private static function summary(WC_Product $product): array
    {
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'status' => $product->get_status(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'sale_from' => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date('Y-m-d') : null,
            'sale_to' => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date('Y-m-d') : null,
            'on_sale' => $product->is_on_sale(),
            'manage_stock' => $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'url' => get_permalink($product->get_id()),
        ];
    }

    private static function product(int $productId): WC_Product
    {
        $product = $productId > 0 ? wc_get_product($productId) : null;

        if (! $product instanceof WC_Product) {
            throw new Multioto_Agent_Rpc_Error(-32602, "המוצר {$productId} לא נמצא.");
        }

        return $product;
    }

    /** A price the shop can actually use: a non-negative number, as a string. */
    private static function price($value, string $field): string
    {
        if (! is_numeric($value) || (float) $value < 0) {
            throw new Multioto_Agent_Rpc_Error(-32602, "{$field} חייב להיות מספר אי-שלילי.");
        }

        return wc_format_decimal($value);
    }

    /**
     * A date, read in the SHOP's timezone.
     *
     * strtotime() resolves a bare date against PHP's default timezone, which on
     * most servers is UTC. A sale asked to end "on the 20th" would then end at
     * 03:00 on the 20th Israel time — three hours of a promotion the owner
     * believed was running, or three hours of a discount they believed had
     * stopped. The date the customer says is the date in their own shop.
     *
     * The format is strict: `strtotime` would cheerfully read "yesterday" or a
     * half-typed date and produce something, and a promotion is not a place for
     * a lenient parser.
     */
    private static function date(string $value, string $field): WC_DateTime
    {
        $value = trim($value);
        $local = date_create_immutable_from_format('Y-m-d|', $value, self::timezone());

        if ($local === false || $local->format('Y-m-d') !== $value) {
            throw new Multioto_Agent_Rpc_Error(-32602, "{$field} אינו תאריך תקין בפורמט YYYY-MM-DD.");
        }

        // Built from the absolute instant and then moved into the shop's zone —
        // the same two steps WooCommerce itself uses when it stores a date.
        $date = new WC_DateTime('@'.$local->getTimestamp());
        $date->setTimezone(self::timezone());

        return $date;
    }

    /** The shop's timezone, however this WordPress happens to express it. */
    private static function timezone(): DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        return new DateTimeZone(function_exists('wc_timezone_string') ? wc_timezone_string() : 'UTC');
    }

    private static function assertSaleBelowRegular(WC_Product $product): void
    {
        $sale = $product->get_sale_price();
        $regular = $product->get_regular_price();

        if ($sale === '' || $regular === '') {
            return;
        }

        if ((float) $sale >= (float) $regular) {
            throw new Multioto_Agent_Rpc_Error(-32602,
                "מחיר המבצע ({$sale}) אינו נמוך מהמחיר הרגיל ({$regular}) — זה לא היה מוצג כהנחה.");
        }
    }
}
