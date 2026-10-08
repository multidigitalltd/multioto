<?php

if (! defined('ABSPATH')) {
    exit;
}

/** ACF's loaded field definitions and unformatted values, scoped to a real edit screen. */
class Multioto_Agent_Acf_Schema
{
    private const TYPES = ['text', 'textarea', 'number', 'range', 'email', 'url', 'password', 'wysiwyg', 'oembed', 'image', 'file', 'gallery', 'select', 'checkbox', 'radio', 'button_group', 'true_false', 'link', 'post_object', 'relationship', 'taxonomy', 'user', 'page_link', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'icon_picker', 'google_map', 'group', 'repeater', 'flexible_content', 'clone'];
    private const STRUCTURAL = ['message', 'tab', 'accordion', 'separator', 'output'];
    private const MAX_BYTES = 262144;
    private const MAX_FIELDS = 500;
    private const MAX_DEPTH = 16;

    public static function selectorProperties(): array
    {
        return [
            'context' => ['type' => 'string', 'enum' => ['post', 'user', 'term', 'options']],
            'id' => ['type' => 'integer', 'minimum' => 1],
            'options_page' => ['type' => 'string'],
            'field_key' => ['type' => 'string', 'description' => 'מפתח field_ מדויק מהסכמה; אפשר לקרוא שדה יחיד כדי לצמצם את התשובה.'],
        ];
    }

    public static function definitions(): array
    {
        $definitions = [];
        foreach ([
            ['wp_acf_schema', 'סכמת ACF ו-ACF Pro לפי מיקום העריכה האמיתי, כולל שדות מקוננים, פריסות ו-Clone. context הוא post, user, term או options; options דורש options_page רשום.'],
            ['wp_acf_get', 'קריאת ערכי ACF לא מפורמטים ומפתחות שדה יציבים, עם snapshots מוצפנים לאישור ושחזור. סיסמאות מוסתרות. אין לקצר או להמציא ערכי שורות.'],
            ['wp_acf_options_pages', 'רשימת עמודי אפשרויות ACF הרשומים באתר, לצורך בחירת options_page מפורש.'],
        ] as $spec) {
            $definitions[] = [
                'name' => $spec[0], 'description' => $spec[1],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'inputSchema' => ['type' => 'object', 'properties' => $spec[0] === 'wp_acf_options_pages' ? (object) [] : self::selectorProperties(), 'required' => [], 'additionalProperties' => false],
            ];
        }
        return $definitions;
    }

    public static function handles(string $name): bool
    {
        return in_array($name, ['wp_acf_schema', 'wp_acf_get', 'wp_acf_options_pages'], true);
    }

    public static function call(string $name, array $args): array
    {
        self::active();
        if ($name === 'wp_acf_options_pages') {
            return ['pages' => self::optionsPages()];
        }
        if ($name === 'wp_acf_get') {
            return self::snapshot($args);
        }
        if ($name !== 'wp_acf_schema') {
            self::fail('כלי ACF לא מוכר.');
        }
        $target = self::resolveTarget($args);
        $fields = self::selectedFields(self::fieldsForTarget($target), $args);
        $out = ['target' => $target, 'fields' => self::definitionsForFields($fields)];
        self::bounded($out);
        return $out;
    }

    public static function active(): void
    {
        if (! function_exists('get_field') || ! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
            self::fail('ACF אינו פעיל באתר.');
        }
    }

    public static function fail(string $message): void
    {
        throw new Multioto_Agent_Rpc_Error(-32602, $message);
    }

    /** A caller cannot supply an arbitrary ACF storage identifier. */
    public static function resolveTarget(array $args): array
    {
        self::active();
        $context = $args['context'] ?? 'post';
        if (! is_string($context) || ! in_array($context, ['post', 'user', 'term', 'options'], true)) {
            self::fail('הקשר ACF אינו תקין.');
        }
        if ($context === 'options') {
            $slug = $args['options_page'] ?? null;
            if (! is_string($slug) || $slug === '') {
                self::fail('יש לבחור עמוד אפשרויות רשום.');
            }
            foreach (self::optionsPages() as $page) {
                if ($page['options_page'] === $slug) {
                    return ['context' => 'options', 'id' => null, 'options_page' => $slug, 'acf_id' => $page['acf_id'], 'label' => $page['label']];
                }
            }
            self::fail('עמוד אפשרויות ACF זמין לא נמצא.');
        }
        $id = $args['id'] ?? null;
        if (! is_int($id) || $id < 1) {
            self::fail('נדרש מזהה מספרי חיובי.');
        }
        $target = ['context' => $context, 'id' => $id, 'options_page' => null];
        if ($context === 'post') {
            $post = get_post($id);
            $excluded = ['revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'acf-field', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'elementor_library', 'e-landing-page', 'shop_order', 'shop_order_refund', 'shop_subscription'];
            $types = get_post_types(['show_ui' => true], 'names');
            $types[] = 'attachment';
            $types[] = 'product_variation';
            if (! $post instanceof WP_Post || in_array($post->post_type, $excluded, true) || ! in_array($post->post_type, $types, true) || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
                self::fail('פריט תוכן זמין לא נמצא.');
            }
            return $target + ['acf_id' => $id, 'label' => (string) $post->post_title];
        }
        if ($context === 'user') {
            $user = get_userdata($id);
            if (! $user || (function_exists('is_super_admin') && is_super_admin($id))) {
                self::fail('משתמש זמין לעריכה לא נמצא.');
            }
            foreach (['manage_options', 'promote_users', 'edit_users', 'delete_users', 'manage_network'] as $capability) {
                if ($user->has_cap($capability)) {
                    self::fail('שדות של משתמש בעל הרשאות ניהול מוגנים.');
                }
            }
            return $target + ['acf_id' => 'user_'.$id, 'label' => (string) $user->display_name];
        }
        $term = get_term($id);
        $taxonomy = $term && ! is_wp_error($term) ? get_taxonomy($term->taxonomy) : null;
        if (! $taxonomy || empty($taxonomy->show_ui)) {
            self::fail('מונח בטקסונומיה זמינה לא נמצא.');
        }
        return $target + ['acf_id' => 'term_'.$id, 'label' => (string) $term->name];
    }

    /** Match ACF's own post, user, taxonomy and options edit-screen location rules. */
    public static function fieldsForTarget(array $target): array
    {
        switch ($target['context']) {
            case 'post':
                $post = get_post($target['id']);
                $screen = ['post_id' => $target['id'], 'post_type' => $post->post_type, 'post_status' => $post->post_status];
                if (function_exists('get_page_template_slug')) {
                    $screen['page_template'] = get_page_template_slug($post->ID) ?: 'default';
                }
                break;
            case 'user': $screen = ['user_id' => $target['id'], 'user_form' => 'edit']; break;
            case 'term': $screen = ['taxonomy' => get_term($target['id'])->taxonomy, 'term_id' => $target['id']]; break;
            case 'options': $screen = ['options_page' => $target['options_page']]; break;
            default: self::fail('הקשר ACF אינו תקין.');
        }
        $fields = [];
        foreach ((array) acf_get_field_groups($screen) as $group) {
            if (isset($group['active']) && ! $group['active']) {
                continue;
            }
            foreach ((array) acf_get_fields($group) as $field) {
                if (! is_array($field) || empty($field['key'])) {
                    continue;
                }
                $field = self::loadedField($field);
                $key = $field['key'];
                if (isset($fields[$key])) {
                    continue;
                }
                $field['_multioto_group'] = (string) ($group['title'] ?? '');
                $fields[$key] = $field;
                if (count($fields) > self::MAX_FIELDS) {
                    self::fail('סכמת ACF גדולה מדי. יש לצמצם את קבוצות השדות של היעד.');
                }
            }
        }
        return array_values($fields);
    }

    /** Loaded Clone sub_fields already contain ACF's exact prefixed keys and names. */
    private static function loadedField(array $field): array
    {
        if (($field['type'] ?? '') === 'clone' && empty($field['sub_fields']) && function_exists('acf_get_field')) {
            $loaded = acf_get_field($field['key']);
            if (is_array($loaded) && ! empty($loaded['sub_fields'])) {
                return $loaded;
            }
        }
        return $field;
    }

    private static function selectedFields(array $fields, array $args): array
    {
        if (! isset($args['field_key'])) {
            return $fields;
        }
        if (! is_string($args['field_key'])) {
            self::fail('מפתח השדה אינו תקין.');
        }
        foreach ($fields as $field) {
            if ($field['key'] === $args['field_key']) {
                return [$field];
            }
        }
        self::fail('השדה אינו שייך לסכמת היעד הנוכחי.');
    }

    private static function definitionsForFields(array $fields): array
    {
        $count = 0;
        $out = [];
        foreach ($fields as $field) {
            $out[] = self::definition($field, 0, $count);
        }
        return $out;
    }

    public static function fieldDefinition(array $field): array
    {
        $count = 0;
        return self::definition($field, 0, $count);
    }

    private static function definition(array $field, int $depth, int &$count): array
    {
        if ($depth > self::MAX_DEPTH || ++$count > self::MAX_FIELDS) {
            self::fail('סכמת ACF עמוקה או גדולה מדי; לא הוחזר מידע חלקי.');
        }
        $field = self::loadedField($field);
        $type = (string) ($field['type'] ?? '');
        $protected = self::isProtected($field);
        $supported = in_array($type, self::TYPES, true);
        $out = [
            'key' => (string) ($field['key'] ?? ''), 'name' => (string) ($field['name'] ?? ''),
            'label' => (string) ($field['label'] ?? ''), 'type' => $type,
            'required' => ! empty($field['required']), 'editable' => $supported && ! $protected,
            'sensitive' => $type === 'password' || $protected,
        ];
        if (isset($field['_multioto_group'])) {
            $out['group'] = $field['_multioto_group'];
        }
        if (! $out['editable']) {
            $out['reason'] = in_array($type, self::STRUCTURAL, true) ? 'layout_only' : ($protected ? 'protected' : 'unsupported_custom_type');
        }
        // Settings affecting accepted raw values; default values and arbitrary
        // plugin settings may contain secrets and are deliberately not exported.
        foreach (['min', 'max', 'step', 'maxlength', 'multiple', 'allow_null', 'return_format', 'post_type', 'post_status', 'taxonomy', 'role', 'save_terms', 'load_terms', 'add_term', 'bidirectional', 'bidirectional_target', 'mime_types', 'min_width', 'max_width', 'min_height', 'max_height', 'min_size', 'max_size', 'display', 'prefix_name', 'prefix_label', 'collapsed', 'allow_in_bindings', 'tabs', 'sources', 'field_type', 'enable_opacity', 'allow_archives', 'allow_custom', 'save_custom', 'other_choice', 'save_other_choice', 'create_options', 'save_options'] as $key) {
            if (array_key_exists($key, $field)) {
                $out[$key] = $field[$key];
            }
        }
        $dateFormats = ['date_picker' => 'Ymd', 'date_time_picker' => 'Y-m-d H:i:s', 'time_picker' => 'H:i:s'];
        if (isset($dateFormats[$type])) {
            $out['storage_format'] = $dateFormats[$type];
        }
        if (in_array($type, ['select', 'checkbox', 'radio', 'button_group'], true)) {
            $out['choices'] = $field['choices'] ?? [];
        }
        if (in_array($type, ['group', 'repeater', 'clone'], true)) {
            $out['sub_fields'] = [];
            foreach ((array) ($field['sub_fields'] ?? []) as $child) {
                $out['sub_fields'][] = self::definition($child, $depth + 1, $count);
            }
            if ($type === 'clone' && ! $out['sub_fields']) {
                $out['editable'] = false;
                $out['reason'] = 'unresolved_clone';
            }
        }
        if ($type === 'flexible_content') {
            $out['layouts'] = [];
            foreach ((array) ($field['layouts'] ?? []) as $layout) {
                $item = ['key' => (string) ($layout['key'] ?? ''), 'name' => (string) ($layout['name'] ?? ''), 'label' => (string) ($layout['label'] ?? ''), 'min' => $layout['min'] ?? '', 'max' => $layout['max'] ?? '', 'sub_fields' => []];
                foreach ((array) ($layout['sub_fields'] ?? []) as $child) {
                    $item['sub_fields'][] = self::definition($child, $depth + 1, $count);
                }
                $out['layouts'][] = $item;
            }
        }
        return $out;
    }

    public static function isProtected(array $field): bool
    {
        $name = (string) ($field['name'] ?? '');
        $normalized = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name));
        $normalized = str_replace('-', '_', $normalized);
        return $name === '' || strpos($name, '_') === 0 || ! empty($field['readonly']) || ! empty($field['disabled'])
            || in_array($normalized, ['user_pass', 'session_tokens', 'wp_capabilities', 'wp_user_level'], true)
            || (bool) preg_match('/(^|_)(api_?key|access_?token|refresh_?token|secret|private_?key|auth_?token|client_?secret|encryption_?key|webhook_?secret|token|authorization|credentials?)(_|$)/', $normalized)
            || (($field['type'] ?? '') !== 'password' && (bool) preg_match('/(^|_)(password|passwd|pwd)(_|$)/', $normalized));
    }

    public static function snapshot(array $args): array
    {
        $target = self::resolveTarget($args);
        $fields = self::selectedFields(self::fieldsForTarget($target), $args);
        $out = ['target' => $target, 'fields' => self::definitionsForFields($fields), 'values' => [], 'snapshots' => []];
        foreach ($fields as $field) {
            $definition = self::fieldDefinition($field);
            if (! $definition['editable']) {
                continue;
            }
            $raw = get_field($field['key'], $target['acf_id'], false);
            self::bounded($raw);
            $out['values'][$field['key']] = self::redact($field, $raw);
            if (class_exists('Multioto_Agent_Acf_Management')) {
                $out['snapshots'][$field['key']] = Multioto_Agent_Acf_Management::state($target, $field);
            }
        }
        self::bounded($out);
        return $out;
    }

    /** Only registered children are returned, never a raw custom-object blob. */
    public static function redact(array $field, $value, int $depth = 0)
    {
        if ($depth > self::MAX_DEPTH || is_object($value) || is_resource($value)) {
            self::fail('ערך ACF עמוק מדי או אינו מבנה נתמך.');
        }
        $type = $field['type'] ?? '';
        if ($type === 'password' || self::isProtected($field)) {
            return '[מוסתר]';
        }
        if (! in_array($type, self::TYPES, true)) {
            return null;
        }
        if (in_array($type, ['group', 'clone'], true)) {
            if (! is_array($value)) {
                return $value;
            }
            return self::redactChildren((array) ($field['sub_fields'] ?? []), $value, $depth + 1);
        }
        if (in_array($type, ['repeater', 'flexible_content'], true)) {
            if (! is_array($value)) {
                return $value;
            }
            $rows = [];
            foreach ($value as $row) {
                if (! is_array($row)) {
                    self::fail('שורת ACF אינה תקינה.');
                }
                $children = (array) ($field['sub_fields'] ?? []);
                if ($type === 'flexible_content') {
                    $children = null;
                    foreach ((array) ($field['layouts'] ?? []) as $layout) {
                        if (($layout['name'] ?? null) === ($row['acf_fc_layout'] ?? null)) {
                            $children = (array) ($layout['sub_fields'] ?? []);
                            break;
                        }
                    }
                    if ($children === null) {
                        self::fail('תוכן גמיש מכיל פריסה שאינה רשומה; יש לתקן את הסכמה לפני עריכה.');
                    }
                }
                $safe = self::redactChildren($children, $row, $depth + 1);
                if ($type === 'flexible_content') {
                    $safe = ['acf_fc_layout' => $row['acf_fc_layout']] + $safe;
                }
                $rows[] = $safe;
            }
            return $rows;
        }
        if (in_array($type, ['link', 'google_map', 'icon_picker'], true) && is_array($value)) {
            $keys = $type === 'link' ? ['url', 'title', 'target'] : ($type === 'icon_picker' ? ['type', 'value'] : ['address', 'lat', 'lng', 'zoom', 'place_id', 'name', 'street_number', 'street_name', 'street_name_short', 'city', 'state', 'state_short', 'post_code', 'country', 'country_short']);
            $safe = array_intersect_key($value, array_flip($keys));
            foreach ($safe as $item) {
                if (! is_scalar($item) && $item !== null) {
                    self::fail('מבנה הערך אינו תואם לסוג שדה ACF.');
                }
            }
            return $safe;
        }
        if (is_array($value) && ! in_array($type, ['gallery', 'select', 'checkbox', 'post_object', 'relationship', 'taxonomy', 'user', 'page_link'], true)) {
            self::fail('מבנה הערך אינו תואם לסוג שדה ACF.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! is_scalar($item) && $item !== null) {
                    self::fail('מבנה הערך אינו תואם לסוג שדה ACF.');
                }
            }
        }
        if (in_array($type, ['post_object', 'relationship', 'taxonomy', 'user', 'gallery', 'image', 'file'], true)) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item !== null && $item !== false && $item !== '' && ! is_int($item) && (! is_string($item) || ! ctype_digit($item))) {
                    self::fail('מזהה ACF גולמי אינו תקין.');
                }
            }
        }
        return $value;
    }

    private static function redactChildren(array $fields, array $value, int $depth): array
    {
        $safe = [];
        foreach ($fields as $child) {
            $child = self::loadedField($child);
            $key = $child['key'] ?? '';
            $name = $child['name'] ?? '';
            if (array_key_exists($key, $value)) {
                $safe[$key] = self::redact($child, $value[$key], $depth);
            } elseif ($name !== '' && array_key_exists($name, $value)) {
                $safe[$key] = self::redact($child, $value[$name], $depth);
            }
        }
        return $safe;
    }

    public static function bounded($value): void
    {
        $nodes = 0;
        self::plainData($value, 0, $nodes);
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            self::fail('נתוני ACF גדולים מדי; בחר שדה יחיד. לא הוחזר מידע חלקי.');
        }
    }

    private static function plainData($value, int $depth, int &$nodes): void
    {
        if (++$nodes > 20000 || $depth > 24 || is_object($value) || is_resource($value)) {
            self::fail('נתוני ACF אינם מבנה ערכים נתמך.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::plainData($item, $depth + 1, $nodes);
            }
        }
    }

    private static function optionsPages(): array
    {
        if (! function_exists('acf_get_options_pages')) {
            return [];
        }
        $out = [];
        foreach ((array) acf_get_options_pages() as $slug => $page) {
            if (! is_array($page)) {
                continue;
            }
            $slug = (string) ($page['menu_slug'] ?? $slug);
            $storage = $page['post_id'] ?? 'options';
            if ($storage === 'option') {
                $storage = 'options';
            }
            if (! is_string($storage) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/', $storage) || preg_match('/^(user|term|comment|post)_/i', $storage) || $slug === '') {
                continue;
            }
            $out[] = ['options_page' => $slug, 'label' => (string) ($page['page_title'] ?? $page['menu_title'] ?? $slug), 'acf_id' => $storage];
        }
        return $out;
    }
}
