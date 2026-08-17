<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Custom fields: ACF and JetEngine.
 *
 * Most of our customers' sites do not keep their real content in `post_content`.
 * A property portal keeps the price, the rooms and the address in ACF fields on
 * a `property` post type; an events site keeps the date in JetEngine meta. An
 * agent that can only edit `post_content` can read those pages and change
 * nothing that matters on them.
 *
 * Two ways in, tried in order, because they behave differently:
 *
 *  · **ACF's own API** when it is active. `update_field()` knows the field's
 *    type, so a date goes in as ACF stores dates and a relationship as ids —
 *    writing raw meta instead would produce a value ACF cannot read back.
 *
 *  · **Plain post meta** otherwise (JetEngine, and hand-rolled meta boxes).
 *
 * What is never writable, by either route: keys beginning with an underscore.
 * That is WordPress's own convention for internal state — `_edit_lock`,
 * `_wp_page_template`, `_elementor_data`, WooCommerce's `_price` — and letting
 * an agent set those by name is how a page layout or a product price gets
 * corrupted through a door meant for a phone number. Elementor and WooCommerce
 * each have their own tool here, with their own validation.
 */
class Multioto_Agent_Fields
{
    /** Meta keys never exposed or written, whatever the source. */
    private const HIDDEN_PREFIXES = ['_'];

    public static function acfActive(): bool
    {
        return function_exists('get_fields') && function_exists('update_field');
    }

    /**
     * The field definitions attached to a post type, so the caller can map
     * "the property's price" onto the key `price` before writing anything.
     *
     * Without this the agent has to guess key names, and a guessed key writes a
     * new meta row that nothing on the site ever reads — a change that reports
     * success and does nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schema(string $postType): array
    {
        if (! function_exists('acf_get_field_groups')) {
            return [];
        }

        $groups = acf_get_field_groups(['post_type' => $postType]);
        $out = [];

        foreach ($groups as $group) {
            foreach ((array) acf_get_fields($group['key']) as $field) {
                if (self::hidden((string) ($field['name'] ?? ''))) {
                    continue;
                }

                $out[] = array_filter([
                    'group' => $group['title'] ?? '',
                    'key' => $field['name'] ?? '',
                    'label' => $field['label'] ?? '',
                    'type' => $field['type'] ?? '',
                    // Only for the field types where the allowed values ARE the
                    // contract; sending every option of every field would bury
                    // the schema in noise.
                    'choices' => in_array($field['type'] ?? '', ['select', 'radio', 'checkbox', 'button_group'], true)
                        ? ($field['choices'] ?? null)
                        : null,
                    'required' => ! empty($field['required']),
                ], static fn ($value): bool => $value !== null);
            }
        }

        return $out;
    }

    /**
     * The custom fields on one post, with their current values.
     *
     * @return array<string, mixed>
     */
    public static function values(int $postId): array
    {
        if (self::acfActive()) {
            $fields = get_fields($postId);

            if (is_array($fields)) {
                return array_filter(
                    $fields,
                    static fn (string $key): bool => ! self::hidden($key),
                    ARRAY_FILTER_USE_KEY
                );
            }
        }

        $out = [];

        foreach ((array) get_post_meta($postId) as $key => $value) {
            if (self::hidden((string) $key)) {
                continue;
            }

            // get_post_meta() without a key returns every value as an array,
            // even when there is exactly one — unwrapped here so a single value
            // reads as a single value.
            $out[$key] = is_array($value) && count($value) === 1 ? maybe_unserialize($value[0]) : $value;
        }

        return $out;
    }

    /**
     * Write custom fields, returning what was there before.
     *
     * The previous values are the point: they are what the platform stores as
     * the snapshot, and without them "undo" is a promise nobody can keep.
     *
     * @param  array<string, mixed>  $fields
     * @return array{updated: list<string>, previous: array<string, mixed>}
     */
    public static function update(int $postId, array $fields): array
    {
        $previous = [];
        $updated = [];

        foreach ($fields as $key => $value) {
            $key = (string) $key;

            if ($key === '' || self::hidden($key)) {
                throw new Multioto_Agent_Rpc_Error(-32602,
                    "השדה {$key} מוגן ואינו ניתן לעדכון דרך הסוכן.");
            }

            $previous[$key] = self::single($postId, $key);

            if (self::acfActive()) {
                update_field($key, $value, $postId);
            } else {
                update_post_meta($postId, $key, $value);
            }

            $updated[] = $key;
        }

        if ($updated === []) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'לא צוין שום שדה לעדכון.');
        }

        return ['updated' => $updated, 'previous' => $previous];
    }

    /** One field's current value, through ACF when it owns the field. */
    private static function single(int $postId, string $key)
    {
        if (self::acfActive()) {
            $value = get_field($key, $postId);

            if ($value !== null && $value !== false) {
                return $value;
            }
        }

        return get_post_meta($postId, $key, true);
    }

    private static function hidden(string $key): bool
    {
        foreach (self::HIDDEN_PREFIXES as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}
