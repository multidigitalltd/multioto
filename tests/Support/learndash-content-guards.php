<?php

define('ABSPATH', __DIR__);
$GLOBALS['ld_guard'] = json_decode(stream_get_contents(STDIN), true);
$GLOBALS['ld_writes'] = [];
$GLOBALS['ld_data_reads'] = [];
$GLOBALS['ld_types'] = ['post', 'page', 'project', 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question', 'sfwd-certificates', 'sfwd-assignment', 'sfwd-essays', 'sfwd-transactions'];
class WP_Post
{
    public $ID = 11;

    public $post_type = 'sfwd-lessons';

    public $post_title = 'Lesson';

    public $post_content = 'Old content';

    public $post_excerpt = '';

    public $post_status = 'draft';

    public $post_date = '2026-10-08 12:00:00';

    public $post_date_gmt = '2026-10-08 09:00:00';

    public $post_parent = 0;

    public $menu_order = 0;

    public $post_name = 'lesson';
}
$GLOBALS['ld_post'] = new WP_Post;
$GLOBALS['ld_post']->post_type = $GLOBALS['ld_guard']['post_type'] ?? 'sfwd-lessons';
function post_type_exists($type)
{
    return ! empty($GLOBALS['ld_guard']['active']) && in_array($type, $GLOBALS['ld_types'], true);
}
function get_post($id)
{
    return $id === 11 ? clone $GLOBALS['ld_post'] : null;
}
function get_post_types($args, $format)
{
    return $GLOBALS['ld_types'];
}
function get_post_type_object($type)
{
    return (object) ['hierarchical' => true];
}
function get_post_meta($id, $key = '', $single = false)
{
    $GLOBALS['ld_data_reads'][] = ['meta', $id, $key];
    $meta = $GLOBALS['ld_guard']['meta'] ?? [];

    return $key === '' ? array_map(static function ($value) {
        return [$value];
    }, $meta) : ($meta[$key] ?? '');
}
function update_post_meta($id, $key, $value)
{
    $GLOBALS['ld_writes'][] = [$key, $value];

    return true;
}
function maybe_unserialize($value)
{
    return $value;
}
function get_field($key, $id, $formatted = true)
{
    $GLOBALS['ld_data_reads'][] = ['acf_value', $id, $key];

    return $GLOBALS['ld_guard']['values'][$key] ?? false;
}
function acf_get_field_groups($screen)
{
    $GLOBALS['ld_data_reads'][] = ['acf_groups', $screen];

    return [['key' => 'group_course', 'title' => 'Course', 'active' => true]];
}
function acf_get_fields($group)
{
    $GLOBALS['ld_data_reads'][] = ['acf_fields', $group];

    return $GLOBALS['ld_guard']['fields'] ?? [];
}
if (! empty($GLOBALS['ld_guard']['acf'])) {
    function get_fields($id)
    {
        return [];
    }
    function update_field($key, $value, $id)
    {
        $GLOBALS['ld_writes'][] = ['acf', $key, $value];
    }
}
function sanitize_text_field($value)
{
    return trim(strip_tags($value));
}
function wp_kses_post($value)
{
    return strip_tags($value, '<p><strong>');
}
function wp_json_encode($value, $options = 0)
{
    return json_encode($value, $options);
}
function get_permalink($id)
{
    return 'https://site.test/?p='.$id;
}
function is_wp_error($value)
{
    return false;
}
function wp_insert_post($value, $error = false)
{
    $GLOBALS['ld_writes'][] = $value;

    return 50;
}
function wp_update_post($value, $error = false)
{
    $GLOBALS['ld_writes'][] = $value;
    foreach ($value as $key => $item) {
        if ($key !== 'ID') {
            $GLOBALS['ld_post']->$key = $item;
        }
    }

    return 11;
}
function wp_trash_post($id)
{
    $GLOBALS['ld_writes'][] = ['trash', $id];
    $GLOBALS['ld_post']->post_status = 'trash';

    return clone $GLOBALS['ld_post'];
}
function wp_slash($value)
{
    return $value;
}
function wp_timezone_string()
{
    return 'Asia/Jerusalem';
}
function wp_timezone()
{
    return new DateTimeZone('Asia/Jerusalem');
}
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-acf-schema.php';
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-fields.php';
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-content-management.php';
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-mcp-server.php';
try {
    $args = $GLOBALS['ld_guard']['args'] ?? [];
    switch ($GLOBALS['ld_guard']['action']) {
        case 'read': $result = Multioto_Agent_Fields::values(11);
            break;
        case 'update': $result = Multioto_Agent_Fields::update(11, $args);
            break;
        case 'schema': $result = Multioto_Agent_Fields::schema($GLOBALS['ld_post']->post_type);
            break;
        case 'acf': $result = Multioto_Agent_Acf_Schema::snapshot(['context' => 'post', 'id' => 11]);
            break;
        case 'create':
        case 'content_update':
        case 'content_trash':
        case 'content_get':
        case 'editable_types':
            $methods = ['create' => 'contentCreate', 'content_update' => 'contentUpdate', 'content_trash' => 'contentTrash', 'content_get' => 'contentGet', 'editable_types' => 'editableTypes'];
            $method = new ReflectionMethod(Multioto_Agent_Mcp_Server::class, $methods[$GLOBALS['ld_guard']['action']]);
            $server = (new ReflectionClass(Multioto_Agent_Mcp_Server::class))->newInstanceWithoutConstructor();
            $raw = $method->invoke($server, $args);
            $result = is_string($raw) ? json_decode($raw, true) : $raw;
            break;
        case 'details':
            $result = Multioto_Agent_Content_Management::call('wp_content_details', ['id' => 11]);
            break;
        case 'manage':
            // Invoke the mutation directly: a denied read must not hide an
            // independently accessible write with a known prior snapshot.
            $post = $GLOBALS['ld_post'];
            $expected = ['status' => $post->post_status, 'date' => $post->post_date, 'date_gmt' => $post->post_date_gmt, 'parent' => $post->post_parent, 'menu_order' => $post->menu_order, 'slug' => $post->post_name];
            $result = Multioto_Agent_Content_Management::call('wp_content_manage', ['id' => 11, 'values' => $args, 'expected' => $expected]);
            break;
        default: throw new RuntimeException('Unknown scenario');
    }
    $output = ['result' => $result];
} catch (Throwable $error) {
    $output = ['error' => $error->getCode(), 'message' => $error->getMessage()];
}
echo json_encode($output + ['writes' => $GLOBALS['ld_writes'], 'data_reads' => $GLOBALS['ld_data_reads']], JSON_THROW_ON_ERROR);
