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
     * Returns the total number of matches alongside the page of results, so a
     * caller can tell "these are all of them" from "these are the first fifty".
     *
     * @return array{total: int, returned: int, products: array<int, array<string, mixed>>}
     */
    public static function search(string $term, int $limit = 10, int $page = 1): array
    {
        // The per-call ceiling is about the size of ONE answer, not about how
        // many products a caller may work through: pages beyond the first are
        // fetched with `page`, so "all the shirts" in a shop with hundreds of
        // them is a sequence of ordinary calls rather than a limit nobody can
        // get past.
        $limit = min(100, max(1, $limit));
        $page = max(1, $page);
        $found = [];

        // SKU first, and separately: `s` searches post title and content, and a
        // SKU lives in product data. An exact SKU would otherwise come back
        // empty unless it happened to appear in the description — precisely the
        // lookup somebody does before repricing, answered with "no such
        // product" about a product that exists.
        $bySku = wc_get_product_id_by_sku(trim($term));
        $skuProduct = $bySku > 0 ? wc_get_product($bySku) : null;
        if (! $skuProduct instanceof WC_Product || ! in_array($skuProduct->get_status(), ['publish', 'draft', 'private'], true)) {
            $skuProduct = null;
        }

        // Exclude the SKU hit from EVERY text page, even if its title matches
        // a later one. This makes the union total stable and prevents the hit
        // appearing twice while the caller walks the complete result set.
        if ($page === 1 && $skuProduct !== null) {
            $found[$bySku] = self::summary($skuProduct);
        }

        // `paginate` so the answer can say how many matched, not only how many
        // fit. Without the total, a caller acting on "all the shirts" in a shop
        // with two hundred of them silently acts on the first fifty and every
        // report it writes says "all".
        $query = wc_get_products([
            's' => $term,
            'limit' => $limit,
            'page' => $page,
            'status' => ['publish', 'draft', 'private'],
            'exclude' => $skuProduct !== null ? [$bySku] : [],
            // Stable across pages: relevance can reorder between calls, and a
            // walk over shifting order silently skips products and repeats
            // others — the caller then acts on "all of them" having missed some.
            'orderby' => 'ID',
            'order' => 'ASC',
            'paginate' => true,
        ]);

        foreach ($query->products as $product) {
            $found[$product->get_id()] = self::summary($product);
        }

        // The SKU hit rides ALONGSIDE the text page, never in place of one of
        // its rows.
        //
        // Trimming back to $limit would drop the page's last product to make
        // room — and `pages` is counted from the text query alone, so with a
        // single full page that product would not appear on any page at all.
        // A walk over "all the shirts" would then miss one, and nothing would
        // say so. One extra row in a single answer is the cheaper problem.
        $extra = $page === 1 && $skuProduct !== null ? 1 : 0;
        $products = array_slice(array_values($found), 0, $limit + $extra);

        // query.total excludes the SKU hit by construction, on every page.
        $total = (int) $query->total + ($skuProduct !== null ? 1 : 0);

        return [
            // Never fewer than what is in the box: the page is proof those
            // products matched, whatever the count says.
            'total' => max($total, count($products)),
            'returned' => count($products),
            'page' => $page,
            'pages' => max(1, (int) $query->max_num_pages),
            'products' => $products,
        ];
    }

    /** @return array<string, mixed> */
    public static function get(int $productId): array
    {
        return self::summary(self::product($productId));
    }

    /** Whole-store counts: parent products and variations are separate totals. */
    public static function counts(): array
    {
        if (! self::active() || ! function_exists('wp_count_posts')) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'ספירת מוצרי WooCommerce אינה זמינה באתר.');
        }

        return [
            'products' => self::countsForPostType('product'),
            'variations' => self::countsForPostType('product_variation'),
            'excluded_from_total' => ['trash', 'auto-draft'],
        ];
    }

    /** Fixed post types, two core aggregate counts, never a paginated search. */
    private static function countsForPostType(string $postType): array
    {
        if (! post_type_exists($postType)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'סוג התוכן הנדרש לספירת המוצרים אינו זמין.');
        }
        // The authenticated site-management endpoint has the same whole-store
        // scope as product reads, including private/draft products. The core
        // count cache is invalidated by normal WordPress product state changes.
        $counts = wp_count_posts($postType);
        if (! is_object($counts)) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'לא ניתן לאמת את ספירת המוצרים.');
        }
        $byStatus = array_fill_keys(['publish', 'draft', 'private', 'pending', 'future', 'trash', 'auto-draft'], 0);
        $total = 0;
        foreach (get_object_vars($counts) as $status => $count) {
            if (! is_string($status) || ! preg_match('/^[a-z0-9_-]{1,20}$/D', $status)
                || ! (is_int($count) || is_string($count)) || ! preg_match('/^\d+$/D', (string) $count)
                || filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                throw new Multioto_Agent_Rpc_Error(-32000, 'לא ניתן לאמת את ספירת המוצרים לפי סטטוס.');
            }
            $byStatus[$status] = (int) $count;
            if (! in_array($status, ['trash', 'auto-draft'], true)) {
                if ($byStatus[$status] > PHP_INT_MAX - $total) {
                    throw new Multioto_Agent_Rpc_Error(-32000, 'ספירת המוצרים חורגת מהטווח הנתמך.');
                }
                $total += $byStatus[$status];
            }
        }

        return ['total' => $total, 'by_status' => $byStatus];
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
        if (class_exists('Multioto_Agent_Sale_Schedule')) {
            return Multioto_Agent_Sale_Schedule::withProductLocks([$productId], static function () use ($productId, $args): array {
                return self::updateLocked($productId, $args);
            });
        }
        return self::updateLocked($productId, $args);
    }

    private static function updateLocked(int $productId, array $args): array
    {
        $virtual = self::virtualValue($args);
        $product = self::product($productId);
        if ($virtual !== null && ! in_array($product->get_type(), ['simple', 'variation'], true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'virtual ניתן לעדכון רק במוצר פשוט או בווריאציה מסוימת, ולא במוצר האב או בסוג מוצר אחר.');
        }
        $previous = self::summary($product);
        $beforeTuple = class_exists('Multioto_Agent_Sale_Schedule') ? Multioto_Agent_Sale_Schedule::productTuple($product) : null;
        $changed = [];

        if ($virtual !== null) {
            $product->set_virtual($virtual);
            $changed['virtual'] = $virtual;
        }

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
            if (! array_key_exists($key, $args)) {
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

        // Settable on its own, because setting a quantity turns stock
        // management ON as a side effect — and without a way to turn it off
        // again, an undo cannot put back a product that was never managing
        // stock in the first place.
        if (array_key_exists('manage_stock', $args)) {
            $manage = filter_var($args['manage_stock'], FILTER_VALIDATE_BOOLEAN);
            $product->set_manage_stock($manage);
            $changed['manage_stock'] = $manage;
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

        if (isset($args['name'])) {
            $name = trim(sanitize_text_field((string) $args['name']));

            if ($name === '') {
                throw new Multioto_Agent_Rpc_Error(-32602, 'שם מוצר אינו יכול להיות ריק.');
            }

            $product->set_name($name);
            $changed['name'] = $name;
        }

        if (isset($args['short_description'])) {
            $short = wp_kses_post((string) $args['short_description']);
            $product->set_short_description($short);
            $changed['short_description'] = $short;
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

        $from = $product->get_date_on_sale_from();
        $to = $product->get_date_on_sale_to();
        if ($from && $to && $to->getTimestamp() <= $from->getTimestamp()) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'סיום המבצע חייב להיות אחרי תחילתו.');
        }
        $pricing = (bool) array_intersect(array_keys($changed), ['regular_price', 'sale_price', 'sale_from', 'sale_to']);
        $detached = null;
        $owner = null;
        $intended = self::summary($product);
        $touched = array_keys($changed);
        if (array_key_exists('sale_price', $changed) && $changed['sale_price'] === '') {
            $touched = array_merge($touched, ['sale_from', 'sale_to']);
        }
        if (array_key_exists('stock_quantity', $changed)) {
            $touched[] = 'manage_stock';
        }
        $touched = array_values(array_unique($touched));
        try {
            if ($pricing && class_exists('Multioto_Agent_Sale_Schedule')) {
                $detached = Multioto_Agent_Sale_Schedule::detach($productId);
                $product->set_price($product->is_on_sale('edit') ? $product->get_sale_price('edit') : $product->get_regular_price('edit'));
            }
            if (! $product->save()) {
                throw new Multioto_Agent_Rpc_Error(-32000, 'שמירת המוצר נכשלה.');
            }
            $saved = self::summary(self::product($productId));
            foreach ($touched as $key) {
                if ($saved[$key] !== $intended[$key]) {
                    throw new Multioto_Agent_Rpc_Error(-32000, 'המוצר לא שמר את כל השינויים המבוקשים.');
                }
            }
            if ($pricing && class_exists('Multioto_Agent_Sale_Schedule') && $product->get_sale_price('edit') !== '' && (! $to || $to->getTimestamp() > time())) {
                $afterTuple = Multioto_Agent_Sale_Schedule::productTuple($product);
                $owner = 'single_'.bin2hex(random_bytes(12));
                Multioto_Agent_Sale_Schedule::register([
                    'id' => $owner, 'starts_at' => $from ? $from->getTimestamp() : time(), 'ends_at' => $to ? $to->getTimestamp() : PHP_INT_MAX,
                    'products' => [['id' => $productId, 'before' => $beforeTuple, 'after' => $afterTuple]],
                ]);
            }
        } catch (Throwable $error) {
            if ($pricing && class_exists('Multioto_Agent_Sale_Schedule')) {
                if ($owner) {
                    Multioto_Agent_Sale_Schedule::cancel($owner, false);
                }
                try {
                    $fresh = self::product($productId);
                    $live = self::summary($fresh);
                    foreach (array_unique($touched) as $key) {
                        if ($live[$key] !== $intended[$key] && $live[$key] !== $previous[$key]) {
                            throw new RuntimeException('A changed field was edited outside this request.');
                        }
                    }
                    foreach (array_unique($touched) as $key) {
                        if (in_array($key, ['sale_from', 'sale_to'], true)) {
                            $setter = $key === 'sale_from' ? 'set_date_on_sale_from' : 'set_date_on_sale_to';
                            $fresh->{$setter}($beforeTuple[$key]);
                        } else {
                            $setter = 'set_'.$key;
                            $fresh->{$setter}($previous[$key]);
                        }
                    }
                    $fresh->set_price($fresh->is_on_sale('edit') ? $fresh->get_sale_price('edit') : $fresh->get_regular_price('edit'));
                    if (! $fresh->save()) {
                        throw new RuntimeException('Restoring the product failed.');
                    }
                    Multioto_Agent_Sale_Schedule::restoreDetached($productId, $detached);
                } catch (Throwable $restoreError) {
                    throw new Multioto_Agent_Rpc_Error(-32000, 'התזמון נכשל והשחזור לא הושלם. נדרשת בדיקה באתר.');
                }
            }
            throw $error;
        }

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
        $virtual = self::virtualValue($args);
        $name = trim((string) ($args['name'] ?? ''));

        if ($name === '') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר שם מוצר (name).');
        }

        $product = new WC_Product_Simple;
        if ($virtual !== null) {
            $product->set_virtual($virtual);
        }
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

        if (! $product->save()) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'שמירת טיוטת המוצר נכשלה.');
        }

        if ($virtual !== null) {
            // A draft already exists. Keep its ID even if readback fails so the
            // caller can inspect it instead of retrying and creating a duplicate.
            try {
                $saved = self::summary(self::product($product->get_id()));

                return $saved + ['created_as' => 'draft', 'virtual_applied' => $saved['virtual'] === $virtual];
            } catch (Throwable $error) {
                return ['id' => $product->get_id(), 'created_as' => 'draft', 'verification_failed' => true];
            }
        }

        return self::summary($product) + ['created_as' => 'draft'];
    }

    /** An explicit false clears the flag; omission leaves Woo's value alone. */
    private static function virtualValue(array $args): ?bool
    {
        if (! array_key_exists('virtual', $args)) {
            return null;
        }
        if (! is_bool($args['virtual'])) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'virtual חייב להיות ערך בוליאני true או false.');
        }

        return $args['virtual'];
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
            'type' => $product->get_type(),
            'virtual' => (bool) $product->get_virtual('edit'),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'sale_from' => self::saleDateSummary($product->get_date_on_sale_from()),
            'sale_to' => self::saleDateSummary($product->get_date_on_sale_to()),
            'on_sale' => $product->is_on_sale(),
            'timezone' => self::timezone()->getName(),
            'manage_stock' => $product->get_manage_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            // Part of the summary because the summary IS the undo snapshot: a
            // rename or a new blurb is only reversible if the old one is here.
            'short_description' => $product->get_short_description(),
            // The featured image, by id. Products are not readable through the
            // content tools, so this is the only way a caller can tell whether
            // the picture it is about to replace is still the one it saw.
            'thumbnail_id' => (int) $product->get_image_id(),
            'url' => get_permalink($product->get_id()),
        ];
    }

    /**
     * Move a product to the trash — never a permanent delete.
     *
     * The product leaves the shop at once, and a manager (or the undo from
     * the panel, through trashRestore) can bring it back as it was: WordPress
     * keeps the status it had before it went in.
     *
     * @return array{trashed_id: int, previous_status: string}
     */
    public static function trash(int $productId): array
    {
        $product = self::product($productId);
        $status = (string) $product->get_status();

        if ($status === 'trash') {
            throw new Multioto_Agent_Rpc_Error(-32602, "המוצר {$productId} כבר בפח.");
        }

        if (! wp_trash_post($product->get_id())) {
            throw new Multioto_Agent_Rpc_Error(-32000, "לא ניתן להעביר לפח את המוצר {$productId}.");
        }

        return ['trashed_id' => (int) $product->get_id(), 'previous_status' => $status];
    }

    /**
     * Take a product back out of the trash, to the status it had before.
     *
     * @return array{restored_id: int, status: string}
     */
    public static function trashRestore(int $productId): array
    {
        $post = $productId > 0 ? get_post($productId) : null;

        if (! $post || $post->post_type !== 'product' || $post->post_status !== 'trash') {
            throw new Multioto_Agent_Rpc_Error(-32602, "המוצר {$productId} אינו בפח.");
        }

        $previous = (string) get_post_meta($post->ID, '_wp_trash_meta_status', true);

        if (! wp_untrash_post($post->ID)) {
            throw new Multioto_Agent_Rpc_Error(-32000, "לא ניתן להחזיר את המוצר {$productId} מהפח.");
        }

        // Since WP 5.6 untrash lands on draft; put back what it really was.
        if ($previous !== '' && get_post_status($post->ID) !== $previous) {
            wp_update_post(['ID' => $post->ID, 'post_status' => $previous]);
        }

        return ['restored_id' => (int) $post->ID, 'status' => (string) get_post_status($post->ID)];
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
        if (class_exists('Multioto_Agent_Sale_Schedule')) {
            $timestamp = Multioto_Agent_Sale_Schedule::parse($value);
            $local = new DateTimeImmutable('@'.$timestamp);
        } else {
            $local = date_create_immutable_from_format('Y-m-d|', $value, self::timezone());
            if ($local === false || $local->format('Y-m-d') !== $value) {
                throw new Multioto_Agent_Rpc_Error(-32602, "{$field} אינו תאריך תקין בפורמט YYYY-MM-DD.");
            }
        }

        // Built from the absolute instant and then moved into the shop's zone —
        // the same two steps WooCommerce itself uses when it stores a date.
        $date = new WC_DateTime('@'.$local->getTimestamp());
        $date->setTimezone(self::timezone());

        return $date;
    }

    private static function saleDateSummary($date): ?string
    {
        if (! $date) {
            return null;
        }
        return class_exists('Multioto_Agent_Sale_Schedule')
            ? Multioto_Agent_Sale_Schedule::format($date->getTimestamp())
            : $date->date('Y-m-d');
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
