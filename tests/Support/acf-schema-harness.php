<?php

// Isolated WordPress/ACF contract fixture; no writes or live site connections.
define('ABSPATH', '/fixture/');
$config = json_decode(stream_get_contents(STDIN), true);
$GLOBALS['config'] = $config;
$GLOBALS['screens'] = [];
$GLOBALS['reads'] = [];
class Multioto_Agent_Rpc_Error extends RuntimeException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
class WP_Post
{
    public $ID = 11;

    public $post_type = 'page';

    public $post_title = 'Example';

    public $post_status = 'publish';
}
class AcfFixtureUser
{
    public $display_name = 'Customer';

    public function has_cap($capability): bool
    {
        return ! empty($GLOBALS['config']['admin']);
    }
}
function get_post($id)
{
    if ($id !== 11) {
        return null;
    }
    $post = new WP_Post;
    $post->post_type = $GLOBALS['config']['post_type'] ?? 'page';

    return $post;
}
function get_post_types($args, $format)
{
    return ['post', 'page', 'property', 'acf-field-group'];
}
function get_page_template_slug($id)
{
    return 'template-property.php';
}
function get_userdata($id)
{
    return $id === 21 ? new AcfFixtureUser : null;
}
function is_super_admin($id)
{
    return ! empty($GLOBALS['config']['superadmin']);
}
function get_term($id)
{
    return $id === 31 ? (object) ['taxonomy' => 'category', 'name' => 'News'] : false;
}
function get_taxonomy($name)
{
    return (object) ['show_ui' => empty($GLOBALS['config']['hidden_taxonomy'])];
}
function is_wp_error($value)
{
    return false;
}
function acf_get_options_pages()
{
    return [
        'site-settings' => ['menu_slug' => 'site-settings', 'page_title' => 'Site settings', 'post_id' => 'options'],
        'custom-settings' => ['menu_slug' => 'custom-settings', 'page_title' => 'Custom settings', 'post_id' => 'custom_storage'],
        'user-store' => ['menu_slug' => 'user-store', 'post_id' => 'user_1'],
        'number-store' => ['menu_slug' => 'number-store', 'post_id' => 11],
        'redirect-page' => ['menu_slug' => 'redirect-page', 'redirect' => true],
    ];
}
function acf_get_field_groups($screen)
{
    $GLOBALS['screens'][] = $screen;

    return [['key' => 'group_example', 'title' => 'Example fields', 'active' => true], ['key' => 'group_disabled', 'active' => false]];
}
function acf_get_fields($group)
{
    return $GLOBALS['config']['fields'] ?? [];
}
function acf_get_field($key)
{
    return $GLOBALS['config']['loaded'][$key] ?? null;
}
function get_field($key, $target, $formatted = true)
{
    $GLOBALS['reads'][] = [$key, $target, $formatted];

    return $GLOBALS['config']['values'][$key] ?? null;
}
if (! empty($config['with_state'])) {
    class Multioto_Agent_Acf_Management
    {
        public static function state($target, $field): array
        {
            return ['version' => 'version-'.$field['key'], 'token' => 'opaque-'.$field['key']];
        }
    }
}
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-acf-schema.php';
$results = [];
foreach ($config['calls'] ?? [] as $call) {
    try {
        $results[] = Multioto_Agent_Acf_Schema::call($call['tool'], $call['args'] ?? []);
    } catch (Throwable $error) {
        $results[] = ['error' => $error->getCode(), 'message' => $error->getMessage()];
    }
}
echo json_encode(['results' => $results, 'screens' => $GLOBALS['screens'], 'reads' => $GLOBALS['reads']], JSON_THROW_ON_ERROR);
