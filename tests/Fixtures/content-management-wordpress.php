<?php

// Minimal stateful WordPress adapter. Each scenario runs in its own process,
// so plugin constants and WordPress global functions cannot leak into Laravel.
define('ABSPATH', '/tmp/');
class WP_Post
{
    public $ID = 1;

    public $post_title = 'Original';

    public $post_type = 'page';

    public $post_status = 'draft';

    public $post_date = '2025-01-01 09:00:00';

    public $post_date_gmt = '2025-01-01 07:00:00';

    public $post_parent = 0;

    public $menu_order = 0;

    public $post_name = 'original';

    public $post_content = '<p>שלום חברים, בקרו בחנות שלנו.</p>';

    public $post_password = '';
}
$GLOBALS['posts'] = [1 => new WP_Post, 2 => new WP_Post, 3 => new WP_Post];
$GLOBALS['posts'][2]->ID = 2;
$GLOBALS['posts'][2]->post_status = 'publish';
$GLOBALS['posts'][3]->ID = 3;
$GLOBALS['posts'][3]->post_status = 'publish';
$GLOBALS['options'] = ['timezone_string' => 'Asia/Jerusalem', 'gmt_offset' => 2.0];
$GLOBALS['meta'] = [];
$GLOBALS['post_saves'] = 0;
function get_post($id)
{
    return isset($GLOBALS['posts'][$id]) ? clone $GLOBALS['posts'][$id] : null;
}
function get_post_types($args, $format)
{
    return ['post' => 'post', 'page' => 'page', 'product' => 'product', 'attachment' => 'attachment', 'project' => 'project', 'shop_subscription' => 'shop_subscription', 'shop_order_refund' => 'shop_order_refund'];
}
function get_post_type_object($type)
{
    return (object) ['hierarchical' => $type === 'page'];
}
function get_option($key, $default = false)
{
    return $GLOBALS['options'][$key] ?? $default;
}
function update_option($key, $value)
{
    if (($GLOBALS['fail_option'] ?? null) === $key) {
        unset($GLOBALS['fail_option']);
        if (! empty($GLOBALS['concurrent_option'])) {
            $GLOBALS['options']['blogname'] = 'Concurrent editor';
        }
        throw new RuntimeException('A settings hook failed');
    }
    $GLOBALS['options'][$key] = $value;

    return true;
}
function wp_timezone_string()
{
    return 'Asia/Jerusalem';
}
function wp_timezone()
{
    return new DateTimeZone(wp_timezone_string());
}
function sanitize_title($value)
{
    return strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $value), '-'));
}
function sanitize_text_field($value)
{
    return trim(strip_tags(str_replace(["\n", "\r", "\t"], ' ', $value)));
}
function wp_unique_post_slug($slug, $id, $status, $type, $parent)
{
    return $slug === 'taken' ? 'taken-2' : $slug;
}
function is_wp_error($value)
{
    return false;
}
function wp_slash($value)
{
    return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value);
}
function wp_update_post($values, $error = false)
{
    foreach ($values as $key => $value) {
        $GLOBALS['posts'][$values['ID']]->$key = is_string($value) ? stripslashes($value) : $value;
    }
    $GLOBALS['post_saves']++;
    $GLOBALS['seo_at_save'] = $GLOBALS['meta'];

    return $values['ID'];
}
function metadata_exists($type, $id, $key)
{
    return array_key_exists($key, $GLOBALS['meta'][$id] ?? []);
}
function get_post_meta($id, $key, $single = true)
{
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function update_post_meta($id, $key, $value)
{
    if (($GLOBALS['fail_meta'] ?? null) === $key) {
        unset($GLOBALS['fail_meta']);
        if (! empty($GLOBALS['concurrent_meta'])) {
            $GLOBALS['meta'][$id]['_yoast_wpseo_title'] = 'Concurrent editor';
        }
        throw new RuntimeException('A metadata hook failed');
    }
    $GLOBALS['meta'][$id][$key] = stripslashes($value);

    return true;
}
function delete_post_meta($id, $key)
{
    unset($GLOBALS['meta'][$id][$key]);

    return true;
}
function home_url($path)
{
    return 'https://example.test'.$path;
}
function get_permalink($id)
{
    return $GLOBALS['target_url'] ?? 'https://example.test/content/'.$id;
}
function wp_parse_url($url)
{
    return parse_url($url);
}
function wp_json_encode($value, $options = 0)
{
    return json_encode($value, $options);
}
function wp_html_split($content)
{
    return preg_split('/(<!--.*?-->|<[^>]+>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
}
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-mcp-server.php';
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-content-management.php';
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-cct.php';
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-media-management.php';
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-site-administration.php';
function call_tool($tool, $args = [])
{
    return Multioto_Agent_Content_Management::call($tool, $args);
}
function content_read()
{
    return call_tool('wp_content_details', ['id' => 1])['values'];
}
function failure($callback)
{
    try {
        $callback();

        return ['rejected' => false];
    } catch (Multioto_Agent_Rpc_Error $e) {
        return ['rejected' => true, 'message' => $e->getMessage(), 'saves' => $GLOBALS['post_saves']];
    }
}
$case = $argv[1];
$out = [];
switch ($case) {
    case 'woocommerce_entities':
        $server = new Multioto_Agent_Mcp_Server;
        $call = new ReflectionMethod($server, 'callTool');
        $call->setAccessible(true);
        foreach (['shop_subscription', 'shop_order_refund'] as $type) {
            $GLOBALS['posts'][1]->post_type = $type;
            foreach (['wp_content_details', 'wp_content_manage', 'wp_content_get', 'wp_content_update'] as $tool) {
                $out[$type][$tool] = failure(function () use ($call, $server, $tool) {
                    $call->invoke($server, $tool, ['id' => 1, 'values' => ['status' => 'draft'], 'expected' => ['status' => 'draft'], 'title' => 'Forbidden edit']);
                });
            }
        }
        break;
    case 'no_changes':
        define('WPSEO_VERSION', '1.0');
        $out[] = call_tool('wp_content_manage', ['id' => 1, 'values' => ['menu_order' => 0], 'expected' => content_read()]);
        $out[] = call_tool('wp_seo_update', ['id' => 1, 'provider' => 'yoast', 'values' => ['title' => null], 'expected' => ['title' => null]]);
        $out[] = call_tool('wp_internal_link_update', ['id' => 1, 'values' => ['content' => get_post(1)->post_content], 'expected' => ['content' => get_post(1)->post_content]]);
        $out[] = call_tool('wp_site_settings_update', ['values' => ['blogname' => '', 'blogdescription' => ''], 'expected' => call_tool('wp_site_settings_get')['values']]);
        $out = ['results' => $out, 'saves' => $GLOBALS['post_saves']];
        break;
    case 'seo_partial_failure': case 'seo_concurrent_failure':
        define('WPSEO_VERSION', '1.0');
        $GLOBALS['fail_meta'] = '_yoast_wpseo_metadesc';
        $GLOBALS['concurrent_meta'] = $case === 'seo_concurrent_failure';
        $out = failure(function () {
            call_tool('wp_seo_update', ['id' => 1, 'provider' => 'yoast', 'values' => ['title' => 'Attempted title', 'description' => 'Attempted description'], 'expected' => ['title' => null, 'description' => null]]);
        });
        $out['current'] = call_tool('wp_seo_get', ['id' => 1])['values'];
        break;
    case 'settings_partial_failure': case 'settings_concurrent_failure':
        $GLOBALS['fail_option'] = 'blogdescription';
        $GLOBALS['concurrent_option'] = $case === 'settings_concurrent_failure';
        $out = failure(function () {
            call_tool('wp_site_settings_update', ['values' => ['blogname' => 'Attempted title', 'blogdescription' => 'Attempted description'], 'expected' => call_tool('wp_site_settings_get')['values']]);
        });
        $out['current'] = call_tool('wp_site_settings_get')['values'];
        break;
    case 'schedule':
        $before = content_read();
        $write = call_tool('wp_content_manage', ['id' => 1, 'values' => ['status' => 'future', 'date' => '2099-07-01 12:30:00'], 'expected' => $before]);
        $restored = call_tool('wp_content_manage', ['id' => 1, 'values' => $write['before'], 'expected' => $write['after']]);
        $out = ['change' => $write, 'restored' => content_read(), 'before' => $before];
        break;
    case 'stale_schedule':
        $before = content_read();
        $GLOBALS['posts'][1]->post_date_gmt = '2026-01-01 07:00:00';
        $out = failure(function () use ($before) {
            call_tool('wp_content_manage', ['id' => 1, 'values' => ['status' => 'future', 'date' => '2099-07-01 12:30:00'], 'expected' => $before]);
        });
        break;
    case 'bad_dates':
        foreach (['2099-02-30 10:00:00', '2020-01-01 00:00:00', '2099-01-01T10:00:00Z'] as $date) {
            $out[] = failure(function () use ($date) {
                call_tool('wp_content_manage', ['id' => 1, 'values' => ['status' => 'future', 'date' => $date], 'expected' => content_read()]);
            });
        }
        break;
    case 'organization':
        $GLOBALS['posts'][1]->post_content = 'Do not modify me';
        $before = content_read();
        $write = call_tool('wp_content_manage', ['id' => 1, 'values' => ['parent' => 2, 'menu_order' => 7, 'slug' => 'new-path'], 'expected' => $before]);
        call_tool('wp_content_manage', ['id' => 1, 'values' => $write['before'], 'expected' => $write['after']]);
        $out = ['change' => $write, 'content' => get_post(1)->post_content, 'restored' => content_read(), 'before' => $before];
        break;
    case 'parent_cycle':
        $GLOBALS['posts'][2]->post_parent = 1;
        $out = failure(function () {
            call_tool('wp_content_manage', ['id' => 1, 'values' => ['parent' => 2], 'expected' => content_read()]);
        });
        break;
    case 'bad_content_changes':
        foreach ([['menu_order' => null], ['parent' => null], ['slug' => 'taken'], ['status' => 'trash'], ['post_content' => 'bad'], ['date' => '2099-07-01 12:30:00', 'date_gmt' => null]] as $values) {
            $out[] = failure(function () use ($values) {
                call_tool('wp_content_manage', ['id' => 1, 'values' => $values, 'expected' => content_read()]);
            });
        }
        break;
    case 'no_provider':
        $out = failure(function () {
            call_tool('wp_seo_update', ['id' => 1, 'provider' => 'yoast', 'values' => ['title' => 'New'], 'expected' => ['title' => null]]);
        });
        break;
    case 'yoast': case 'rank_math':
        define($case === 'yoast' ? 'WPSEO_VERSION' : 'RANK_MATH_VERSION', '1.0');
        $before = call_tool('wp_seo_get', ['id' => 1]);
        $change = call_tool('wp_seo_update', ['id' => 1, 'provider' => $before['provider'], 'values' => ['title' => 'Title \\ original', 'description' => 'תיאור לחיפוש'], 'expected' => $before['values']]);
        $metadata = $GLOBALS['meta'][1];
        $atSave = $GLOBALS['seo_at_save'][1];
        call_tool('wp_seo_update', ['id' => 1, 'provider' => $before['provider'], 'values' => $change['before'], 'expected' => $change['after']]);
        $out = ['change' => $change, 'metadata' => $metadata, 'at_save' => $atSave, 'restored' => call_tool('wp_seo_get', ['id' => 1])['values'], 'meta' => $GLOBALS['meta'][1]];
        break;
    case 'seo_stale':
        define('WPSEO_VERSION', '1.0');
        $GLOBALS['meta'][1]['_yoast_wpseo_title'] = 'Someone edited';
        $out = failure(function () {
            call_tool('wp_seo_update', ['id' => 1, 'provider' => 'yoast', 'values' => ['title' => 'Overwrite'], 'expected' => ['title' => null]]);
        });
        break;
    case 'link':
        $GLOBALS['posts'][1]->post_content = "<!-- wp:paragraph --><p class='keep-original'>שלום חברים, בקרו בחנות שלנו.</p><!-- /wp:paragraph -->";
        $before = call_tool('wp_internal_links_get', ['id' => 1]);
        $change = call_tool('wp_internal_link_update', ['id' => 1, 'values' => ['text' => 'בחנות שלנו', 'target_id' => 2], 'expected' => $before['values']]);
        $links = call_tool('wp_internal_links_get', ['id' => 1])['links'];
        call_tool('wp_internal_link_update', ['id' => 1, 'values' => $change['before'], 'expected' => $change['after']]);
        $out = ['change' => $change, 'links' => $links, 'restored' => get_post(1)->post_content, 'before' => $before['values']['content']];
        break;
    case 'bad_links':
        foreach ([['<a href="/original">hello</a>', 'hello'], ['<script>hello</script>', 'hello'], ['<p>hello hello</p>', 'hello'], ['<p>&amp;</p>', '&'], ['[button title="hello"]', 'hello'], ['<code>hello</code>', 'hello']] as $row) {
            $GLOBALS['posts'][1]->post_content = $row[0];
            $out[] = failure(function () use ($row) {
                call_tool('wp_internal_link_update', ['id' => 1, 'values' => ['text' => $row[1], 'target_id' => 2], 'expected' => ['content' => $row[0]]]);
            });
        }
        break;
    case 'external_link': case 'private_link': case 'elementor': case 'stale_link':
        $before = ['content' => get_post(1)->post_content];
        if ($case === 'external_link') {
            $GLOBALS['target_url'] = 'https://evil.test/foo';
        }
        if ($case === 'private_link') {
            $GLOBALS['posts'][2]->post_status = 'private';
        }
        if ($case === 'elementor') {
            $GLOBALS['meta'][1]['_elementor_data'] = '[{"id":"content"}]';
        }
        if ($case === 'stale_link') {
            $GLOBALS['posts'][1]->post_content .= ' Changed';
        }
        $out = failure(function () use ($before) {
            call_tool('wp_internal_link_update', ['id' => 1, 'values' => ['text' => 'בחנות שלנו', 'target_id' => 2], 'expected' => $before]);
        });
        break;
    case 'settings':
        $before = call_tool('wp_site_settings_get')['values'];
        $change = call_tool('wp_site_settings_update', ['values' => ['blogname' => 'האתר שלי', 'show_on_front' => 'page', 'page_on_front' => 2, 'page_for_posts' => 3], 'expected' => $before]);
        call_tool('wp_site_settings_update', ['values' => $change['before'], 'expected' => $change['after']]);
        $out = ['change' => $change, 'restored' => call_tool('wp_site_settings_get')['values'], 'before' => $before];
        break;
    case 'unsafe_settings':
        foreach ([['admin_email' => 'hijack@test'], ['home' => 'https://evil.test'], ['users_can_register' => 1], ['default_role' => 'administrator'], ['permalink_structure' => '/new'], ['timezone_string' => 'Unknown/Bad'], ['posts_per_page' => -1], ['show_on_front' => 'page', 'page_on_front' => 2, 'page_for_posts' => 2], ['show_on_front' => 'page'], ['gmt_offset' => 1]] as $values) {
            $out[] = failure(function () use ($values) {
                call_tool('wp_site_settings_update', ['values' => $values, 'expected' => call_tool('wp_site_settings_get')['values']]);
            });
        }
        break;
    case 'settings_stale':
        $before = call_tool('wp_site_settings_get')['values'];
        $GLOBALS['options']['blogname'] = 'Someone else';
        $out = failure(function () use ($before) {
            call_tool('wp_site_settings_update', ['values' => ['blogname' => 'Overwrite'], 'expected' => $before]);
        });
        break;
    case 'gmt_json_round_trip':
        $GLOBALS['options']['timezone_string'] = '';
        $before = json_decode(json_encode(call_tool('wp_site_settings_get')['values']), true);
        $out = call_tool('wp_site_settings_update', ['values' => ['gmt_offset' => 5.5], 'expected' => $before]);
        break;
    default: throw new RuntimeException('Unknown scenario');
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
