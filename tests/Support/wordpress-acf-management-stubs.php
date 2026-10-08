<?php

define('ABSPATH', __DIR__);
class Multioto_Agent_Rpc_Error extends RuntimeException
{
    public function __construct($code, $message)
    {
        parent::__construct($message, $code);
    }
}
class Multioto_Agent_Acf_Schema
{
    public static function resolveTarget(array $args): array
    {
        return ['context' => $args['context'] ?? 'post', 'id' => $args['id'] ?? 1, 'acf_id' => ($args['context'] ?? 'post') === 'post' ? ($args['id'] ?? 1) : ($args['context'].'_'.($args['id'] ?? 1)), 'label' => 'Target'];
    }

    public static function fieldsForTarget(array $target): array
    {
        return array_values($GLOBALS['acf_fields']);
    }

    public static function isProtected(array $field): bool
    {
        return strpos($field['name'], '_') === 0 || $field['name'] === 'api_key';
    }
}
function wp_salt($scheme)
{
    return 'test-secret-never-exported';
}
function acf_get_field($key)
{
    return $GLOBALS['acf_fields'][$key] ?? null;
}
function get_field($key, $id, $format = true)
{
    $GLOBALS['acf_read_formats'][] = $format;

    return $GLOBALS['acf_values'][$id][$key] ?? false;
}
function acf_get_metadata($id, $name, $hidden = false)
{
    return $GLOBALS[$hidden ? 'acf_refs' : 'acf_meta'][$id][$name] ?? null;
}
function acf_update_metadata($id, $name, $value, $hidden = false)
{
    $GLOBALS[$hidden ? 'acf_refs' : 'acf_meta'][$id][$name] = wp_unslash($value);
}
function acf_delete_metadata($id, $name, $hidden = false)
{
    unset($GLOBALS[$hidden ? 'acf_refs' : 'acf_meta'][$id][$name]);
}
function acf_get_data($key)
{
    return $GLOBALS['acf_data'][$key] ?? false;
}
function acf_set_data($key, $value)
{
    $GLOBALS['acf_data'][$key] = $value;
}
function update_field($key, $value, $id)
{
    $value = wp_unslash($value);
    $field = acf_get_field($key);
    $old = get_field($key, $id, false);
    $GLOBALS['acf_writes'][] = [$key, $value, $id];
    if (! empty($GLOBALS['acf_fail_once'])) {
        $GLOBALS['acf_fail_once'] = false;

        return false;
    }
    $GLOBALS['acf_values'][$id][$key] = $value;
    $GLOBALS['acf_meta'][$id][$field['name']] = is_array($value) ? count($value) : $value;
    $GLOBALS['acf_refs'][$id][$field['name']] = $key;
    if (! empty($field['bidirectional']) && ! acf_get_data('acf_doing_bidirectional_update')) {
        $oldGuard = acf_get_data('acf_doing_bidirectional_update');
        acf_set_data('acf_doing_bidirectional_update', true);
        foreach ($field['bidirectional_target'] as $inverse) {
            foreach (array_diff((array) $value, (array) $old) as $other) {
                update_field($inverse, array_values(array_unique(array_merge((array) (get_field($inverse, $other, false) ?: []), [$id]))), $other);
            }
            foreach (array_diff((array) $old, (array) $value) as $other) {
                update_field($inverse, array_values(array_diff((array) get_field($inverse, $other, false), [$id])), $other);
            }
        }
        acf_set_data('acf_doing_bidirectional_update', $oldGuard);
    }
    if (! empty($field['save_terms'])) {
        wp_set_object_terms($id, (array) $value, $field['taxonomy'], false);
    }

    return true;
}
function delete_field($key, $id)
{
    $field = acf_get_field($key);
    unset($GLOBALS['acf_values'][$id][$key], $GLOBALS['acf_meta'][$id][$field['name']], $GLOBALS['acf_refs'][$id][$field['name']]);
}
function acf_flush_value_cache($id, $name) {}
function is_email($value)
{
    return filter_var($value, FILTER_VALIDATE_EMAIL);
}
function sanitize_text_field($value)
{
    return trim(strip_tags($value));
}
function sanitize_textarea_field($value)
{
    return trim(strip_tags($value));
}
function wp_kses_post($value)
{
    return strip_tags($value, '<p><strong><em><a><br>');
}
function esc_url_raw($value, $protocols = [])
{
    return preg_match('~^(https?://|mailto:|tel:)~i', $value) ? $value : '';
}
function get_post($id)
{
    return isset($GLOBALS['acf_posts'][$id]) ? (object) $GLOBALS['acf_posts'][$id] : null;
}
function get_userdata($id)
{
    return isset($GLOBALS['acf_users'][$id]) ? (object) $GLOBALS['acf_users'][$id] : null;
}
function get_term($id, $taxonomy = '')
{
    return isset($GLOBALS['acf_terms'][$id]) ? (object) $GLOBALS['acf_terms'][$id] : null;
}
function is_wp_error($value)
{
    return false;
}
function get_attached_file($id)
{
    return '/tmp/photo.jpg';
}
function wp_get_attachment_metadata($id)
{
    return ['width' => 800, 'height' => 600];
}
function wp_get_object_terms($id, $taxonomy, $args)
{
    return $GLOBALS['acf_object_terms'][$id][$taxonomy] ?? [];
}
function wp_set_object_terms($id, $ids, $taxonomy, $append)
{
    $GLOBALS['acf_object_terms'][$id][$taxonomy] = $ids;

    return $ids;
}
require_once __DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-acf-management.php';

function wp_slash($value)
{
    return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value);
}
function wp_unslash($value)
{
    return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
}
