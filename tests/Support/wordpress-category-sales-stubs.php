<?php

define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
class Multioto_Agent_Rpc_Error extends RuntimeException
{
    public function __construct($code, $message)
    {
        parent::__construct($message, $code);
    }
}
class CategorySaleProduct
{
    public $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get_id()
    {
        return $this->data['id'];
    }

    public function get_parent_id()
    {
        return $this->data['parent_id'];
    }

    public function get_name()
    {
        return $this->data['name'];
    }

    public function get_status()
    {
        return $this->data['status'];
    }

    public function get_type()
    {
        return $this->data['type'];
    }

    public function get_children()
    {
        return $this->data['children'] ?? [];
    }

    public function get_regular_price($context = 'view')
    {
        return $this->data['regular_price'];
    }

    public function get_sale_price($context = 'view')
    {
        return $this->data['sale_price'];
    }

    public function get_date_on_sale_from($context = 'view')
    {
        return $this->data['sale_from'] === null ? null : (new DateTimeImmutable)->setTimestamp($this->data['sale_from']);
    }

    public function get_date_on_sale_to($context = 'view')
    {
        return $this->data['sale_to'] === null ? null : (new DateTimeImmutable)->setTimestamp($this->data['sale_to']);
    }

    public function set_sale_price($value)
    {
        $this->data['sale_price'] = $value;
    }

    public function set_date_on_sale_from($value)
    {
        $this->data['sale_from'] = $value;
    }

    public function set_date_on_sale_to($value)
    {
        $this->data['sale_to'] = $value;
    }

    public function set_price($value)
    {
        $this->data['price'] = $value;
    }

    public function save()
    {
        $GLOBALS['cs_saves'][] = $this->get_id();
        if (($GLOBALS['cs_fail_product'] ?? null) === $this->get_id()) {
            $GLOBALS['cs_fail_product'] = null;

            return 0;
        }
        $GLOBALS['cs_products'][$this->get_id()] = $this->data;

        return $this->get_id();
    }
}
class WC_Product_Variable
{
    public static function sync($id)
    {
        $GLOBALS['cs_synced'][] = $id;
    }
}
class Multioto_Agent_Sale_Schedule
{
    public static function conflicts($ids)
    {
        return array_values(array_intersect($ids, $GLOBALS['cs_owned'] ?? []));
    }

    public static function register($campaign)
    {
        $GLOBALS['cs_register_args'] = $campaign;
        if (isset($GLOBALS['cs_register_mutation'])) {
            call_user_func($GLOBALS['cs_register_mutation']);
        }
        if ($GLOBALS['cs_register_fail'] ?? false) {
            throw new RuntimeException('Schedule unavailable');
        }
        $campaign['status'] = 'scheduled';
        foreach ($campaign['products'] as &$row) {
            $row['phase'] = 'pending';
            $row['current_expected'] = $row['after'];
        }
        $GLOBALS['cs_campaigns'][$campaign['id']] = $campaign;
        $GLOBALS['cs_owned'] = array_merge($GLOBALS['cs_owned'], $campaign['product_ids']);
    }

    public static function cancel($id, $restore = false)
    {
        if (isset($GLOBALS['cs_campaigns'][$id])) {
            $GLOBALS['cs_campaigns'][$id]['status'] = 'cancelled';
        }
        $GLOBALS['cs_owned'] = [];
        $GLOBALS['cs_cancelled'][] = $id;
    }

    public static function state($id)
    {
        return $GLOBALS['cs_campaigns'][$id] ?? null;
    }
}
class CategorySaleDatabase
{
    public $options = 'wp_options';

    public function delete($table, $where)
    {
        if (isset($GLOBALS['cs_options'][$where['option_name']]) && serialize($GLOBALS['cs_options'][$where['option_name']]) === $where['option_value']) {
            unset($GLOBALS['cs_options'][$where['option_name']]);

            return 1;
        }

        return 0;
    }
}
$GLOBALS['wpdb'] = new CategorySaleDatabase;
function wc_get_product($id)
{
    $GLOBALS['cs_get_counts'][$id] = ($GLOBALS['cs_get_counts'][$id] ?? 0) + 1;
    if (isset($GLOBALS['cs_before_get'])) {
        call_user_func($GLOBALS['cs_before_get'], $id, $GLOBALS['cs_get_counts'][$id]);
    }

    return isset($GLOBALS['cs_products'][$id]) ? new CategorySaleProduct($GLOBALS['cs_products'][$id]) : false;
}
function get_term($id, $taxonomy)
{
    return $id === 10 ? (object) ['term_id' => 10, 'name' => 'Shoes', 'taxonomy' => 'product_cat'] : null;
}
function is_wp_error($value)
{
    return false;
}
function get_posts($args)
{
    $GLOBALS['cs_query'] = $args;

    return $args['tax_query'][0]['include_children'] ? $GLOBALS['cs_members'] : ($GLOBALS['cs_direct'] ?? $GLOBALS['cs_members']);
}
function get_woocommerce_currency()
{
    return $GLOBALS['cs_currency'] ?? 'ILS';
}
function wc_get_price_decimals()
{
    return $GLOBALS['cs_decimals'] ?? 2;
}
function wp_timezone()
{
    return new DateTimeZone($GLOBALS['cs_timezone'] ?? 'Asia/Jerusalem');
}
function wp_date($format, $timestamp, $timezone = null)
{
    return (new DateTimeImmutable)->setTimestamp($timestamp)->setTimezone($timezone ?? wp_timezone())->format($format);
}
function wc_delete_product_transients($id)
{
    $GLOBALS['cs_cache_cleared'][] = $id;
}
function wc_get_formatted_variation($product, $flat, $names, $skip)
{
    return $product->data['attributes'] ?? '';
}
function get_option($key, $default = false)
{
    return $GLOBALS['cs_options'][$key] ?? $default;
}
function add_option($key, $value, $deprecated = '', $autoload = false)
{
    if (isset($GLOBALS['cs_options'][$key])) {
        return false;
    }$GLOBALS['cs_options'][$key] = $value;

    return true;
}
function maybe_serialize($value)
{
    return serialize($value);
}
function wp_cache_delete($key, $group) {}
function wp_salt($scheme)
{
    return 'category-sale-test-secret';
}
function home_url($path = '/')
{
    return 'https://shop.example.test'.$path;
}
function get_current_blog_id()
{
    return 1;
}
require_once __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-category-sales.php';
