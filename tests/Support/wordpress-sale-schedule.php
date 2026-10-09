<?php

define('ABSPATH', __DIR__);
class Multioto_Agent_Rpc_Error extends RuntimeException
{
    public function __construct($code, $message)
    {
        parent::__construct($message, $code);
    }
}
class WooCommerce {}
class WC_DateTime extends DateTime
{
    public function date($format)
    {
        return $this->format($format);
    }
}
class WC_Product
{
    public $values;

    public $changes = [];

    public function __construct(int $id)
    {
        $this->values = ['id' => $id, 'regular_price' => '100', 'sale_price' => '', 'price' => '100', 'sale_from' => null, 'sale_to' => null, 'parent_id' => 0, 'name' => 'Product', 'sku' => '', 'status' => 'publish', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'short_description' => '', 'image_id' => 0, 'type' => 'simple', 'virtual' => false, 'downloadable' => false, 'children' => []];
    }

    public function __call($name, $args)
    {
        $key = substr($name, 4);
        $key = ['date_on_sale_from' => 'sale_from', 'date_on_sale_to' => 'sale_to'][$key] ?? $key;
        if (strpos($name, 'get_') === 0) {
            $value = $this->values[$key];

            return in_array($key, ['sale_from', 'sale_to'], true) && $value !== null ? new WC_DateTime('@'.$value) : $value;
        }
        if (strpos($name, 'set_') === 0) {
            $value = $args[0];
            if (in_array($key, ['sale_from', 'sale_to'], true) && $value instanceof DateTimeInterface) {
                $value = $value->getTimestamp();
            }
            $this->values[$key] = $value;
            $this->changes[$key] = $value;

            return;
        }
        throw new RuntimeException('Unknown method '.$name);
    }

    public function get_id()
    {
        return $this->values['id'];
    }

    public function get_changes()
    {
        return $this->changes;
    }

    public function is_on_sale($context = 'view')
    {
        return $this->values['sale_price'] !== '' && (float) $this->values['sale_price'] < (float) $this->values['regular_price'] && (! $this->values['sale_from'] || $this->values['sale_from'] <= time()) && (! $this->values['sale_to'] || $this->values['sale_to'] >= time());
    }

    public function save()
    {
        $GLOBALS['sale_saves'][] = $this->get_id();
        if (! empty($GLOBALS['sale_save_false'])) {
            return 0;
        }
        if (! empty($GLOBALS['sale_save_false_once'])) {
            unset($GLOBALS['sale_save_false_once']);

            return 0;
        }
        if (! empty($GLOBALS['sale_save_fail'])) {
            throw new RuntimeException('save failed');
        }
        $this->changes = [];
        $GLOBALS['sale_products'][$this->get_id()] = clone $this;

        return $this->get_id();
    }
}
function wc_get_product($id)
{
    return isset($GLOBALS['sale_products'][$id]) ? clone $GLOBALS['sale_products'][$id] : null;
}
function get_option($key, $default = false)
{
    return $GLOBALS['sale_options'][$key] ?? $default;
}
function add_option($key, $value, $deprecated = '', $autoload = true)
{
    if (array_key_exists($key, $GLOBALS['sale_options'])) {
        return false;
    }
    $GLOBALS['sale_options'][$key] = $value;

    return true;
}
function update_option($key, $value, $autoload = true)
{
    $GLOBALS['sale_options'][$key] = $value;

    return true;
}
function delete_option($key)
{
    unset($GLOBALS['sale_options'][$key]);
}
function get_post_meta($id, $key, $single = false)
{
    return $GLOBALS['sale_meta'][$id][$key] ?? '';
}
function update_post_meta($id, $key, $value)
{
    $GLOBALS['sale_meta'][$id][$key] = $value;

    return true;
}
function delete_post_meta($id, $key, $value = '')
{
    if ($value === '' || get_post_meta($id, $key, true) === $value) {
        unset($GLOBALS['sale_meta'][$id][$key]);
    }
}
function wp_timezone()
{
    return new DateTimeZone($GLOBALS['sale_timezone'] ?? 'Asia/Jerusalem');
}
function wc_format_decimal($value)
{
    return (string) $value;
}
function sanitize_text_field($value)
{
    return trim(strip_tags($value));
}
function wp_kses_post($value)
{
    return strip_tags($value, '<p><b><a>');
}
function get_permalink($id)
{
    return 'https://shop.test/?p='.$id;
}
function wc_delete_product_transients($id)
{
    $GLOBALS['sale_invalidated'][] = $id;
}
function add_action($hook, $callback, $priority = 10, $count = 1)
{
    $GLOBALS['sale_hooks'][$hook][] = $callback;
}
function add_filter($hook, $callback, $priority = 10, $count = 1)
{
    $GLOBALS['sale_hooks'][$hook][] = $callback;
}
if (! defined('SALE_NO_AS')) {
    function as_schedule_single_action($at, $hook, $args, $group, $unique = false)
    {
        $GLOBALS['sale_events'][] = [$at, $hook, $args, $group];
        if (isset($GLOBALS['sale_schedule_callback'])) {
            $callback = $GLOBALS['sale_schedule_callback'];
            unset($GLOBALS['sale_schedule_callback']);
            $callback();
        }

        return ! empty($GLOBALS['sale_schedule_fail']) ? 0 : count($GLOBALS['sale_events']);
    }
    function as_unschedule_all_actions($hook, $args, $group)
    {
        $GLOBALS['sale_unscheduled'][] = [$hook, $args];
    }
}
function wp_schedule_single_event($at, $hook, $args, $error = false)
{
    $GLOBALS['sale_events'][] = [$at, $hook, $args, 'wp-cron'];

    return empty($GLOBALS['sale_schedule_fail']);
}
function wp_clear_scheduled_hook($hook, $args)
{
    $GLOBALS['sale_unscheduled'][] = [$hook, $args];
}
function is_wp_error($value)
{
    return false;
}
function get_posts($args)
{
    return [11];
}
function get_term($id, $taxonomy = '')
{
    return $id === 77 ? (object) ['term_id' => 77, 'taxonomy' => 'product_cat', 'name' => 'Shirts'] : null;
}
function get_woocommerce_currency()
{
    return 'ILS';
}
function wc_get_price_decimals()
{
    return 2;
}
function wp_salt($scheme)
{
    return 'test-only-category-sale-secret';
}
function home_url($path = '/')
{
    return 'https://shop.test'.$path;
}
function wp_date($format, $timestamp, $timezone)
{
    return (new DateTimeImmutable('@'.$timestamp))->setTimezone($timezone)->format($format);
}
function maybe_serialize($value)
{
    return is_array($value) ? serialize($value) : $value;
}
function wp_cache_delete($key, $group) {}
class SaleScheduleFixtureDatabase
{
    public $options = 'wp_options';

    public function delete($table, $where, $formats = [])
    {
        $key = $where['option_name'];
        if (maybe_serialize(get_option($key, null)) !== $where['option_value']) {
            return 0;
        }
        delete_option($key);

        return 1;
    }
}
$GLOBALS['wpdb'] = new SaleScheduleFixtureDatabase;
if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-sale-schedule.php';
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-woo-writer.php';
