<?php

// Execute real WordPress adapters in a separate process, with stateful storage.
define('ABSPATH', '/tmp/multioto-legacy-fields/');
$GLOBALS['legacy_input'] = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$GLOBALS['legacy_writes'] = [];
$GLOBALS['legacy_reads'] = [];

class Multioto_Agent_Rpc_Error extends RuntimeException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
class WP_Post
{
    public $ID = 7;

    public $post_type = 'project';

    public $post_status = 'publish';

    public $post_title = 'A project';
}

function get_post($id)
{
    return $id === 7 ? new WP_Post : null;
}
function get_post_types($args, $format)
{
    return ['project'];
}
function get_post_meta($id, $key = '', $single = false)
{
    $meta = $GLOBALS['legacy_input']['meta'] ?? [];
    if ($key !== '') {
        return $meta[$key] ?? '';
    }

    return array_map(static function ($value) {
        return [$value];
    }, $meta);
}
function maybe_unserialize($value)
{
    return $value;
}
function update_post_meta($id, $key, $value)
{
    $GLOBALS['legacy_writes'][] = ['id' => $id, 'key' => $key, 'value' => $value];
    $GLOBALS['legacy_input']['meta'][$key] = $value;

    return true;
}

if (! empty($GLOBALS['legacy_input']['acf'])) {
    function get_fields($id)
    {
        throw new RuntimeException('Formatted get_fields must never be used.');
    }
    function update_field($key, $value, $id)
    {
        $GLOBALS['legacy_writes'][] = ['acf' => true, 'id' => $id, 'key' => $key, 'value' => $value];

        return true;
    }
    function get_field($key, $id, $format = true)
    {
        $GLOBALS['legacy_reads'][] = ['key' => $key, 'format' => $format];

        return $GLOBALS['legacy_input']['values'][$key] ?? false;
    }
    function acf_get_field_groups($screen)
    {
        return [['key' => 'group_project', 'title' => 'Project', 'active' => true]];
    }
    function acf_get_fields($group)
    {
        return $GLOBALS['legacy_input']['definitions'] ?? [];
    }
}

if (empty($GLOBALS['legacy_input']['without_schema'])) {
    require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-acf-schema.php';
}
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-fields.php';

try {
    switch ($GLOBALS['legacy_input']['action']) {
        case 'schema': $result = Multioto_Agent_Fields::schema('project');
            break;
        case 'read': $result = Multioto_Agent_Fields::values(7);
            break;
        case 'update': $result = Multioto_Agent_Fields::update(7, $GLOBALS['legacy_input']['fields']);
            break;
        default: throw new RuntimeException('Unknown test operation.');
    }
    $out = ['result' => $result];
} catch (Multioto_Agent_Rpc_Error $error) {
    $out = ['error' => $error->getMessage(), 'code' => $error->getCode()];
}
echo json_encode($out + ['writes' => $GLOBALS['legacy_writes'], 'reads' => $GLOBALS['legacy_reads']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
