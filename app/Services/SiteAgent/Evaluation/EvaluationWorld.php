<?php

namespace App\Services\SiteAgent\Evaluation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Stateful, isolated WordPress/WooCommerce boundary for live-model evaluation.
 * This is a simulator, not a claim that WordPress/plugin integration passed.
 * Every supported mutation has an observable state transition; unsupported
 * tools fail, and there is deliberately no HTTP client in this world.
 */
class EvaluationWorld
{
    public array $state = [];

    public array $calls = [];

    private EvaluationAdvancedWorld $advanced;

    private const READS = [
        'wp_health', 'wp_plugin_list', 'wp_theme_list', 'wp_admin_list', 'wp_option_get', 'wp_error_log_tail',
        'wp_menu_list', 'wp_post_types_list', 'wp_content_list', 'wp_content_get', 'wp_content_details', 'wp_internal_links_get',
        'wp_user_list', 'wp_user_profile_get', 'wp_theme_active_get', 'wp_comment_list', 'wp_taxonomy_list', 'wp_term_list', 'wp_post_terms_get',
        'wp_media_list', 'wp_media_get', 'wp_elementor_texts_get', 'wc_product_search', 'wc_product_counts', 'wc_product_get',
        'wc_coupon_list', 'wc_order_get', 'wc_order_list', 'wc_order_stats_get', 'wc_sales_report', 'wc_shipping_zones_list',
        'wcs_subscription_list', 'wcs_subscription_get', 'wp_lead_list',
    ];

    private const WRITES = [
        'wp_content_create', 'wp_content_update', 'wp_content_trash', 'wp_content_restore', 'wp_content_manage', 'wp_internal_link_update',
        'wp_user_create', 'wp_user_role_set', 'wp_user_profile_update', 'wp_theme_active_set', 'wp_comment_moderate',
        'wp_term_create', 'wp_post_terms_set', 'wp_menu_item_add', 'wp_menu_item_update', 'wp_menu_item_unlink',
        'wp_media_upload', 'wp_media_update', 'wp_media_delete', 'wp_post_thumbnail_set', 'wp_elementor_text_update',
        'wc_product_update', 'wc_product_create', 'wc_product_trash', 'wc_product_restore', 'wc_coupon_create', 'wc_coupon_expire',
        'wc_order_status_set', 'wc_order_note_add', 'wcs_subscription_status_set',
        'wp_plugin_activate', 'wp_plugin_deactivate', 'wp_plugin_update', 'wp_theme_update', 'wp_cache_flush',
    ];

    public function __construct(?EvaluationAdvancedWorld $advanced = null)
    {
        $this->advanced = $advanced ?? new EvaluationAdvancedWorld;
        $this->reset();
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->state = [
            'content' => [], 'products' => [], 'media' => [], 'users' => [], 'orders' => [],
            'terms' => ['product_cat' => [], 'category' => [], 'post_tag' => []], 'post_terms' => [],
            'menus' => [], 'plugins' => [], 'themes' => [], 'active_theme' => 'twentytwentyfive',
            'coupons' => [], 'subscriptions' => [], 'comments' => [], 'elementor' => [], 'cache_flushes' => 0,
            'next_ids' => ['content' => 1000, 'products' => 40000, 'media' => 500, 'users' => 100, 'terms' => 100, 'menu_items' => 100, 'coupons' => 100, 'notes' => 100],
            'settings' => ['blogname' => 'חנות הדוגמה', 'blogdescription' => 'מוצרים ושירותים', 'timezone_string' => 'Asia/Jerusalem', 'gmt_offset' => 3, 'show_on_front' => 'page', 'page_on_front' => 43, 'page_for_posts' => 0, 'posts_per_page' => 10, 'date_format' => 'd/m/Y', 'time_format' => 'H:i', 'start_of_week' => 0],
        ];
        foreach ([
            [43, 'דף הבית', 'איזה כייף שבאת. הטלפון שלנו 03-1234567.', 'page', 'publish'],
            [44, 'צור קשר', 'טלפון: 03-1234567', 'page', 'publish'],
            [45, 'אודות', 'אנחנו כאן מאז 2010.', 'page', 'publish'],
            [46, 'חדשות החברה', 'העדכונים האחרונים של החברה.', 'post', 'draft'],
            [47, 'פרויקט צפון', 'פרויקט חדש בצפון.', 'project', 'publish'],
            [48, 'עמוד נחיתה', '', 'page', 'publish'],
        ] as [$id, $title, $content, $type, $status]) {
            $this->state['content'][$id] = $this->contentRecord($id, compact('title', 'content', 'type', 'status'));
        }
        $this->state['content'][48]['built_with_elementor'] = true;
        $this->state['elementor'][48] = ['hero' => ['setting' => 'title', 'text' => 'לומדים ביחד']];
        foreach ([[7, 'חולצה כחולה', '100.00', 12, false, 'SHIRT-BLUE'], [8, 'חולצה אדומה', '120.00', 6, false, 'SHIRT-RED'], [9, 'קורס דיגיטלי', '250.00', null, true, 'COURSE'], [33852, 'מוצר דוגמה', '150.00', null, false, 'DEMO']] as [$id, $name, $price, $stock, $virtual, $sku]) {
            $this->state['products'][$id] = $this->productRecord($id, ['name' => $name, 'regular_price' => $price, 'stock_quantity' => $stock, 'manage_stock' => $stock !== null, 'virtual' => $virtual, 'sku' => $sku, 'status' => 'publish']);
        }
        for ($i = 1; $i <= 36; $i++) {
            $id = 100 + $i;
            $this->state['products'][$id] = $this->productRecord($id, ['name' => 'מוצר קטלוג '.$i, 'regular_price' => '50.00', 'status' => 'publish', 'sku' => 'CAT-'.$i]);
        }
        $this->state['media'][90] = $this->mediaRecord(90, ['title' => 'לוגו החברה', 'alt' => 'לוגו כחול', 'filename' => 'logo.png']);
        $this->state['media'][91] = $this->mediaRecord(91, ['title' => 'תמונת חולצה', 'alt' => 'חולצה כחולה', 'filename' => 'shirt.png']);
        $this->state['users'][1] = ['id' => 1, 'login' => 'admin', 'email' => 'admin@example.test', 'display_name' => 'מנהל האתר', 'first_name' => 'מנהל', 'last_name' => 'האתר', 'description' => '', 'roles' => ['administrator'], 'registered' => '2025-01-01'];
        $this->state['users'][5] = ['id' => 5, 'login' => 'noa', 'email' => 'noa@example.test', 'display_name' => 'נועה כהן', 'first_name' => 'נועה', 'last_name' => 'כהן', 'description' => '', 'roles' => ['subscriber'], 'registered' => '2026-01-01'];
        $this->state['orders'][1001] = ['id' => 1001, 'number' => '1001', 'status' => 'processing', 'status_label' => 'בטיפול', 'date' => '2026-10-09 08:00', 'total' => '150.00', 'currency' => 'ILS', 'customer' => 'נועה כהן', 'phone' => '0500000005', 'email' => 'noa@example.test', 'items' => [['product_id' => 33852, 'name' => 'מוצר דוגמה', 'quantity' => 1, 'total' => '150.00']], 'notes' => [], 'payment_method' => 'כרטיס אשראי'];
        $this->state['subscriptions'][601] = ['id' => 601, 'status' => 'active', 'status_label' => 'פעיל', 'customer' => 'נועה כהן', 'email' => 'noa@example.test', 'phone' => '0500000005', 'total' => '50.00', 'currency' => 'ILS', 'billing' => 'every 1 month', 'next_payment' => '2026-11-09', 'items' => ['מנוי חודשי']];
        $this->state['coupons']['WELCOME10'] = ['id' => 70, 'code' => 'WELCOME10', 'type' => 'percent', 'amount' => '10.00', 'expires' => '2026-12-31', 'minimum_amount' => '0.00', 'usage_limit' => 0, 'usage_count' => 0];
        foreach ([['product_cat', 12, 'חולצות'], ['category', 13, 'חדשות']] as [$taxonomy, $id, $name]) {
            $this->state['terms'][$taxonomy][$id] = ['id' => $id, 'name' => $name, 'slug' => $taxonomy.'-'.$id, 'description' => '', 'parent' => 0, 'count' => $id === 12 ? 2 : 1];
        }
        $this->state['post_terms'] = [7 => ['product_cat' => [12]], 8 => ['product_cat' => [12]], 46 => ['category' => [13]]];
        $this->state['menus'][2] = ['menu' => 'ראשי', 'menu_id' => 2, 'items' => [21 => ['item_id' => 21, 'title' => 'צור קשר', 'url' => $this->url(44), 'parent_id' => 0, 'order' => 1, 'page_id' => 44]]];
        foreach ([['hello-dolly/hello.php', 'Hello Dolly', false, '1.7.2', '1.7.3'], ['woocommerce/woocommerce.php', 'WooCommerce', true, '10.0.0', null], ['wordpress-seo/wp-seo.php', 'Yoast SEO', true, '26.0', null]] as [$plugin, $name, $active, $version, $next]) {
            $this->state['plugins'][$plugin] = ['plugin' => $plugin, 'name' => $name, 'active' => $active, 'version' => $version, 'update_available' => $next !== null, 'new_version' => $next];
        }
        $this->state['themes'] = [
            'twentytwentyfive' => ['stylesheet' => 'twentytwentyfive', 'name' => 'Twenty Twenty-Five', 'version' => '1.2', 'active' => true, 'update_available' => false],
            'astra' => ['stylesheet' => 'astra', 'name' => 'Astra', 'version' => '4.0', 'active' => false, 'update_available' => true, 'new_version' => '4.1'],
        ];
        $this->state['comments'][31] = ['id' => 31, 'post_id' => 43, 'author' => 'נועה', 'email' => 'noa@example.test', 'content' => 'אתר נהדר', 'status' => 'hold', 'date' => '2026-10-09'];
        $this->state['shipping_zones'][1] = ['id' => 1, 'name' => 'ישראל', 'locations' => [['code' => 'IL', 'type' => 'country']], 'methods' => [['id' => 'flat_rate', 'title' => 'משלוח רגיל', 'enabled' => true, 'cost' => '10.00'], ['id' => 'free_shipping', 'title' => 'משלוח חינם', 'enabled' => true, 'min_amount' => '200.00']]];
        $this->advanced->seed($this->state);
    }

    public function supportedTools(): array
    {
        return array_values(array_unique([...self::READS, ...self::WRITES, ...$this->advanced->supportedTools()]));
    }

    public function isWrite(string $name): bool
    {
        return in_array($name, self::WRITES, true) || $this->advanced->isWrite($name);
    }

    /** Every attempt is recorded, including rejected calls, without leaving partial failed mutations. */
    public function handle(string $name, array $arguments = []): array
    {
        $index = count($this->calls);
        $this->calls[] = ['tool' => $name, 'arguments' => $arguments, 'write' => $this->isWrite($name)];
        $before = $this->state;
        try {
            if (! in_array($name, $this->supportedTools(), true)) {
                throw new LogicException('Unsupported evaluation tool: '.$name);
            }
            $result = $this->dispatch($name, $arguments);
            $this->calls[$index]['result'] = $result;
            $this->calls[$index]['changed'] = $before !== $this->state;

            return $result;
        } catch (Throwable $error) {
            $this->state = $before;
            $this->calls[$index]['error'] = get_class($error);
            $this->calls[$index]['changed'] = false;
            throw $error;
        }
    }

    private function dispatch(string $name, array $a): array
    {
        return match ($name) {
            'wp_health' => ['wp_version' => '6.8', 'php_version' => '8.3', 'is_ssl' => true, 'home' => 'https://evaluation.example', 'active_theme' => $this->state['active_theme'], 'active_plugins' => count(array_filter($this->state['plugins'], fn (array $plugin): bool => $plugin['active'])), 'total_plugins' => count($this->state['plugins'])],
            'wp_plugin_list' => array_values($this->state['plugins']),
            'wp_theme_list' => array_values($this->state['themes']),
            'wp_admin_list' => array_values(array_filter($this->state['users'], fn (array $u): bool => in_array('administrator', $u['roles'], true))),
            'wp_option_get' => ['name' => $a['name'] ?? '', 'value' => $this->state['settings'][$a['name'] ?? ''] ?? throw new InvalidArgumentException('Unsupported option')],
            'wp_error_log_tail' => ['lines' => [], 'available' => true],
            'wp_cache_flush' => $this->flushCache(),
            'wp_post_types_list' => $this->postTypes(),
            'wp_content_list' => $this->contentList($a),
            'wp_content_get' => $this->contentGet($a),
            'wp_content_create' => $this->contentCreate($a),
            'wp_content_update' => $this->contentUpdate($a),
            'wp_content_trash', 'wp_content_restore' => $this->trash('content', $this->id($a), str_ends_with($name, 'restore')),
            'wp_content_details' => $this->contentDetails($a),
            'wp_content_manage' => $this->contentManage($a),
            'wp_internal_links_get' => $this->linksGet($a),
            'wp_internal_link_update' => $this->linkUpdate($a),
            'wp_elementor_texts_get' => $this->elementorGet($a),
            'wp_elementor_text_update' => $this->elementorUpdate($a),
            'wc_product_counts' => $this->productCounts(),
            'wc_product_search' => $this->productSearch($a),
            'wc_product_get' => $this->row('products', $this->id($a, 'product_id')),
            'wc_product_create' => $this->productCreate($a),
            'wc_product_update' => $this->productUpdate($a),
            'wc_product_trash', 'wc_product_restore' => $this->trash('products', $this->id($a, 'product_id'), str_ends_with($name, 'restore')),
            'wc_order_list' => ['count' => count($r = $this->filter($this->state['orders'], $a, ['customer', 'email', 'number'])), 'orders' => $r],
            'wc_order_get' => $this->row('orders', $this->id($a, 'order_id')),
            'wc_order_status_set' => $this->statusChange('orders', $a, ['pending', 'processing', 'on-hold', 'completed', 'cancelled']),
            'wc_order_note_add' => $this->orderNote($a),
            'wc_order_stats_get' => $this->orderStats($a),
            'wc_sales_report' => $this->salesReport($a),
            'wc_shipping_zones_list' => $this->shippingZones(),
            'wcs_subscription_list' => ['count' => count($r = $this->filter($this->state['subscriptions'], $a, ['customer', 'email'])), 'subscriptions' => $r],
            'wcs_subscription_get' => $this->row('subscriptions', $this->id($a, 'subscription_id')),
            'wcs_subscription_status_set' => $this->statusChange('subscriptions', $a, ['active', 'on-hold', 'pending-cancel', 'cancelled']),
            'wc_coupon_list' => array_values($this->state['coupons']),
            'wc_coupon_create' => $this->couponCreate($a),
            'wc_coupon_expire' => $this->couponExpire($a),
            'wp_media_list' => $this->mediaList($a),
            'wp_media_get' => $this->mediaGet($a),
            'wp_media_upload' => $this->mediaUpload($a),
            'wp_media_update' => $this->mediaUpdate($a),
            'wp_post_thumbnail_set' => $this->thumbnail($a),
            'wp_media_delete' => $this->mediaDelete($a),
            'wp_user_list' => $this->userList($a),
            'wp_user_create' => $this->userCreate($a),
            'wp_user_role_set' => $this->userRole($a),
            'wp_user_profile_get' => $this->userProfile($a),
            'wp_user_profile_update' => $this->userProfileUpdate($a),
            'wp_comment_list' => ['comments' => $this->filter($this->state['comments'], $a, ['content', 'author'])],
            'wp_comment_moderate' => $this->commentModerate($a),
            'wp_taxonomy_list' => $this->taxonomies($a),
            'wp_term_list' => $this->termList($a),
            'wp_term_create' => $this->termCreate($a),
            'wp_post_terms_get' => $this->postTerms($a),
            'wp_post_terms_set' => $this->setTerms($a),
            'wp_menu_list' => array_map(fn (array $m): array => [...$m, 'items' => array_values($m['items'])], array_values($this->state['menus'])),
            'wp_menu_item_add' => $this->menuAdd($a),
            'wp_menu_item_update' => $this->menuUpdate($a),
            'wp_menu_item_unlink' => $this->menuUnlink($a),
            'wp_plugin_activate', 'wp_plugin_deactivate', 'wp_plugin_update' => $this->pluginChange($name, $a),
            'wp_theme_active_get' => ['label' => 'התבנית הפעילה', 'values' => ['stylesheet' => $this->state['active_theme']]],
            'wp_theme_active_set' => $this->themeSet($a),
            'wp_theme_update' => $this->themeUpdate($a),
            'wp_lead_list' => ['count' => 1, 'leads' => [['id' => 301, 'form' => 'צור קשר', 'date' => '2026-10-09', 'fields' => ['שם' => 'נועה כהן', 'הודעה' => 'אשמח לפרטים']]]],
            default => $this->advanced->handle($name, $a, $this->state) ?? throw new LogicException('Unimplemented evaluation tool: '.$name),
        };
    }

    private function contentRecord(int $id, array $fields): array
    {
        return [...['id' => $id, 'title' => '', 'content' => '', 'excerpt' => '', 'type' => 'post', 'status' => 'draft', 'date' => '2026-09-01 10:00:00', 'date_gmt' => '2026-09-01 07:00:00', 'parent' => 0, 'menu_order' => 0, 'slug' => ($fields['type'] ?? 'post').'-'.$id, 'thumbnail_id' => 0, 'built_with_elementor' => false, 'url' => $this->url($id)], ...$fields];
    }

    private function productRecord(int $id, array $fields): array
    {
        return [...['id' => $id, 'name' => '', 'type' => 'simple', 'virtual' => false, 'status' => 'draft', 'description' => '', 'short_description' => '', 'regular_price' => '', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'on_sale' => false, 'timezone' => 'Asia/Jerusalem', 'sku' => '', 'stock_quantity' => null, 'stock_status' => 'instock', 'manage_stock' => false, 'thumbnail_id' => 0, 'url' => $this->url($id)], ...$fields];
    }

    private function mediaRecord(int $id, array $fields): array
    {
        return [...['id' => $id, 'title' => '', 'alt' => '', 'caption' => '', 'description' => '', 'parent_id' => 0, 'terms' => [], 'mime' => 'image/png', 'filename' => 'image-'.$id.'.png', 'url' => 'https://evaluation.example/uploads/'.$id.'.png'], ...$fields];
    }

    private function postTypes(): array
    {
        return array_map(fn (string $type): array => [
            'type' => $type,
            'label' => match ($type) {
                'page' => 'עמודים', 'post' => 'פוסטים', 'project' => 'פרויקטים'
            },
            'published' => count(array_filter($this->state['content'], fn (array $row): bool => $row['type'] === $type && $row['status'] === 'publish')),
            'drafts' => count(array_filter($this->state['content'], fn (array $row): bool => $row['type'] === $type && $row['status'] === 'draft')),
            'has_custom_fields' => $type === 'page', 'builtin' => $type !== 'project',
        ], ['post', 'page', 'project']);
    }

    private function contentGet(array $a): array
    {
        $row = $this->row('content', $this->id($a));

        return [...$row, 'fields' => [], 'edit_note' => $row['built_with_elementor'] ? 'העמוד בנוי באלמנטור; לעריכת הטקסטים השתמשו בכלי Elementor.' : null];
    }

    private function contentList(array $a): array
    {
        $a += ['type' => 'page', 'status' => 'any'];

        // Match the plugin's list projection. Reading content/dates requires
        // the corresponding single-item tool, just as on an actual site.
        return array_map(fn (array $row): array => array_intersect_key($row, array_flip([
            'id', 'title', 'type', 'status', 'built_with_elementor', 'modified', 'url',
        ])), $this->filter($this->state['content'], $a, ['title', 'content']));
    }

    private function contentCreate(array $a): array
    {
        $this->only($a, ['title', 'content', 'excerpt', 'type', 'status', 'publish_at']);
        $this->text($a, 'title');
        $type = $a['type'] ?? 'post';
        $this->enum($type, ['post', 'page', 'project']);
        $status = $a['status'] ?? 'draft';
        $this->enum($status, ['draft', 'pending', 'publish', 'private', 'future']);
        $id = $this->next('content');
        $record = $this->contentRecord($id, $a);
        if (isset($a['publish_at'])) {
            [$record['date'], $record['date_gmt']] = $this->dates($a['publish_at']);
            $record['status'] = 'future';
        } elseif ($status === 'future') {
            throw new InvalidArgumentException('Scheduled content requires a date');
        }
        $this->state['content'][$id] = $record;

        return ['created_id' => $id, 'url' => $this->url($id), 'status' => $record['status']];
    }

    private function contentUpdate(array $a): array
    {
        $id = $this->id($a);
        $row = $this->row('content', $id);
        $this->only($a, ['id', 'title', 'content', 'excerpt', 'status', 'publish_at']);
        $fields = array_diff_key($a, ['id' => true]);
        if (! $fields) {
            throw new InvalidArgumentException('No content fields');
        }
        $previous = array_intersect_key($row, $fields);
        if (isset($fields['status'])) {
            $this->enum($fields['status'], ['draft', 'pending', 'publish', 'private', 'future']);
        }
        if (isset($fields['publish_at'])) {
            $previous['publish_at'] = substr($row['date'], 0, 16);
            $previous['status'] = $row['status'];
            [$fields['date'], $fields['date_gmt']] = $this->dates($fields['publish_at']);
            $fields['status'] ??= 'future';
            unset($fields['publish_at']);
        }
        $this->state['content'][$id] = [...$row, ...$fields];

        return ['updated_id' => $id, 'previous' => $previous];
    }

    private function contentDetails(array $a): array
    {
        $r = $this->row('content', $this->id($a));

        return ['id' => $r['id'], 'label' => $r['title'], 'type' => $r['type'], 'timezone' => 'Asia/Jerusalem', 'values' => array_intersect_key($r, array_flip(['status', 'date', 'date_gmt', 'parent', 'menu_order', 'slug']))];
    }

    private function contentManage(array $a): array
    {
        $id = $this->id($a);
        $values = $this->patch($a, $this->contentDetails($a)['values'], ['status', 'date', 'date_gmt', 'parent', 'menu_order', 'slug']);
        if (isset($values['status'])) {
            $this->enum($values['status'], ['draft', 'pending', 'publish', 'private', 'future']);
        }
        if (isset($values['date'])) {
            [$values['date'], $values['date_gmt']] = $this->dates($values['date']);
        }
        if (isset($values['parent']) && $values['parent'] !== 0) {
            $this->row('content', $this->positive($values['parent']));
        }
        $before = array_intersect_key($this->state['content'][$id], $values);
        $this->state['content'][$id] = [...$this->state['content'][$id], ...$values];

        return ['id' => $id, 'changed' => $before !== $values, 'before' => $before, 'after' => $values];
    }

    private function linksGet(array $a): array
    {
        $r = $this->row('content', $this->id($a));
        if ($r['built_with_elementor']) {
            throw new InvalidArgumentException('Elementor links unsupported');
        }

        return ['id' => $r['id'], 'label' => $r['title'], 'values' => ['content' => $r['content']], 'links' => []];
    }

    private function linkUpdate(array $a): array
    {
        $read = $this->linksGet($a);
        $id = $read['id'];
        $expected = $a['expected']['content'] ?? null;
        if ($expected !== $read['values']['content']) {
            throw new InvalidArgumentException('Content changed');
        }
        $values = $a['values'] ?? [];
        if (array_keys($values) === ['content']) {
            $after = $values['content'];
        } else {
            $target = $this->row('content', $this->positive($values['target_id'] ?? null));
            $text = $this->text($values, 'text');
            if ($target['status'] !== 'publish' || substr_count($expected, $text) !== 1) {
                throw new InvalidArgumentException('Ambiguous text or unpublished target');
            }
            $after = str_replace($text, '<a href="'.$target['url'].'">'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</a>', $expected);
        }
        $this->state['content'][$id]['content'] = $after;

        return ['id' => $id, 'changed' => $after !== $expected, 'before' => ['content' => $expected], 'after' => ['content' => $after]];
    }

    private function elementorGet(array $a): array
    {
        $id = $this->id($a);
        $row = $this->row('content', $id);
        if (! $row['built_with_elementor']) {
            throw new InvalidArgumentException('Not an Elementor page');
        }
        $texts = [];
        foreach ($this->state['elementor'][$id] as $widget => $value) {
            $texts[] = ['widget_id' => $widget, 'type' => 'heading', ...$value];
        }

        return ['id' => $id, 'title' => $row['title'], 'texts' => $texts];
    }

    private function elementorUpdate(array $a): array
    {
        $id = $this->id($a);
        $widget = $this->text($a, 'widget_id');
        $this->elementorGet($a);
        $current = $this->state['elementor'][$id][$widget] ?? throw new InvalidArgumentException('Widget not found');
        if (($a['setting'] ?? $current['setting']) !== $current['setting']) {
            throw new InvalidArgumentException('Setting not found');
        }
        $text = $this->text($a, 'text');
        $this->state['elementor'][$id][$widget]['text'] = $text;

        return ['id' => $id, 'widget_id' => $widget, 'setting' => $current['setting'], 'previous' => $current['text'], 'text' => $text];
    }

    private function productSearch(array $a): array
    {
        $search = $this->text($a, 'search');
        $rows = $this->filter($this->state['products'], ['search' => $search, 'limit' => 10000], ['name', 'sku']);
        $rows = array_values(array_filter($rows, fn (array $r): bool => ! in_array($r['status'], ['trash', 'auto-draft'], true)));
        $limit = min(50, max(1, (int) ($a['limit'] ?? 10)));
        $page = max(1, (int) ($a['page'] ?? 1));
        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * $limit, $limit);

        return ['total' => $total, 'returned' => count($rows), 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit)), 'products' => $rows];
    }

    private function productCounts(): array
    {
        $statuses = array_fill_keys(['publish', 'draft', 'private', 'pending', 'future', 'trash', 'auto-draft'], 0);
        foreach ($this->state['products'] as $p) {
            $statuses[$p['status']] = ($statuses[$p['status']] ?? 0) + 1;
        }

        return ['products' => ['total' => array_sum($statuses) - $statuses['trash'] - $statuses['auto-draft'], 'by_status' => $statuses], 'variations' => ['total' => 0, 'by_status' => array_fill_keys(array_keys($statuses), 0)], 'excluded_from_total' => ['trash', 'auto-draft']];
    }

    private function productCreate(array $a): array
    {
        $this->only($a, ['name', 'description', 'short_description', 'regular_price', 'sku', 'virtual']);
        $this->text($a, 'name');
        if (isset($a['virtual']) && ! is_bool($a['virtual'])) {
            throw new InvalidArgumentException('Virtual must be boolean');
        }
        if (isset($a['regular_price'])) {
            $a['regular_price'] = $this->price($a['regular_price']);
        }
        $id = $this->next('products');
        $this->state['products'][$id] = $this->productRecord($id, $a);

        return ['id' => $id, 'created_as' => 'draft', 'virtual' => $a['virtual'] ?? false, 'url' => $this->url($id)];
    }

    private function productUpdate(array $a): array
    {
        $id = $this->id($a, 'product_id');
        $before = $this->row('products', $id);
        $this->only($a, ['product_id', 'name', 'short_description', 'regular_price', 'sale_price', 'sale_from', 'sale_to', 'stock_quantity', 'manage_stock', 'stock_status', 'status', 'virtual']);
        $fields = array_diff_key($a, ['product_id' => true]);
        if (! $fields) {
            throw new InvalidArgumentException('No product fields');
        }
        foreach (['regular_price', 'sale_price'] as $key) {
            if (isset($fields[$key])) {
                $fields[$key] = $fields[$key] === '' ? '' : $this->price($fields[$key]);
            }
        }
        foreach (['virtual', 'manage_stock'] as $key) {
            if (isset($fields[$key]) && ! is_bool($fields[$key])) {
                throw new InvalidArgumentException('Boolean required');
            }
        }
        if (isset($fields['virtual']) && ! in_array($before['type'], ['simple', 'variation'], true)) {
            throw new InvalidArgumentException('Unsupported product type');
        }
        if (isset($fields['status'])) {
            $this->enum($fields['status'], ['publish', 'draft', 'private']);
        }
        if (isset($fields['stock_status'])) {
            $this->enum($fields['stock_status'], ['instock', 'outofstock', 'onbackorder']);
        }
        if (isset($fields['stock_quantity'])) {
            if (! is_int($fields['stock_quantity']) || $fields['stock_quantity'] < 0) {
                throw new InvalidArgumentException('Invalid stock');
            } $fields['manage_stock'] = true;
        }
        $after = [...$before, ...$fields];
        if ($after['sale_price'] !== '' && (int) str_replace('.', '', $after['sale_price']) >= (int) str_replace('.', '', $after['regular_price'])) {
            throw new InvalidArgumentException('Sale must be lower than regular price');
        }
        if (($fields['sale_price'] ?? null) === '') {
            $after['sale_from'] = '';
            $after['sale_to'] = '';
        }
        $after['on_sale'] = $after['sale_price'] !== '';
        $this->state['products'][$id] = $after;

        return ['updated_id' => $id, 'changed' => $before !== $after, 'previous' => $before];
    }

    private function trash(string $bucket, int $id, bool $restore): array
    {
        $row = $this->row($bucket, $id);
        if ($restore) {
            if ($row['status'] !== 'trash') {
                throw new InvalidArgumentException('Item is not trashed');
            }
            $this->state[$bucket][$id]['status'] = $row['previous_status'] ?? 'draft';
            unset($this->state[$bucket][$id]['previous_status']);

            return ['restored_id' => $id, 'status' => $this->state[$bucket][$id]['status']];
        }
        if ($row['status'] === 'trash') {
            throw new InvalidArgumentException('Item already trashed');
        }
        $this->state[$bucket][$id]['previous_status'] = $row['status'];
        $this->state[$bucket][$id]['status'] = 'trash';

        return ['trashed_id' => $id, 'previous_status' => $row['status']];
    }

    private function statusChange(string $bucket, array $a, array $allowed): array
    {
        $key = $bucket === 'orders' ? 'order_id' : 'subscription_id';
        $id = $this->positive($a['internal_id'] ?? $a[$key] ?? null);
        $row = $this->row($bucket, $id);
        $status = $this->text($a, 'status');
        $this->enum($status, $allowed);
        if ($bucket === 'subscriptions' && in_array($row['status'], ['cancelled', 'expired'], true) && $status !== $row['status']) {
            throw new InvalidArgumentException('Terminal subscription cannot be resumed');
        }
        $changed = ($a['expected_status'] ?? $row['status']) === $row['status'] && $status !== $row['status'];
        if ($changed) {
            $this->state[$bucket][$id]['status'] = $status;
        }

        return ['changed' => $changed, $key => $id, 'number' => (string) $id, 'previous' => $row['status'], 'status' => $this->state[$bucket][$id]['status']];
    }

    private function orderNote(array $a): array
    {
        $id = $this->positive($a['internal_id'] ?? $a['order_id'] ?? null);
        $this->row('orders', $id);
        $note = ['id' => $this->next('notes'), 'note' => $this->text($a, 'note'), 'customer_note' => ($a['customer_note'] ?? false) === true];
        $this->state['orders'][$id]['notes'][] = $note;

        return ['note_id' => $note['id'], 'order_id' => $id, 'number' => (string) $id, 'customer_note' => $note['customer_note']];
    }

    private function salesReport(array $a): array
    {
        $days = min(365, max(1, (int) ($a['days'] ?? 30)));
        $to = isset($a['to']) ? CarbonImmutable::parse($a['to'], 'Asia/Jerusalem')->endOfDay() : CarbonImmutable::now('Asia/Jerusalem')->endOfDay();
        $from = isset($a['from']) ? CarbonImmutable::parse($a['from'], 'Asia/Jerusalem')->startOfDay() : $to->subDays($days - 1)->startOfDay();
        if ($from->greaterThan($to) || $from->diffInDays($to) > 366) {
            throw new InvalidArgumentException('Invalid report period');
        }
        $paid = [];
        $gross = 0;
        $waiting = 0;
        $top = [];
        foreach ($this->state['orders'] as $order) {
            $date = CarbonImmutable::parse($order['date'], 'Asia/Jerusalem');
            if ($date->lessThan($from) || $date->greaterThan($to)) {
                continue;
            }
            if (in_array($order['status'], ['pending', 'on-hold'], true)) {
                $waiting++;
            }
            if (! in_array($order['status'], ['processing', 'completed'], true)) {
                continue;
            }
            $paid[] = $order;
            $gross += (int) str_replace('.', '', $this->price($order['total']));
            foreach ($order['items'] as $item) {
                $name = $item['name'];
                $top[$name] ??= ['name' => $name, 'quantity' => 0, 'total_agorot' => 0];
                $top[$name]['quantity'] += $item['quantity'];
                $top[$name]['total_agorot'] += (int) str_replace('.', '', $this->price($item['total']));
            }
        }

        return ['days' => $days, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'currency' => 'ILS',
            'paid_orders' => count($paid), 'gross_sales' => $this->decimal($gross), 'refunded' => '0.00', 'net_sales' => $this->decimal($gross),
            'average_order' => $this->decimal($paid ? intdiv($gross, count($paid)) : 0), 'awaiting_payment_or_hold' => $waiting,
            'top_products' => array_values(array_map(fn (array $row): array => ['name' => $row['name'], 'quantity' => $row['quantity'], 'total' => $this->decimal($row['total_agorot'])], $top)),
            'previous_period' => ['paid_orders' => 0, 'gross_sales' => '0.00'], 'truncated' => false];
    }

    private function orderStats(array $a): array
    {
        $days = min(60, max(7, (int) ($a['days'] ?? 28)));
        $now = CarbonImmutable::now('Asia/Jerusalem');
        $daily = [];
        for ($i = $days; $i >= 0; $i--) {
            $daily[$now->subDays($i)->format('Y-m-d')] = ['orders' => 0, 'paid' => 0];
        }
        $last = ['orders' => 0, 'paid' => 0, 'by_status' => []];
        foreach ($this->state['orders'] as $order) {
            $date = CarbonImmutable::parse($order['date'], 'Asia/Jerusalem');
            if ($date->lessThan($now->subDays($days)) || $date->greaterThan($now)) {
                continue;
            }
            $day = $date->format('Y-m-d');
            $paid = in_array($order['status'], ['processing', 'completed'], true);
            if (isset($daily[$day])) {
                $daily[$day]['orders']++;
                $daily[$day]['paid'] += $paid ? 1 : 0;
            }
            if ($date->greaterThanOrEqualTo($now->subDay())) {
                $last['orders']++;
                $last['paid'] += $paid ? 1 : 0;
                $last['by_status'][$order['status']] = ($last['by_status'][$order['status']] ?? 0) + 1;
            }
        }

        return ['days' => $days, 'daily' => $daily, 'last_24h' => $last, 'currency' => 'ILS'];
    }

    private function decimal(int $agorot): string
    {
        return intdiv($agorot, 100).'.'.str_pad((string) ($agorot % 100), 2, '0', STR_PAD_LEFT);
    }

    private function couponCreate(array $a): array
    {
        $this->only($a, ['code', 'type', 'amount', 'expires', 'minimum_amount', 'usage_limit']);
        $code = mb_strtolower($this->text($a, 'code'));
        if (array_filter(array_keys($this->state['coupons']), fn (string $existing): bool => mb_strtolower($existing) === $code)) {
            throw new InvalidArgumentException('Coupon already exists');
        }
        $type = $a['type'] ?? 'percent';
        $this->enum($type, ['percent', 'fixed_cart', 'fixed_product']);
        $amount = $this->price($a['amount'] ?? '');
        if ($amount === '0.00' || ($type === 'percent' && (int) str_replace('.', '', $amount) > 10000)) {
            throw new InvalidArgumentException('Invalid discount');
        }
        $id = $this->next('coupons');
        $this->state['coupons'][$code] = ['id' => $id, 'code' => $code, 'type' => $type, 'amount' => $amount, 'expires' => $a['expires'] ?? null, 'minimum_amount' => isset($a['minimum_amount']) ? $this->price($a['minimum_amount']) : '0.00', 'usage_limit' => $a['usage_limit'] ?? 0, 'usage_count' => 0];

        return ['created_id' => $id, 'code' => $code];
    }

    private function couponExpire(array $a): array
    {
        $query = mb_strtolower($this->text($a, 'code'));
        $matches = array_values(array_filter(array_keys($this->state['coupons']), fn (string $code): bool => mb_strtolower($code) === $query));
        $code = $matches[0] ?? throw new InvalidArgumentException('Coupon not found');
        $row = $this->row('coupons', $code);
        $this->state['coupons'][$code]['expires'] = now()->format('Y-m-d');

        return ['coupon_id' => $row['id'], 'code' => $code, 'previous_expiry' => $row['expires']];
    }

    private function mediaList(array $a): array
    {
        $rows = $this->filter($this->state['media'], [...$a, 'limit' => 10000], ['title', 'alt']);
        if (isset($a['mime_type'])) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => str_starts_with($row['mime'], $a['mime_type'])));
        }
        $limit = min(50, max(1, (int) ($a['limit'] ?? 20)));
        $page = max(1, (int) ($a['page'] ?? 1));
        $items = array_slice($rows, ($page - 1) * $limit, $limit);
        $items = array_map(fn (array $row): array => array_intersect_key($row, array_flip([
            'id', 'title', 'url', 'mime', 'alt', 'date',
        ])), $items);

        return ['total' => count($rows), 'returned' => count($items), 'page' => $page, 'pages' => (int) ceil(count($rows) / $limit), 'items' => $items];
    }

    private function mediaGet(array $a): array
    {
        $r = $this->row('media', $this->id($a));

        return ['id' => $r['id'], 'label' => $r['title'], 'url' => $r['url'], 'mime' => $r['mime'], 'filename' => $r['filename'], 'filename_rename_supported' => false, 'editable_taxonomies' => [], 'values' => array_intersect_key($r, array_flip(['title', 'alt', 'caption', 'description', 'parent_id', 'terms']))];
    }

    private function mediaUpload(array $a): array
    {
        $this->only($a, ['filename', 'data', 'alt', 'title', 'attach_to']);
        $bytes = base64_decode($this->text($a, 'data'), true);
        $filename = $this->text($a, 'filename');
        if ($bytes === false || @getimagesizefromstring($bytes) === false || ! preg_match('/\.(png|jpe?g|webp)$/i', $filename)) {
            throw new InvalidArgumentException('Invalid simulated image');
        }
        $alt = $this->text($a, 'alt');
        $id = $this->next('media');
        $this->state['media'][$id] = $this->mediaRecord($id, ['filename' => basename($filename), 'title' => $a['title'] ?? pathinfo($filename, PATHINFO_FILENAME), 'alt' => $alt, 'parent_id' => $a['attach_to'] ?? 0]);

        return ['id' => $id, 'attachment_id' => $id, 'url' => $this->state['media'][$id]['url']];
    }

    private function mediaUpdate(array $a): array
    {
        $id = $this->id($a);
        $current = $this->mediaGet($a)['values'];
        $values = $this->patch($a, $current, array_keys($current));
        if (isset($values['parent_id']) && $values['parent_id'] !== 0) {
            $this->target($this->positive($values['parent_id']));
        }
        $before = array_intersect_key($current, $values);
        $this->state['media'][$id] = [...$this->state['media'][$id], ...$values];

        return ['id' => $id, 'changed' => $before !== $values, 'before' => $before, 'after' => $values];
    }

    private function thumbnail(array $a): array
    {
        $id = $this->id($a);
        $bucket = $this->target($id);
        $attachment = $a['attachment_id'] ?? null;
        if (! is_int($attachment) || $attachment < 0) {
            throw new InvalidArgumentException('Invalid attachment');
        }
        if ($attachment > 0) {
            $this->row('media', $attachment);
        }
        $before = $this->state[$bucket][$id]['thumbnail_id'];
        $changed = ! isset($a['if_current']) || $a['if_current'] === $before;
        if ($changed) {
            $this->state[$bucket][$id]['thumbnail_id'] = $attachment;
        }

        return ['id' => $id, 'attachment_id' => $this->state[$bucket][$id]['thumbnail_id'], 'previous' => ['attachment_id' => $before], 'changed' => $changed];
    }

    private function mediaDelete(array $a): array
    {
        $id = $this->id($a, 'attachment_id');
        $this->row('media', $id);
        foreach ([...$this->state['content'], ...$this->state['products']] as $row) {
            if (($row['thumbnail_id'] ?? 0) === $id) {
                throw new InvalidArgumentException('Media is in use');
            }
        }
        unset($this->state['media'][$id]);

        return ['deleted_id' => $id];
    }

    private function userList(array $a): array
    {
        $rows = $this->filter($this->state['users'], $a, ['display_name', 'email', 'login']);
        if (isset($a['role'])) {
            $rows = array_values(array_filter($rows, fn (array $r): bool => in_array($a['role'], $r['roles'], true)));
        }

        $rows = array_map(fn (array $row): array => [...$row, 'editable' => ! in_array('administrator', $row['roles'], true), 'status_meta_keys' => []], $rows);

        return ['total' => count($rows), 'count' => count($rows), 'returned' => count($rows), 'page' => 1, 'pages' => $rows === [] ? 0 : 1, 'assignable_roles' => ['subscriber', 'customer', 'contributor', 'author', 'editor', 'shop_manager'], 'users' => $rows];
    }

    private function userCreate(array $a): array
    {
        $this->only($a, ['email', 'login', 'username', 'display_name', 'first_name', 'last_name', 'role', 'notify']);
        $email = $this->text($a, 'email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }
        foreach ($this->state['users'] as $user) {
            if ($user['email'] === $email) {
                throw new InvalidArgumentException('Duplicate email');
            }
        }
        $role = $a['role'] ?? 'subscriber';
        $this->enum($role, ['subscriber', 'customer', 'contributor', 'author', 'editor', 'shop_manager']);
        $id = $this->next('users');
        $login = $a['login'] ?? $a['username'] ?? explode('@', $email)[0];
        $this->state['users'][$id] = ['id' => $id, 'email' => $email, 'login' => $login, 'display_name' => $a['display_name'] ?? $login, 'roles' => [$role], 'first_name' => $a['first_name'] ?? '', 'last_name' => $a['last_name'] ?? '', 'description' => '', 'registered' => now()->format('Y-m-d')];

        return ['created_id' => $id, 'login' => $login, 'email' => $email, 'role' => $role, 'notified' => ($a['notify'] ?? true) === true, 'password_returned' => false];
    }

    private function userRole(array $a): array
    {
        $id = $this->id($a, 'user_id');
        $row = $this->safeUser($id);
        $role = $this->text($a, 'role');
        $this->enum($role, ['subscriber', 'customer', 'contributor', 'author', 'editor', 'shop_manager']);
        $this->state['users'][$id]['roles'] = [$role];

        return ['user_id' => $id, 'changed' => $row['roles'] !== [$role], 'previous' => ['role' => $row['roles'][0]], 'role' => $role];
    }

    private function safeUser(int $id): array
    {
        $row = $this->row('users', $id);
        if (in_array('administrator', $row['roles'], true)) {
            throw new InvalidArgumentException('Protected administrator');
        }

        return $row;
    }

    private function userProfile(array $a): array
    {
        $r = $this->safeUser($this->id($a));

        return ['id' => $r['id'], 'label' => $r['display_name'], 'values' => array_intersect_key($r, array_flip(['display_name', 'first_name', 'last_name', 'description']))];
    }

    private function userProfileUpdate(array $a): array
    {
        $r = $this->userProfile($a);
        $values = $this->patch($a, $r['values'], array_keys($r['values']));
        $before = array_intersect_key($r['values'], $values);
        $this->state['users'][$r['id']] = [...$this->state['users'][$r['id']], ...$values];

        return ['id' => $r['id'], 'changed' => $before !== $values, 'before' => $before, 'after' => $values];
    }

    private function commentModerate(array $a): array
    {
        $id = $this->id($a, 'comment_id');
        $row = $this->row('comments', $id);
        $status = $this->text($a, 'status');
        $this->enum($status, ['hold', 'approve', 'spam', 'trash']);
        $this->state['comments'][$id]['status'] = $status;

        return ['comment_id' => $id, 'status' => $status, 'previous' => ['status' => $row['status']]];
    }

    private function taxonomies(array $a): array
    {
        $types = ['product_cat' => ['product'], 'category' => ['post'], 'post_tag' => ['post']];
        $rows = [];
        foreach ($types as $taxonomy => $postTypes) {
            if (isset($a['type']) && ! in_array($a['type'], $postTypes, true)) {
                continue;
            }
            $rows[] = ['taxonomy' => $taxonomy, 'label' => match ($taxonomy) {
                'product_cat' => 'קטגוריות מוצרים', 'category' => 'קטגוריות', default => 'תגיות'
            }, 'hierarchical' => $taxonomy !== 'post_tag', 'post_types' => $postTypes, 'terms' => count($this->state['terms'][$taxonomy])];
        }

        return ['type' => $a['type'] ?? 'all', 'taxonomies' => $rows];
    }

    private function shippingZones(): array
    {
        $zones = [];
        foreach ($this->state['shipping_zones'] as $zone) {
            $methods = [];
            foreach ($zone['methods'] as $index => $method) {
                $methods[] = ['instance_id' => $index + 1, 'method_id' => $method['id'], 'title' => $method['title'], 'enabled' => $method['enabled'], 'settings' => array_intersect_key($method, array_flip(['cost', 'min_amount']))];
            }
            $zones[] = ['id' => $zone['id'], 'name' => $zone['name'], 'regions' => $zone['locations'], 'methods' => $methods];
        }
        $zones[] = ['id' => 0, 'name' => 'שאר העולם', 'regions' => [], 'methods' => []];

        return ['zones' => $zones];
    }

    private function termList(array $a): array
    {
        $taxonomy = $this->text($a, 'taxonomy');
        $rows = $this->state['terms'][$taxonomy] ?? throw new InvalidArgumentException('Unknown taxonomy');

        return ['taxonomy' => $taxonomy, 'total' => count($rows), 'returned' => count($found = $this->filter($rows, $a, ['name', 'slug'])), 'terms' => $found];
    }

    private function termCreate(array $a): array
    {
        $taxonomy = $this->text($a, 'taxonomy');
        if (! isset($this->state['terms'][$taxonomy])) {
            throw new InvalidArgumentException('Unknown taxonomy');
        }
        $name = $this->text($a, 'name');
        foreach ($this->state['terms'][$taxonomy] as $term) {
            if ($term['name'] === $name) {
                throw new InvalidArgumentException('Duplicate term');
            }
        }
        $id = $this->next('terms');
        $this->state['terms'][$taxonomy][$id] = ['id' => $id, 'name' => $name, 'slug' => $a['slug'] ?? 'term-'.$id, 'description' => $a['description'] ?? '', 'parent' => $a['parent'] ?? 0, 'count' => 0];

        return ['created_id' => $id, 'term_id' => $id, 'taxonomy' => $taxonomy, 'name' => $name];
    }

    private function postTerms(array $a): array
    {
        $id = $this->id($a);
        $this->target($id);
        $taxonomy = $this->text($a, 'taxonomy');
        if (! isset($this->state['terms'][$taxonomy])) {
            throw new InvalidArgumentException('Unknown taxonomy');
        }
        $ids = $this->state['post_terms'][$id][$taxonomy] ?? [];

        return ['id' => $id, 'taxonomy' => $taxonomy, 'term_ids' => $ids, 'terms' => array_map(fn (int $term): string => $this->state['terms'][$taxonomy][$term]['name'], $ids)];
    }

    private function setTerms(array $a): array
    {
        $previous = $this->postTerms($a);
        $taxonomy = $previous['taxonomy'];
        $ids = $a['term_ids'] ?? [];
        foreach ($a['terms'] ?? [] as $name) {
            $match = array_values(array_filter($this->state['terms'][$taxonomy], fn (array $t): bool => $t['name'] === $name));
            if (count($match) !== 1) {
                throw new InvalidArgumentException('Term does not exist');
            } $ids[] = $match[0]['id'];
        }
        foreach ($ids as $id) {
            if (! isset($this->state['terms'][$taxonomy][$this->positive($id)])) {
                throw new InvalidArgumentException('Term does not exist');
            }
        }
        $mode = $a['mode'] ?? 'add';
        $this->enum($mode, ['add', 'replace']);
        $ids = array_values(array_unique($mode === 'replace' ? $ids : [...$previous['term_ids'], ...$ids]));
        sort($ids);
        $this->state['post_terms'][$previous['id']][$taxonomy] = $ids;

        return ['id' => $previous['id'], 'taxonomy' => $taxonomy, 'term_ids' => $ids, 'previous' => ['term_ids' => $previous['term_ids']]];
    }

    private function menuAdd(array $a): array
    {
        $menu = $a['menu'] ?? '';
        $matches = array_filter($this->state['menus'], fn (array $m): bool => (string) $m['menu_id'] === (string) $menu || $m['menu'] === $menu);
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('Menu not found');
        } $menuId = array_key_first($matches);
        $title = $this->text($a, 'title');
        $url = isset($a['page_id']) ? $this->row('content', $this->positive($a['page_id']))['url'] : $this->text($a, 'url');
        if (! preg_match('#^https?://#', $url)) {
            throw new InvalidArgumentException('Invalid URL');
        }
        $id = $this->next('menu_items');
        $this->state['menus'][$menuId]['items'][$id] = ['item_id' => $id, 'title' => $title, 'url' => $url, 'parent_id' => $a['parent_id'] ?? 0, 'order' => $a['position'] ?? count($matches[$menuId]['items']) + 1, 'page_id' => $a['page_id'] ?? 0];

        return ['added_item_id' => $id, 'menu_id' => $menuId];
    }

    private function menuOf(int $id): int
    {
        foreach ($this->state['menus'] as $menuId => $menu) {
            if (isset($menu['items'][$id])) {
                return $menuId;
            }
        }
        throw new InvalidArgumentException('Menu item not found');
    }

    private function menuUpdate(array $a): array
    {
        $id = $this->id($a, 'item_id');
        $menuId = $this->menuOf($id);
        $this->only($a, ['item_id', 'title', 'url', 'parent_id', 'position']);
        foreach (array_diff_key($a, ['item_id' => true]) as $key => $value) {
            $this->state['menus'][$menuId]['items'][$id][$key === 'position' ? 'order' : $key] = $value;
        }

        return ['updated_item_id' => $id];
    }

    private function menuUnlink(array $a): array
    {
        $id = $this->id($a, 'item_id');
        $menuId = $this->menuOf($id);
        $parent = $this->state['menus'][$menuId]['items'][$id]['parent_id'];
        unset($this->state['menus'][$menuId]['items'][$id]);
        foreach ($this->state['menus'][$menuId]['items'] as &$item) {
            if ($item['parent_id'] === $id) {
                $item['parent_id'] = $parent;
            }
        }

        return ['unlinked_item_id' => $id];
    }

    private function pluginChange(string $tool, array $a): array
    {
        $file = $this->text($a, 'plugin');
        $row = $this->row('plugins', $file);
        if ($tool === 'wp_plugin_deactivate' && in_array($file, ['woocommerce/woocommerce.php', 'wordpress-seo/wp-seo.php'], true)) {
            throw new InvalidArgumentException('Critical plugin cannot be disabled');
        }
        if ($tool === 'wp_plugin_update') {
            if (! $row['update_available']) {
                throw new InvalidArgumentException('No update available');
            }
            $this->state['plugins'][$file]['version'] = $row['new_version'];
            $this->state['plugins'][$file]['update_available'] = false;

            return ['plugin' => $file, 'updated' => true, 'version' => $row['new_version']];
        }
        $this->state['plugins'][$file]['active'] = $tool === 'wp_plugin_activate';

        return ['plugin' => $file, 'active' => $this->state['plugins'][$file]['active']];
    }

    private function themeSet(array $a): array
    {
        $current = ['stylesheet' => $this->state['active_theme']];
        $values = $this->patch($a, $current, ['stylesheet']);
        $this->row('themes', $values['stylesheet']);
        $this->state['active_theme'] = $values['stylesheet'];
        foreach ($this->state['themes'] as $slug => &$theme) {
            $theme['active'] = $slug === $values['stylesheet'];
        }

        return ['changed' => $current !== $values, 'before' => $current, 'after' => $values];
    }

    private function themeUpdate(array $a): array
    {
        $slug = $this->text($a, 'stylesheet');
        $row = $this->row('themes', $slug);
        if (! $row['update_available']) {
            throw new InvalidArgumentException('No theme update');
        }
        $this->state['themes'][$slug]['version'] = $row['new_version'];
        $this->state['themes'][$slug]['update_available'] = false;

        return ['stylesheet' => $slug, 'updated' => true];
    }

    private function flushCache(): array
    {
        $this->state['cache_flushes']++;

        return ['flushed' => true];
    }

    private function patch(array $a, array $current, array $allowed): array
    {
        $values = $a['values'] ?? null;
        $expected = $a['expected'] ?? null;
        if (! is_array($values) || ! $values || ! is_array($expected) || array_diff(array_keys($values), $allowed)) {
            throw new InvalidArgumentException('Unsupported patch fields');
        }
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $expected) || ! array_key_exists($key, $current) || $expected[$key] !== $current[$key]) {
                throw new InvalidArgumentException('State changed before confirmation');
            }
        }

        return $values;
    }

    private function filter(array $rows, array $a, array $fields): array
    {
        $search = mb_strtolower(trim((string) ($a['search'] ?? '')));
        $rows = array_filter($rows, function (array $row) use ($a, $fields, $search): bool {
            foreach (['type', 'status'] as $key) {
                if (isset($a[$key]) && ! in_array($a[$key], ['any', 'all'], true) && ($row[$key] ?? null) !== $a[$key]) {
                    return false;
                }
            }
            foreach (['id', 'post_id'] as $key) {
                if (isset($a[$key]) && ($row[$key] ?? null) !== $a[$key]) {
                    return false;
                }
            }
            if ($search === '') {
                return true;
            }
            foreach ($fields as $field) {
                if (str_contains(mb_strtolower((string) ($row[$field] ?? '')), $search)) {
                    return true;
                }
            }

            return false;
        });

        return array_slice(array_values($rows), 0, max(1, (int) ($a['limit'] ?? 50)));
    }

    private function row(string $bucket, int|string $id): array
    {
        return $this->state[$bucket][$id] ?? throw new InvalidArgumentException('Evaluation item not found: '.$bucket.' '.$id);
    }

    private function target(int $id): string
    {
        foreach (['content', 'products'] as $bucket) {
            if (isset($this->state[$bucket][$id]) && $this->state[$bucket][$id]['status'] !== 'trash') {
                return $bucket;
            }
        }
        throw new InvalidArgumentException('Attachment target not found');
    }

    private function id(array $a, string $key = 'id'): int
    {
        return $this->positive($a[$key] ?? null);
    }

    private function positive(mixed $id): int
    {
        if (! is_int($id) || $id < 1) {
            throw new InvalidArgumentException('Positive integer required');
        }

        return $id;
    }

    private function text(array $a, string $key): string
    {
        if (! is_string($a[$key] ?? null) || trim($a[$key]) === '') {
            throw new InvalidArgumentException('Missing '.$key);
        }

        return trim($a[$key]);
    }

    private function enum(mixed $value, array $allowed): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported value');
        }
    }

    private function only(array $a, array $allowed): void
    {
        if (array_diff(array_keys($a), $allowed)) {
            throw new InvalidArgumentException('Unsupported arguments');
        }
    }

    private function next(string $bucket): int
    {
        return $this->state['next_ids'][$bucket]++;
    }

    private function url(int $id): string
    {
        return 'https://evaluation.example/?p='.$id;
    }

    private function price(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! preg_match('/^(\\d{1,9})(?:\\.(\\d{1,2}))?$/D', (string) $value, $m)) {
            throw new InvalidArgumentException('Invalid decimal amount');
        }

        return ((string) ((int) $m[1])).'.'.str_pad($m[2] ?? '', 2, '0');
    }

    private function dates(string $value): array
    {
        if (! preg_match('/^\\d{4}-\\d{2}-\\d{2}[ T]\\d{2}:\\d{2}(?::\\d{2})?$/D', $value)) {
            throw new InvalidArgumentException('Invalid scheduled date');
        }
        $date = CarbonImmutable::parse($value, 'Asia/Jerusalem');

        return [$date->format('Y-m-d H:i:s'), $date->utc()->format('Y-m-d H:i:s')];
    }
}
