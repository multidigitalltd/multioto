<?php

// Models ACF's native metadata layout: group names, repeater row prefixes,
// cloned loaded names and one underscore-prefixed reference per stored value.
define('ABSPATH', __DIR__);
class Multioto_Agent_Rpc_Error extends RuntimeException
{
    public function __construct($code, $message)
    {
        parent::__construct($message, $code);
    }
}
class WP_Post
{
    public $ID = 11;

    public $post_type = 'page';

    public $post_title = 'Page';

    public $post_status = 'publish';
}
class AcfNativeFixtureGroup
{
    // Same name rule as ACF's public group::prepare_field_for_db().
    public function prepare_field_for_db(array $field): array
    {
        foreach ($field['sub_fields'] as &$child) {
            $child['name'] = $field['name'].'_'.$child['_name'];
        }

        return $field;
    }
}
function acf_get_field_type($type)
{
    return $type === 'group' ? new AcfNativeFixtureGroup : null;
}
function get_post($id)
{
    return $id === 11 ? new WP_Post : null;
}
function get_post_types($args, $format)
{
    return ['page'];
}
function acf_get_field_groups($args)
{
    return [['key' => 'group_page', 'title' => 'Page', 'active' => true]];
}
function acf_get_fields($group)
{
    return $GLOBALS['native_fields'];
}
function acf_get_field($key)
{
    $queue = $GLOBALS['native_fields'];
    while ($queue) {
        $field = array_shift($queue);
        if ($field['key'] === $key) {
            return $field;
        }
        $queue = array_merge($queue, $field['sub_fields'] ?? []);
    }

    return false;
}
function acf_get_metadata($id, $name, $hidden = false)
{
    return $GLOBALS['native_meta'][$id][($hidden ? '_' : '').$name] ?? null;
}
function acf_update_metadata($id, $name, $value, $hidden = false)
{
    $value = wp_unslash($value);
    $GLOBALS['native_meta'][$id][($hidden ? '_' : '').$name] = is_scalar($value) ? (string) $value : $value;
}
function acf_delete_metadata($id, $name, $hidden = false)
{
    unset($GLOBALS['native_meta'][$id][($hidden ? '_' : '').$name]);
}
function get_field($key, $id, $formatted = true)
{
    return native_load($id, acf_get_field($key));
}
function native_children($field, $index = null)
{
    $children = $field['sub_fields'] ?? [];
    foreach ($children as &$child) {
        if ($field['type'] === 'group') {
            $child['name'] = $field['name'].'_'.($child['_name'] ?? $child['name']);
        } elseif ($field['type'] === 'repeater') {
            $child['name'] = $field['name'].'_'.$index.'_'.($child['_name'] ?? $child['name']);
        }
        // Loaded Clone fields already carry their correct storage names.
    }

    return $children;
}
function native_load($id, $field)
{
    if (in_array($field['type'], ['group', 'clone'], true)) {
        $out = [];
        foreach (native_children($field) as $child) {
            $out[$child['key']] = native_load($id, $child);
        }

        return $out;
    }
    $value = acf_get_metadata($id, $field['name']);
    if ($field['type'] === 'repeater') {
        $out = [];
        for ($i = 0; $i < (int) $value; $i++) {
            $row = [];
            foreach (native_children($field, $i) as $child) {
                $row[$child['key']] = native_load($id, $child);
            }
            $out[] = $row;
        }

        return $out ?: false;
    }

    return $value === null ? false : $value;
}
function update_field($key, $value, $id)
{
    $GLOBALS['native_writes']++;
    native_save($id, acf_get_field($key), $value);

    return true;
}
function native_save($id, $field, $value)
{
    $stored = $value;
    if (in_array($field['type'], ['group', 'clone'], true)) {
        foreach (native_children($field) as $child) {
            if (is_array($value) && array_key_exists($child['key'], $value)) {
                native_save($id, $child, $value[$child['key']]);
            }
        }
        $stored = '';
    } elseif ($field['type'] === 'repeater') {
        $oldCount = (int) acf_get_metadata($id, $field['name']);
        foreach ((array) $value as $i => $row) {
            foreach (native_children($field, $i) as $child) {
                if (array_key_exists($child['key'], $row)) {
                    native_save($id, $child, $row[$child['key']]);
                }
            }
        }
        for ($i = count((array) $value); $i < $oldCount; $i++) {
            foreach (native_children($field, $i) as $child) {
                native_delete($id, $child);
            }
        }
        $stored = count((array) $value);
    }
    acf_update_metadata($id, $field['name'], $stored);
    acf_update_metadata($id, $field['name'], $field['key'], true);
}
function native_delete($id, $field)
{
    if (in_array($field['type'], ['group', 'clone'], true)) {
        foreach (native_children($field) as $child) {
            native_delete($id, $child);
        }
    } elseif ($field['type'] === 'repeater') {
        for ($i = 0; $i < (int) acf_get_metadata($id, $field['name']); $i++) {
            foreach (native_children($field, $i) as $child) {
                native_delete($id, $child);
            }
        }
    }
    acf_delete_metadata($id, $field['name']);
    acf_delete_metadata($id, $field['name'], true);
}
function delete_field($key, $id)
{
    native_delete($id, acf_get_field($key));
}
function acf_get_data($key)
{
    return $GLOBALS['native_data'][$key] ?? false;
}
function acf_set_data($key, $value)
{
    $GLOBALS['native_data'][$key] = $value;
}
function acf_flush_value_cache($id, $name) {}
function wp_slash($value)
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }

    return is_string($value) ? addslashes($value) : $value;
}
function wp_unslash($value)
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}
function wp_salt($scheme)
{
    return 'native-storage-test-only-secret';
}
function sanitize_text_field($value)
{
    return trim(strip_tags($value));
}
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-acf-schema.php';
require __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-acf-management.php';
