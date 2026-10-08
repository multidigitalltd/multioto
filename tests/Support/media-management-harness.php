<?php

// Isolated process: these WordPress stand-ins never leak into Laravel tests.
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
define('ABSPATH', '/tmp/');

class Multioto_Agent_Rpc_Error extends RuntimeException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
class WP_Post
{
    public $ID;

    public $post_type = 'attachment';

    public $post_status = 'inherit';

    public $post_title = 'Before';

    public $post_content = 'Description';

    public $post_excerpt = 'Caption';

    public $post_parent = 0;

    public $post_mime_type = 'image/jpeg';
}
class WP_Error
{
    public function get_error_message()
    {
        return 'Write failed';
    }
}

$GLOBALS['media_post'] = new WP_Post;
$GLOBALS['media_post']->ID = 11;
$GLOBALS['media_post']->post_mime_type = $input['mime'] ?? 'image/jpeg';
foreach (['title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content'] as $field => $column) {
    if (isset($input['initial'][$field])) {
        $GLOBALS['media_post']->{$column} = $input['initial'][$field];
    }
}
$GLOBALS['media_alt'] = $input['initial']['alt'] ?? 'Old alt';
$GLOBALS['media_terms'] = ['media_folder' => [7], 'media_tag' => [9]];
$GLOBALS['writes'] = [];
$GLOBALS['optimole_options'] = [
    'api_key' => 'must-never-leak-api-secret',
    'service_data' => ['cdn_secret' => 'must-never-leak-cdn-secret'],
    'unknown_future_setting' => ['keep' => true],
    'image_replacer' => 'enabled', 'autoquality' => 'enabled', 'quality' => 80,
    'lazyload' => 'disabled', 'lazyload_placeholder' => 'enabled',
    'retina_images' => 'disabled', 'resize_smart' => 'disabled', 'native_lazyload' => 'disabled',
];

if (! empty($input['optimole'])) {
    define('OPTML_NAMESPACE', 'optml');
    define('OPTML_VERSION', '4.2.16');
    if (! empty($input['env_locked'])) {
        define('OPTIML_USE_ENV', true);
        define('OPTIML_QUALITY', 85);
    }
    class Optml_Settings
    {
        public function get($key)
        {
            return $GLOBALS['optimole_options'][$key] ?? null;
        }

        public function is_connected()
        {
            return empty($GLOBALS['input']['disconnected']);
        }

        public function parse_settings($values)
        {
            throw new LogicException('Optimole parse_settings writes values; never call it as validation.');
        }

        public function update($key, $value)
        {
            if (($GLOBALS['input']['fail_setting'] ?? '') === $key) {
                return false;
            }
            $GLOBALS['optimole_options'][$key] = $value;
            $GLOBALS['writes'][] = ['setting' => $key, 'value' => $value];

            return true;
        }
    }
}

function get_post($id)
{
    if ($id === 11) {
        return $GLOBALS['media_post'];
    }
    if ($id === 22) {
        $parent = new WP_Post;
        $parent->ID = 22;
        $parent->post_type = 'page';
        $parent->post_status = 'publish';

        return $parent;
    }

    return null;
}
function get_post_type_object($type)
{
    return (object) ['show_ui' => $type !== 'revision'];
}
function get_object_taxonomies($type, $output)
{
    return [
        (object) ['name' => 'media_folder', 'label' => 'Folders', 'show_ui' => true, '_builtin' => false],
        (object) ['name' => 'media_tag', 'label' => 'Tags', 'show_ui' => true, '_builtin' => false],
        (object) ['name' => 'secret_taxonomy', 'label' => 'Internal', 'show_ui' => false, '_builtin' => false],
    ];
}
function wp_get_object_terms($id, $taxonomy, $args)
{
    return $GLOBALS['media_terms'][$taxonomy] ?? [];
}
function wp_set_object_terms($id, $terms, $taxonomy, $append)
{
    if (($GLOBALS['input']['fail_taxonomy'] ?? '') === $taxonomy) {
        return new WP_Error;
    }
    $GLOBALS['media_terms'][$taxonomy] = $terms;
    $GLOBALS['writes'][] = ['taxonomy' => $taxonomy, 'terms' => $terms];

    return $terms;
}
function term_exists($id, $taxonomy)
{
    return in_array($id, [7, 8, 9], true);
}
function wp_get_attachment_url($id)
{
    return 'https://example.test/uploads/original.jpg';
}
function get_attached_file($id)
{
    return '/private/path/uploads/original.jpg';
}
function wp_basename($path)
{
    return basename($path);
}
function get_post_meta($id, $key, $single)
{
    return $GLOBALS['media_alt'];
}
function update_post_meta($id, $key, $value)
{
    if (! empty($GLOBALS['input']['fail_alt'])) {
        return false;
    }
    $GLOBALS['media_alt'] = stripslashes($value);
    $GLOBALS['writes'][] = ['alt' => $value];

    return true;
}
function wp_update_post($args, $error)
{
    foreach ($args as $key => $value) {
        $GLOBALS['media_post']->{$key} = is_string($value) ? stripslashes($value) : $value;
    }
    $GLOBALS['writes'][] = ['post' => $args];

    return 11;
}
function sanitize_text_field($value)
{
    return trim(strip_tags($value));
}
function wp_kses_post($value)
{
    return preg_replace('#<script[^>]*>.*?</script>#is', '', $value);
}
function wp_slash($value)
{
    return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value);
}
function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-media-management.php';

$results = [];
foreach ($input['calls'] as $call) {
    try {
        if ($call['tool'] === 'restore_previous') {
            $previous = end($results);
            $args = ['id' => $previous['id'], 'values' => $previous['before'], 'expected' => $previous['after']];
            $results[] = Multioto_Agent_Media_Management::call($call['write_tool'], $args);
        } elseif ($call['tool'] === 'definitions') {
            $results[] = Multioto_Agent_Media_Management::definitions();
        } else {
            $results[] = Multioto_Agent_Media_Management::call($call['tool'], $call['args'] ?? []);
        }
    } catch (Multioto_Agent_Rpc_Error $e) {
        $results[] = ['error' => $e->getCode(), 'message' => $e->getMessage()];
    }
}
// Only the test-only harness observes raw options to assert preservation.
echo json_encode(['results' => $results, 'writes' => $GLOBALS['writes'], 'options' => $GLOBALS['optimole_options']], JSON_THROW_ON_ERROR);
