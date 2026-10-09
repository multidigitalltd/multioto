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
 *  · **ACF's own API** for schema-aware, unformatted reads. Its writes use the
 *    dedicated prepare/update tools, with typed validation and sealed undo
 *    state. The legacy generic writer must never bypass that validation.
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
        self::assertLearnDashContentAllowed($postType);
        if (! function_exists('acf_get_field_groups')) {
            return [];
        }

        $groups = acf_get_field_groups(['post_type' => $postType]);
        $out = [];

        foreach ($groups as $group) {
            foreach ((array) acf_get_fields($group['key']) as $field) {
                if (self::hidden((string) ($field['name'] ?? '')) || self::isLearnDashReserved((string) ($field['name'] ?? ''), $postType) || ($field['type'] ?? '') === 'password'
                    || (class_exists('Multioto_Agent_Acf_Schema') && Multioto_Agent_Acf_Schema::isProtected($field))) {
                    continue;
                }

                $out[] = array_filter([
                    'group' => $group['title'] ?? '',
                    'key' => $field['name'] ?? '',
                    'native_field_key' => $field['key'] ?? '',
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
        self::assertLearnDashContentAllowed(self::postType($postId));
        if (self::acfActive()) {
            // Never fall through to raw post meta when ACF owns the data: its
            // row storage contains secret children under otherwise public keys.
            // The bootstrap always loads this dependency; isolated legacy
            // consumers without it receive no ACF values rather than raw data.
            if (! class_exists('Multioto_Agent_Acf_Schema')) {
                return [];
            }

            $target = Multioto_Agent_Acf_Schema::resolveTarget(['context' => 'post', 'id' => $postId]);
            $out = [];
            foreach (Multioto_Agent_Acf_Schema::fieldsForTarget($target) as $field) {
                $definition = Multioto_Agent_Acf_Schema::fieldDefinition($field);
                if (! $definition['editable'] || $definition['sensitive'] || self::hidden($definition['name'])) {
                    continue;
                }
                $raw = get_field($field['key'], $target['acf_id'], false);
                Multioto_Agent_Acf_Schema::bounded($raw);
                $out[$definition['name']] = Multioto_Agent_Acf_Schema::redact($field, $raw);
            }
            Multioto_Agent_Acf_Schema::bounded($out);

            return $out;
        }

        $out = [];

        foreach ((array) get_post_meta($postId) as $key => $value) {
            if (self::hidden((string) $key) || self::isLearnDashReserved((string) $key, self::postType($postId))) {
                continue;
            }

            // get_post_meta() without a key returns every value as an array,
            // even when there is exactly one — unwrapped here so a single value
            // reads as a single value.
            $raw = is_array($value) && count($value) === 1 ? maybe_unserialize($value[0]) : $value;
            $out[$key] = self::redactMeta($raw);
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
        self::assertLearnDashContentAllowed(self::postType($postId));
        if ($fields === []) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'לא צוין שום שדה לעדכון.');
        }

        if (self::acfActive()) {
            throw new Multioto_Agent_Rpc_Error(-32602,
                'עריכת ACF ו-ACF Pro דורשת הכנה ואישור דרך wp_acf_prepare ו-wp_acf_update. יש להשתמש בכלי ACF המעודכנים. לא בוצע שום שינוי.');
        }

        /*
         * Every key is checked before ANY key is written.
         *
         * Validating inside the write loop looks equivalent and is not: a
         * request whose third key is protected would have committed the first
         * two and then returned an error carrying no snapshot. The caller reads
         * that as "nothing happened", the journal records nothing to undo, and
         * two customer fields have quietly changed with no way back. A refusal
         * has to mean the site was not touched.
         */
        foreach (array_keys($fields) as $key) {
            $key = (string) $key;

            if ($key === '' || self::hidden($key) || self::isLearnDashReserved($key, self::postType($postId)) || self::containsProtectedMeta($fields[$key])) {
                throw new Multioto_Agent_Rpc_Error(-32602,
                    "השדה {$key} מוגן ואינו ניתן לעדכון דרך הסוכן. לא בוצע שום שינוי.");
            }
        }

        $previous = [];
        $updated = [];

        // Collect and validate every snapshot before the first write. A public
        // container may still contain protected children that must be preserved.
        foreach (array_keys($fields) as $key) {
            $previous[$key] = get_post_meta($postId, (string) $key, true);
            if (self::containsProtectedMeta($previous[$key])) {
                throw new Multioto_Agent_Rpc_Error(-32602,
                    'השדה מכיל נתונים מוגנים ואינו ניתן להחלפה דרך כלי meta כללי. לא בוצע שום שינוי.');
            }
        }

        foreach ($fields as $key => $value) {
            $key = (string) $key;
            update_post_meta($postId, $key, $value);

            $updated[] = $key;
        }

        return ['updated' => $updated, 'previous' => $previous];
    }

    /** Generic post metadata is not an alternative to LearnDash's enrollment/builder APIs. */
    public static function learnDashActive(): bool
    {
        return defined('LEARNDASH_VERSION') || class_exists('SFWD_LMS') || function_exists('learndash_get_post_type_slug')
            || (function_exists('post_type_exists') && post_type_exists('sfwd-courses'));
    }

    public static function isLearnDashType(string $postType): bool
    {
        if (! self::learnDashActive()) {
            return false;
        }
        $types = ['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question', 'sfwd-certificates', 'sfwd-assignment', 'sfwd-essays', 'sfwd-transactions', 'groups'];
        return in_array($postType, $types, true);
    }

    /** Graded submissions, quiz questions and transactions are native LMS state, not page content. */
    public static function isLearnDashInternalType(string $postType): bool
    {
        return self::learnDashActive() && in_array($postType, ['sfwd-assignment', 'sfwd-essays', 'sfwd-transactions', 'sfwd-question'], true);
    }

    public static function assertLearnDashContentAllowed(string $postType): void
    {
        if (self::isLearnDashInternalType($postType)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'רשומות פנימיות של LearnDash, כולל הגשות, שאלות ועסקאות, מוגנות ואינן זמינות דרך כלי תוכן ושדות כלליים.');
        }
    }

    public static function isLearnDashReserved(string $name, string $postType): bool
    {
        if (! self::learnDashActive()) {
            return false;
        }
        $name = strtolower($name);
        if (preg_match('/^(?:_?sfwd[_-]|learndash_|wp_pro_quiz_|proquiz_)/', $name)
            || preg_match('/^(?:ld_)?course_\d+_(?:access_from|access_expires|access_expire|completed)$/', $name)
            || preg_match('/^course_completed_\d+$/', $name)) {
            return true;
        }
        return self::isLearnDashType($postType) && in_array($name, [
            'course_id', 'lesson_id', 'topic_id', 'quiz_id', 'question_id', 'quiz_pro_id', 'question_pro_id',
            'course_access_list', 'course_access_settings', 'course_price', 'course_price_type', 'course_prerequisite',
            'course_points', 'course_disable_content_table', 'course_steps', 'course_sections', 'quiz_settings',
        ], true);
    }

    public static function assertContentCreationAllowed(string $postType): void
    {
        self::assertLearnDashContentAllowed($postType);
        if (self::isLearnDashType($postType) && in_array($postType, ['sfwd-quiz', 'sfwd-question'], true)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'יצירת מבחן או שאלה של LearnDash דורשת את מנגנון המבחנים המקורי. יצירת פוסט רגיל אינה יוצרת מבחן שמיש.');
        }
    }

    private static function postType(int $postId): string
    {
        $post = function_exists('get_post') ? get_post($postId) : null;
        return $post ? (string) $post->post_type : '';
    }

    /** Raw meta never exports PHP objects or secret values nested in arrays. */
    private static function redactMeta($value, int $depth = 0)
    {
        if ($depth > 16 || is_object($value) || is_resource($value)) {
            return null;
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $child) {
                if (! self::hidden((string) $key)) {
                    $safe[$key] = self::redactMeta($child, $depth + 1);
                }
            }

            return $safe;
        }

        return $value;
    }

    private static function containsProtectedMeta($value, int $depth = 0): bool
    {
        if ($depth > 16 || is_object($value) || is_resource($value)) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (self::hidden((string) $key) || self::containsProtectedMeta($child, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hidden(string $key): bool
    {
        foreach (self::HIDDEN_PREFIXES as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }

        $key = (string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $key);

        return (bool) preg_match('/(^|[_\-\s])(password|passwd|pwd|secret|token|authorization|credential|private_?key|api_?key|access_?key|client_?secret)([_\-\s]|$)/i', $key);
    }
}
