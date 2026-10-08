<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reversible library metadata and Optimole's supported, non-destructive settings.
 * Authentication belongs to the MCP server. Files, GUIDs, account credentials,
 * offloading and originals are deliberately outside this tool's vocabulary.
 */
class Multioto_Agent_Media_Management
{
    private const MEDIA_FIELDS = ['title', 'alt', 'caption', 'description', 'parent_id', 'terms'];

    private const OPTIMOLE_FLAGS = [
        'image_replacer', 'autoquality', 'lazyload', 'lazyload_placeholder',
        'retina_images', 'resize_smart', 'native_lazyload',
    ];

    public static function definitions(): array
    {
        $media = [
            'type' => 'object', 'additionalProperties' => false, 'minProperties' => 1,
            'properties' => [
                'title' => ['type' => 'string', 'maxLength' => 1000],
                'alt' => ['type' => 'string', 'maxLength' => 2000],
                'caption' => ['type' => 'string', 'maxLength' => 20000],
                'description' => ['type' => 'string', 'maxLength' => 50000],
                'parent_id' => ['type' => 'integer', 'minimum' => 0],
                'terms' => ['type' => 'object', 'additionalProperties' => [
                    'type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'integer', 'minimum' => 1],
                ]],
            ],
        ];
        $optimole = ['type' => 'object', 'additionalProperties' => false, 'minProperties' => 1, 'properties' => [
            'quality' => ['type' => 'integer', 'minimum' => 50, 'maximum' => 100, 'description' => 'איכות ידנית; משפיעה כאשר autoquality=disabled.'],
        ]];
        foreach (self::OPTIMOLE_FLAGS as $field) {
            $optimole['properties'][$field] = ['type' => 'string', 'enum' => ['enabled', 'disabled']];
        }

        return [
            self::definition('wp_media_get', 'פרטי קובץ: כותרת, alt, כיתוב, תיאור, שיוך לתוכן וטקסונומיות מדיה זמינות. שינוי כותרת אינו שינוי שם הקובץ הפיזי; שינוי שם פיזי אינו נתמך.', ['id' => ['type' => 'integer', 'minimum' => 1]], ['id']),
            self::definition('wp_media_update', 'עדכון הפיך של מאפייני מדיה וארגון בטקסונומיות קיימות. נדרשים values ו-expected מקריאה טרייה. אין שינוי כתובת, שם קובץ פיזי או תוכן הקובץ.', [
                'id' => ['type' => 'integer', 'minimum' => 1], 'values' => $media, 'expected' => $media,
            ], ['id', 'values', 'expected'], true),
            self::definition('wp_optimole_get', 'מצב Optimole והגדרות אופטימיזציה בטוחות שניתן לשנות. אין מפתחות API, מחיקת מקור, offload או ניקוי ענן. האופטימיזציה עצמה נעשית בשירות Optimole בעת הגשת התמונה.', [], []),
            self::definition('wp_optimole_update', 'שינוי הפיך של הגדרות Optimole הנתמכות. נדרשים values ו-expected. איכות 50–100; שדות אחרים enabled/disabled. אינו מחליף או מוחק את קובצי המקור.', [
                'id' => ['type' => 'integer', 'enum' => [1]], 'values' => $optimole, 'expected' => $optimole,
            ], ['id', 'values', 'expected'], true),
        ];
    }

    private static function definition(string $name, string $description, array $properties, array $required, bool $write = false): array
    {
        return [
            'name' => $name, 'description' => $description,
            'annotations' => ['readOnlyHint' => ! $write, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'inputSchema' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false],
        ];
    }

    public static function handles(string $name): bool
    {
        return in_array($name, ['wp_media_get', 'wp_media_update', 'wp_optimole_get', 'wp_optimole_update'], true);
    }

    public static function call(string $name, array $args): array
    {
        switch ($name) {
            case 'wp_media_get':
                return self::mediaGet(self::id($args));
            case 'wp_media_update':
                return self::mediaUpdate($args);
            case 'wp_optimole_get':
                return self::optimoleGet();
            case 'wp_optimole_update':
                return self::optimoleUpdate($args);
        }
        throw new Multioto_Agent_Rpc_Error(-32601, 'כלי מדיה לא מוכר.');
    }

    private static function id(array $args): int
    {
        if (! isset($args['id']) || ! is_int($args['id']) || $args['id'] < 1) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'נדרש מזהה חיובי של פריט.');
        }

        return $args['id'];
    }

    private static function mediaGet(int $id): array
    {
        $post = get_post($id);
        if (! $post instanceof WP_Post || $post->post_type !== 'attachment' || $post->post_status === 'trash') {
            throw new Multioto_Agent_Rpc_Error(-32602, 'הקובץ אינו קיים בספריית המדיה.');
        }
        $terms = [];
        $taxonomies = [];
        foreach (get_object_taxonomies('attachment', 'objects') as $taxonomy) {
            if (empty($taxonomy->show_ui) || ! empty($taxonomy->_builtin)) {
                continue;
            }
            $ids = wp_get_object_terms($id, $taxonomy->name, ['fields' => 'ids']);
            self::assertResult($ids);
            $terms[$taxonomy->name] = array_map('intval', $ids);
            sort($terms[$taxonomy->name], SORT_NUMERIC);
            $taxonomies[] = ['name' => $taxonomy->name, 'label' => (string) $taxonomy->label];
        }
        ksort($terms);
        $url = (string) wp_get_attachment_url($id);

        return [
            'id' => $id, 'label' => (string) $post->post_title, 'url' => $url,
            'mime' => (string) $post->post_mime_type,
            'filename' => wp_basename((string) get_attached_file($id)),
            'filename_rename_supported' => false,
            'editable_taxonomies' => $taxonomies,
            'values' => [
                'title' => (string) $post->post_title,
                'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
                'caption' => (string) $post->post_excerpt,
                'description' => (string) $post->post_content,
                'parent_id' => (int) $post->post_parent,
                'terms' => $terms,
            ],
        ];
    }

    private static function mediaUpdate(array $args): array
    {
        $id = self::id($args);
        $record = self::mediaGet($id);
        [$values, $expected] = self::patch($args, self::MEDIA_FIELDS);
        foreach ($values as $key => $value) {
            if ($key === 'terms') {
                $values[$key] = self::validateTerms($value, $record['values']['terms']);
            } elseif ($key === 'parent_id') {
                if (! is_int($value) || $value < 0) {
                    throw new Multioto_Agent_Rpc_Error(-32602, 'מזהה תוכן לשיוך אינו תקין.');
                }
                if ($value > 0) {
                    $parent = get_post($value);
                    $type = $parent ? get_post_type_object($parent->post_type) : null;
                    if (! $parent || ! $type || empty($type->show_ui) || in_array($parent->post_type, ['attachment', 'revision', 'nav_menu_item'], true) || in_array($parent->post_status, ['trash', 'auto-draft'], true)) {
                        throw new Multioto_Agent_Rpc_Error(-32602, 'ניתן לשייך קובץ רק לפריט תוכן קיים בממשק הניהול.');
                    }
                }
            } else {
                if ($key === 'alt' && strpos($record['mime'], 'image/') !== 0) {
                    throw new Multioto_Agent_Rpc_Error(-32602, 'טקסט חלופי נתמך לתמונות בלבד.');
                }
                $values[$key] = self::mediaText($key, $value);
                // The saved before value will pass through this same public
                // validator on undo. Refuse legacy data it cannot restore
                // exactly instead of silently dropping markup or whitespace.
                if (self::mediaText($key, $record['values'][$key]) !== $record['values'][$key]) {
                    throw new Multioto_Agent_Rpc_Error(-32602, 'הערך הקיים אינו מתאים לשחזור מדויק דרך הבוט. יש לערוך אותו בלוח הבקרה.');
                }
            }
        }
        self::assertExpected($record['values'], $values, $expected);
        $before = self::slice($record['values'], $values);
        if ($before === $values) {
            return ['id' => $id, 'changed' => false, 'before' => $before, 'after' => $before];
        }
        try {
            self::saveMedia($id, $values);
            $after = self::slice(self::mediaGet($id)['values'], $values);
            if ($after !== $values) {
                throw new Multioto_Agent_Rpc_Error(-32000, 'חלק מהגדרות המדיה לא נשמרו. יש לקרוא מחדש לפני ניסיון נוסף.');
            }
        } catch (Throwable $error) {
            // Core uses separate post/meta/term writes. Compensate successfully
            // written fields if a later step fails, only while still ours.
            // Plugin hooks can veto even restoration, so callers must re-read
            // after failure rather than infer an all-or-nothing transaction.
            foreach ($values as $key => $value) {
                try {
                    $current = self::mediaGet($id)['values'];
                    if ($key === 'terms') {
                        foreach ($value as $taxonomy => $ids) {
                            if ($current['terms'][$taxonomy] === $ids && $before['terms'][$taxonomy] !== $ids) {
                                self::saveMedia($id, ['terms' => [$taxonomy => $before['terms'][$taxonomy]]]);
                            }
                        }
                    } elseif ($current[$key] === $value && $before[$key] !== $value) {
                        self::saveMedia($id, [$key => $before[$key]]);
                    }
                } catch (Throwable $restoreError) {
                    // Continue restoring independent fields; surface failure below.
                }
            }
            throw new Multioto_Agent_Rpc_Error(-32000, 'שמירת המדיה נכשלה. בוצע ניסיון לשחזר שדות שנשמרו; יש לקרוא את המצב מחדש.');
        }

        return ['id' => $id, 'changed' => $before !== $after, 'before' => $before, 'after' => $after];
    }

    private static function mediaText(string $field, $value): string
    {
        $limits = ['title' => 1000, 'alt' => 2000, 'caption' => 20000, 'description' => 50000];
        $length = is_string($value)
            ? (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value))
            : false;
        if ($length === false || $length > $limits[$field]) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'ערך טקסט אינו תקין או ארוך מדי.');
        }

        return in_array($field, ['title', 'alt'], true) ? sanitize_text_field($value) : wp_kses_post($value);
    }

    private static function saveMedia(int $id, array $values): void
    {
        $post = ['ID' => $id];
        foreach (['title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content', 'parent_id' => 'post_parent'] as $field => $column) {
            if (array_key_exists($field, $values)) {
                $post[$column] = $values[$field];
            }
        }
        if (count($post) > 1) {
            self::assertResult(wp_update_post(wp_slash($post), true));
        }
        if (array_key_exists('alt', $values)) {
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash($values['alt']));
        }
        foreach ($values['terms'] ?? [] as $taxonomy => $termIds) {
            self::assertResult(wp_set_object_terms($id, $termIds, $taxonomy, false));
        }
    }

    private static function validateTerms($value, array $current): array
    {
        if (! is_array($value) || count($value) > 20) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'נדרשת מפת טקסונומיות מדיה.');
        }
        foreach ($value as $taxonomy => $ids) {
            if (! array_key_exists($taxonomy, $current) || ! is_array($ids) || count($ids) > 100) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'טקסונומיית המדיה אינה זמינה לארגון.');
            }
            foreach ($ids as $id) {
                if (! is_int($id) || $id < 1 || ! term_exists($id, $taxonomy)) {
                    throw new Multioto_Agent_Rpc_Error(-32602, 'אחד ממזהי הקבוצות אינו קיים בטקסונומיה.');
                }
            }
            $value[$taxonomy] = array_values(array_unique($ids));
            sort($value[$taxonomy], SORT_NUMERIC);
        }
        ksort($value);

        return $value;
    }

    /**
     * Public API verified against Codeinwp/optimole-wp inc/settings.php,
     * upstream e1d431ae3338bd220265e4a491820b1c8755ca18 (4.2.16).
     * update() preserves the complete option and performs Optimole's own hooks.
     * Account/offload/cache-buster values are never accepted or returned.
     */
    private static function optimoleSettings()
    {
        if (! defined('OPTML_NAMESPACE') || ! class_exists('Optml_Settings')) {
            return null;
        }
        foreach (['get', 'is_connected', 'update'] as $method) {
            if (! method_exists('Optml_Settings', $method)) {
                return null;
            }
        }

        return new Optml_Settings;
    }

    private static function optimoleGet(): array
    {
        $settings = self::optimoleSettings();
        $values = [];
        $editable = [];
        if ($settings) {
            foreach (array_merge(self::OPTIMOLE_FLAGS, ['quality']) as $field) {
                $value = $settings->get($field);
                if ($field === 'quality') {
                    if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || (int) $value < 50 || (int) $value > 100) {
                        continue;
                    }
                    $value = (int) $value;
                } elseif (! in_array($value, ['enabled', 'disabled'], true)) {
                    continue;
                }
                $values[$field] = $value;
                if (! self::optimoleLocked($field)) {
                    $editable[] = $field;
                }
            }
        }

        return [
            'id' => 1, 'label' => 'Optimole', 'active' => $settings !== null,
            'connected' => $settings ? (bool) $settings->is_connected() : false,
            'version' => defined('OPTML_VERSION') ? (string) OPTML_VERSION : null,
            'values' => $values, 'editable_fields' => $editable,
            'original_files_modified' => false,
        ];
    }

    private static function optimoleLocked(string $field): bool
    {
        if (defined('OPTIML_ENABLED_MU') && OPTIML_ENABLED_MU && defined('OPTIML_MU_SITE_ID') && OPTIML_MU_SITE_ID && (int) OPTIML_MU_SITE_ID !== get_current_blog_id()) {
            return true;
        }

        return defined('OPTIML_USE_ENV') && OPTIML_USE_ENV && defined('OPTIML_'.strtoupper($field));
    }

    private static function optimoleUpdate(array $args): array
    {
        if (self::id($args) !== 1) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'מזהה הגדרות Optimole אינו תקין.');
        }
        $record = self::optimoleGet();
        if (! $record['active'] || ! $record['connected']) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'Optimole חייב להיות פעיל ומחובר לפני שינוי הגדרות.');
        }
        [$values, $expected] = self::patch($args, $record['editable_fields']);
        foreach ($values as $key => $value) {
            if ($key === 'quality' ? (! is_int($value) || $value < 50 || $value > 100) : ! in_array($value, ['enabled', 'disabled'], true)) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'ערך הגדרת Optimole אינו תקין.');
            }
        }
        self::assertExpected($record['values'], $values, $expected);
        $settings = self::optimoleSettings();
        // parse_settings() also WRITES each setting; it is not a validator.
        // The narrow values above already match Optimole's accepted enums/range.
        $before = self::slice($record['values'], $values);
        $written = [];
        foreach ($values as $key => $value) {
            if ($before[$key] === $value) {
                continue;
            }
            if (! $settings->update($key, $value)) {
                // Restore only settings this request changed, while they still
                // contain our value. A refused write must not create a false undo.
                foreach ($written as $restoreKey => $restoreValue) {
                    if ($settings->get($restoreKey) === $restoreValue) {
                        $settings->update($restoreKey, $before[$restoreKey]);
                    }
                }
                throw new Multioto_Agent_Rpc_Error(-32000, 'שמירת Optimole נכשלה. יש לקרוא את המצב מחדש לפני ניסיון נוסף.');
            }
            $written[$key] = $value;
        }
        $after = self::slice(self::optimoleGet()['values'], $values);
        if ($after !== $values) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'הגדרות Optimole השתנו במהלך השמירה; יש לקרוא מחדש.');
        }

        return ['id' => 1, 'changed' => $before !== $after, 'before' => $before, 'after' => $after];
    }

    private static function patch(array $args, array $allowed): array
    {
        $values = $args['values'] ?? null;
        $expected = $args['expected'] ?? null;
        if (! is_array($values) || ! $values || ! is_array($expected) || array_diff(array_keys($values), $allowed)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'נדרשים values ו-expected עם שדות נתמכים בלבד.');
        }

        return [$values, $expected];
    }

    private static function assertExpected(array $current, array $values, array $expected): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $expected)) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'חסר ערך קודם לשינוי בטוח.');
            }
            if ($key === 'terms') {
                if (! is_array($expected[$key])) {
                    throw new Multioto_Agent_Rpc_Error(-32602, 'חסרים שיוכי המדיה הקודמים.');
                }
                foreach ($value as $taxonomy => $ids) {
                    $previous = $expected[$key][$taxonomy] ?? null;
                    if (is_array($previous)) {
                        sort($previous, SORT_NUMERIC);
                    }
                    if ($previous !== $current[$key][$taxonomy]) {
                        throw new Multioto_Agent_Rpc_Error(-32009, 'שיוכי המדיה השתנו מאז הקריאה. יש לקרוא מחדש ולאשר שוב.');
                    }
                }
            } elseif (! array_key_exists($key, $current) || $expected[$key] !== $current[$key]) {
                throw new Multioto_Agent_Rpc_Error(-32009, 'הפריט השתנה מאז הקריאה. יש לקרוא מחדש ולאשר שוב.');
            }
        }
    }

    private static function slice(array $current, array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$key] = $key === 'terms' ? array_intersect_key($current[$key], $value) : $current[$key];
        }

        return $result;
    }

    private static function assertResult($result): void
    {
        if (is_wp_error($result)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $result->get_error_message());
        }
    }
}
