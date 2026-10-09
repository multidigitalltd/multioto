<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * JetEngine CCT access through the vendor's registered factories and item API.
 * Called only after the MCP server authenticates the site's shared secret.
 * No table names, SQL, custom statuses, hard deletes or system columns are accepted.
 *
 * Vendor API references (no third-party implementation is bundled):
 * https://gist.github.com/Crocoblock/a9be7dbb1cb05aa2741aec97757c7f72
 * https://gist.github.com/Crocoblock/b5e87148c1cdc4e0359aa1565ae8f8cf
 * https://github.com/Crocoblock/developer-documentation/tree/main/01-jet-engine/04-modules/01-internal/01-custom-content-types
 *
 * CCT has publish/draft, not trash. Undoing creation means retaining a draft.
 * The item API runs the site's hooks; their external effects cannot be undone.
 */
class Multioto_Agent_Cct
{
    private const TOOLS = ['jet_cct_types', 'jet_cct_list', 'jet_cct_get', 'jet_cct_create', 'jet_cct_update'];

    private const SIMPLE_TYPES = ['text', 'textarea', 'wysiwyg', 'number', 'date', 'time', 'datetime-local', 'select', 'radio', 'switcher', 'colorpicker'];

    public static function handles(string $name): bool
    {
        return in_array($name, self::TOOLS, true);
    }

    public static function definitions(): array
    {
        $read = ['readOnlyHint' => true, 'destructiveHint' => false];
        $write = ['readOnlyHint' => false, 'destructiveHint' => false];
        $type = ['type' => 'string', 'pattern' => '^[a-z0-9_-]{1,64}$', 'description' => 'Registered CCT slug from jet_cct_types; never a database table.'];
        $id = ['type' => 'integer', 'minimum' => 1];
        $values = ['type' => 'object', 'description' => 'Only writable schema keys. cct_status accepts publish/draft.'];

        return [
            ['name' => 'jet_cct_types', 'description' => 'סוגי JetEngine CCT וסכמת השדות. יש לקרוא לפני חיפוש או שינוי. שדות מורכבים ומוגנים אינם ניתנים לעריכה.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => new stdClass]],
            ['name' => 'jet_cct_list', 'description' => 'רשימת פריטי CCT מסוג מסוים, כולל טיוטות, עם דפדוף ו-has_more. filters הוא מיפוי שדות רשומים לערך מדויק; אינו SQL.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['type' => $type, 'page' => ['type' => 'integer', 'minimum' => 1], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'filters' => ['type' => 'object']], 'required' => ['type']]],
            ['name' => 'jet_cct_get', 'description' => 'פריט CCT לפי סוג ומזהה; values מכיל ערכים לסקירת השינוי ולבדיקת expected.', 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => ['type' => $type, 'id' => $id], 'required' => ['type', 'id']]],
            ['name' => 'jet_cct_create', 'description' => 'יצירת פריט CCT, כטיוטה כברירת מחדל. ביטול פרסום משאיר את הפריט כטיוטה; אין מחיקה סופית. שדות נתמכים בלבד.', 'annotations' => $write, 'inputSchema' => ['type' => 'object', 'properties' => ['type' => $type, 'values' => $values], 'required' => ['type', 'values']]],
            ['name' => 'jet_cct_update', 'description' => 'עדכון שדות CCT או מעבר הפיך בין publish/draft. חובה expected עם הערך הנוכחי לכל שדה משתנה; אם השתנה מאז הסקירה, העדכון נעצר. before/after מאפשרים שחזור.', 'annotations' => $write, 'inputSchema' => ['type' => 'object', 'properties' => ['type' => $type, 'id' => $id, 'values' => $values, 'expected' => $values], 'required' => ['type', 'id', 'values', 'expected']]],
        ];
    }

    public static function call(string $name, array $args): array
    {
        if (! self::handles($name)) {
            self::fail('כלי CCT לא מוכר.');
        }

        $manager = self::manager();
        if ($name === 'jet_cct_types') {
            $types = [];
            foreach ($manager ? (array) $manager->get_content_types() : [] as $slug => $factory) {
                if (is_string($slug) && self::validType($slug) && self::compatible($factory)) {
                    $types[] = self::description($slug, $factory);
                }
            }

            return ['available' => $manager !== null, 'types' => $types, 'deletion_supported' => false];
        }

        $slug = $args['type'] ?? null;
        if (! is_string($slug) || ! self::validType($slug) || ! $manager) {
            self::fail('סוג ה-CCT אינו תקין או שמודול CCT אינו פעיל.');
        }

        $factory = $manager->get_content_types($slug);
        if (! self::compatible($factory)) {
            self::fail('סוג ה-CCT לא נמצא או שגרסת JetEngine אינה נתמכת.');
        }

        $schema = self::schema($factory);
        switch ($name) {
            case 'jet_cct_list':
                return self::listing($slug, $factory, $schema, $args);
            case 'jet_cct_get':
                return self::record($slug, $factory, $schema, self::item($factory, self::positiveInt($args['id'] ?? null)));
            case 'jet_cct_create':
            case 'jet_cct_update':
                return self::write($slug, $factory, $schema, $args, $name === 'jet_cct_create');
        }

        self::fail('כלי CCT לא מוכר.');
    }

    private static function manager()
    {
        $module = '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module';
        if (! function_exists('jet_engine') || ! class_exists($module)) {
            return null;
        }

        $engine = jet_engine();
        if (! isset($engine->modules) || ! $engine->modules->is_module_active('custom-content-types')) {
            return null;
        }

        $instance = $module::instance();

        return isset($instance->manager) && is_callable([$instance->manager, 'get_content_types']) ? $instance->manager : null;
    }

    private static function compatible($factory): bool
    {
        return is_object($factory) && is_callable([$factory, 'get_formatted_fields'])
            && is_callable([$factory, 'get_arg']) && is_callable([$factory, 'get_item_handler'])
            && is_callable([$factory, 'prepare_query_args']) && isset($factory->db)
            && is_callable([$factory->db, 'get_item']) && is_callable([$factory->db, 'query'])
            && is_callable([$factory->db, 'set_format_flag']);
    }

    private static function description(string $slug, $factory): array
    {
        $fields = [];
        foreach (self::schema($factory) as $key => $field) {
            $definition = ['key' => $key, 'label' => $field['label'], 'type' => $field['type'], 'writable' => $field['writable'], 'required' => $field['required'], 'choices' => $field['choices']];
            if ($field['type'] === 'number') {
                foreach (['min', 'max'] as $bound) {
                    if (isset($field[$bound]) && is_numeric($field[$bound]) && is_finite((float) $field[$bound])) {
                        $definition[$bound] = $field[$bound];
                    }
                }
            }
            $fields[] = $definition;
        }

        return ['type' => $slug, 'slug' => $slug, 'label' => (string) ($factory->get_arg('name') ?: $slug),
            'fields' => $fields, 'writable' => ! self::linkedPost($factory), 'create_supported' => self::creationSupported($factory),
            'statuses' => ['publish', 'draft'], 'undo_mode' => 'draft', 'deletion_supported' => false];
    }

    /** Use the registered schema, never field names inferred from an arbitrary row. */
    private static function schema($factory): array
    {
        $schema = [];
        foreach ((array) $factory->get_formatted_fields() as $key => $field) {
            if (! is_array($field) || ! is_string($key) || ! self::validKey($key) || self::protectedKey($key)) {
                continue;
            }

            $type = (string) ($field['type'] ?? '');
            $choices = [];
            foreach ((array) ($field['options'] ?? []) as $option) {
                if (is_array($option) && isset($option['key']) && is_scalar($option['key'])) {
                    $choices[] = (string) $option['key'];
                }
            }
            $writable = in_array($type, self::SIMPLE_TYPES, true)
                && ! self::truthy($field['is_array'] ?? false)
                && ! self::truthy($field['multiple'] ?? false)
                && ! self::truthy($field['is_multiple'] ?? false)
                && ! self::truthy($field['save_as_timestamp'] ?? false)
                && ! self::truthy($field['is_timestamp'] ?? false)
                && ! self::truthy($field['options_from_glossary'] ?? false);
            if (in_array($type, ['select', 'radio'], true) && $choices === []) {
                $writable = false;
            }
            $schema[$key] = ['label' => (string) ($field['title'] ?? $field['label'] ?? $key), 'type' => $type,
                'required' => self::truthy($field['is_required'] ?? $field['required'] ?? false), 'choices' => $choices,
                'writable' => $writable, 'min' => $field['min_value'] ?? null, 'max' => $field['max_value'] ?? null];
        }
        $schema['cct_status'] = ['label' => 'סטטוס', 'type' => 'select', 'required' => true, 'choices' => ['publish', 'draft'], 'writable' => true];

        return $schema;
    }

    private static function listing(string $slug, $factory, array $schema, array $args): array
    {
        $page = self::positiveInt($args['page'] ?? 1);
        $limit = self::positiveInt($args['limit'] ?? 20);
        if ($page > 10000 || $limit > 100) {
            self::fail('טווח הדפדוף אינו תקין. limit חייב להיות בין 1 ל-100.');
        }

        $filters = $args['filters'] ?? [];
        if (! is_array($filters) || count($filters) > 10) {
            self::fail('filters חייב להיות אובייקט עם עד עשרה שדות.');
        }
        $query = [];
        foreach ($filters as $key => $value) {
            if (! isset($schema[$key]) || ! $schema[$key]['writable']) {
                self::fail('שדה הסינון אינו שדה נתמך בסכמת CCT.');
            }
            $value = self::value($value, $schema[$key]);
            if ($value === null) {
                self::fail('ערך הסינון חייב להיות ערך מפורש.');
            }
            $query[] = ['field' => $key, 'operator' => '=', 'value' => $value, 'type' => $schema[$key]['type'] === 'number' ? 'NUMERIC' : 'CHAR'];
        }
        $query = $factory->prepare_query_args($query);
        $rows = self::reader($factory)->query($query, $limit + 1, ($page - 1) * $limit, [['orderby' => '_ID', 'order' => 'ASC', 'type' => 'NUMERIC']], 'AND');
        if (! is_array($rows)) {
            self::fail('לא ניתן לקרוא את פריטי CCT כרגע.');
        }
        $items = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $items[] = self::record($slug, $factory, $schema, (array) $row);
        }

        return ['type' => $slug, 'items' => $items, 'page' => $page, 'limit' => $limit, 'returned' => count($items), 'has_more' => count($rows) > $limit];
    }

    private static function item($factory, int $id): array
    {
        $item = self::reader($factory)->get_item($id);
        if ((! is_array($item) && ! is_object($item)) || (int) (((array) $item)['_ID'] ?? 0) !== $id) {
            self::fail('פריט ה-CCT לא נמצא בסוג התוכן שנבחר.');
        }

        return (array) $item;
    }

    private static function record(string $slug, $factory, array $schema, array $item): array
    {
        $values = [];
        foreach ($schema as $key => $field) {
            if ($field['writable']) {
                $values[$key] = $item[$key] ?? null;
            }
        }

        return ['id' => (int) ($item['_ID'] ?? 0), 'type' => $slug, 'label' => (string) ($factory->get_arg('name') ?: $slug), 'values' => $values,
            'writable' => ! self::linkedPost($factory) && empty($item['cct_single_post_id']), 'undo_mode' => 'draft'];
    }

    /** Avoid altering the factory's global result format for other site code. */
    private static function reader($factory)
    {
        $db = clone $factory->db;
        $db->set_format_flag(ARRAY_A);

        return $db;
    }

    private static function write(string $slug, $factory, array $schema, array $args, bool $create): array
    {
        if (self::linkedPost($factory)) {
            self::fail('עריכת CCT שמקושר לפוסט יחיד אינה נתמכת עד שניתן לשחזר גם את הפוסט המקושר.');
        }
        if ($create && ! self::creationSupported($factory)) {
            self::fail('בסוג CCT זה יש שדות חובה מוגנים או מורכבים. יש ליצור את הפריט בממשק האתר.');
        }
        $values = $args['values'] ?? null;
        if (! is_array($values) || $values === [] || count($values) > 100) {
            self::fail('values חייב להכיל בין שדה אחד למאה שדות מוגדרים.');
        }
        if ($create && ! array_key_exists('cct_status', $values)) {
            $values['cct_status'] = 'draft';
        }
        $validated = [];
        foreach ($values as $key => $value) {
            if (! isset($schema[$key]) || ! $schema[$key]['writable']) {
                self::fail('הבקשה מכילה שדה מוגן, לא מוכר או מורכב שאינו נתמך. לא בוצע שינוי.');
            }
            $validated[$key] = self::value($value, $schema[$key]);
        }

        $before = [];
        $id = null;
        if ($create) {
            foreach ($schema as $key => $field) {
                if ($field['required'] && (! $field['writable'] || ! array_key_exists($key, $validated))) {
                    self::fail('חסר שדה חובה בסוג ה-CCT. אם השדה מורכב יש ליצור את הפריט בממשק האתר.');
                }
            }
        } else {
            $id = self::positiveInt($args['id'] ?? null);
            $current = self::item($factory, $id);
            if (! empty($current['cct_single_post_id'])) {
                self::fail('הפריט מקושר לפוסט נוסף ואינו ניתן לשינוי דרך CCT.');
            }
            $expected = $args['expected'] ?? null;
            if (! is_array($expected) || $expected === []) {
                self::fail('חסר expected: נדרשים הערכים שנקראו לפני אישור השינוי.');
            }
            foreach ($validated as $key => $value) {
                if (! array_key_exists($key, $expected)) {
                    self::fail('expected חייב לכלול כל שדה שמשתנה.');
                }
                $before[$key] = $current[$key] ?? null;
                if ($before[$key] !== $expected[$key]) {
                    self::fail('הפריט השתנה מאז הסקירה. יש לקרוא שוב ולאשר את השינוי מחדש.');
                }
                self::assertRestorable($before[$key], $schema[$key]);
            }
            if ($before === $validated) {
                return ['id' => $id, 'type' => $slug, 'changed' => false, 'before' => $before, 'after' => $before, 'values' => $before];
            }
        }

        $payload = $validated;
        if ($id !== null) {
            $payload['_ID'] = $id;
        }
        $handler = $factory->get_item_handler();
        if (! is_object($handler) || ! is_callable([$handler, 'update_item'])) {
            self::fail('גרסת JetEngine אינה מספקת ממשק כתיבה נתמך.');
        }
        $writtenId = $handler->update_item($payload);
        if (is_wp_error($writtenId) || ! is_numeric($writtenId) || (int) $writtenId < 1 || ($id !== null && (int) $writtenId !== $id)) {
            self::fail('JetEngine לא אישר את שמירת הפריט. יש לבדוק את מצבו לפני ניסיון נוסף.');
        }
        $record = self::record($slug, $factory, $schema, self::item($factory, (int) $writtenId));
        $after = [];
        foreach (array_keys($validated) as $key) {
            $after[$key] = $record['values'][$key];
        }

        return array_merge($record, ['changed' => true, 'created' => $create, 'before' => $before, 'after' => $after, 'deletion_supported' => false]);
    }

    /**
     * Undo uses the same validated setter. Refuse a change when that setter
     * would strip legacy content, coerce its type, or reject an obsolete option.
     * Number validation deliberately returns the original representation, so
     * numeric strings from the database do not become false unsafe snapshots.
     */
    private static function assertRestorable($before, array $field): void
    {
        try {
            $restored = self::value($before, $field);
        } catch (Multioto_Agent_Rpc_Error $e) {
            self::fail('הערך הקיים אינו ניתן לשחזור מדויק דרך סכמת CCT הנוכחית. יש לערוך אותו בממשק האתר.');
        }
        if ($restored !== $before) {
            self::fail('הערך הקיים דורש ניקוי או המרה שימנעו שחזור מדויק. לא בוצע שינוי; יש לערוך אותו בממשק האתר.');
        }
    }

    /** Reject unsupported structures before any item is sent to JetEngine. */
    private static function value($value, array $field)
    {
        if ($value === null && ! $field['required']) {
            return null;
        }
        if (! is_scalar($value) || ($field['required'] && $value === '')) {
            self::fail('ערך שדה אינו תקין או שחסר ערך חובה.');
        }
        $type = $field['type'];
        if ($type === 'number') {
            if (is_bool($value) || ! is_numeric($value) || ! is_finite((float) $value)
                || (isset($field['min']) && (float) $value < (float) $field['min'])
                || (isset($field['max']) && (float) $value > (float) $field['max'])) {
                self::fail('השדה מחייב מספר בטווח המוגדר.');
            }

            return $value;
        }
        if ($type === 'switcher') {
            if (! in_array($value, [true, false, 'true', 'false'], true)) {
                self::fail('שדה switcher מחייב true או false.');
            }

            return self::truthy($value) ? 'true' : 'false';
        }
        if (! is_string($value) || strlen($value) > 50000) {
            self::fail('השדה מחייב מחרוזת עד 50,000 בתים.');
        }
        if (in_array($type, ['select', 'radio'], true) && ! in_array($value, $field['choices'], true)) {
            self::fail('הערך אינו אחת האפשרויות המוגדרות לשדה.');
        }
        if (in_array($type, ['date', 'datetime-local', 'time'], true) && $value !== '') {
            $format = ['date' => 'Y-m-d', 'datetime-local' => 'Y-m-d\\TH:i', 'time' => 'H:i'][$type];
            $date = DateTime::createFromFormat('!'.$format, $value);
            if (! $date || $date->format($format) !== $value) {
                self::fail('תאריך או שעה אינם תקינים.');
            }
        }
        if ($type === 'colorpicker' && $value !== '' && ! preg_match('/^#[a-fA-F0-9]{6}$/D', $value)) {
            self::fail('צבע חייב להיות בפורמט #RRGGBB.');
        }
        if ($type === 'wysiwyg') {
            return wp_kses_post($value);
        }

        return $type === 'textarea' ? sanitize_textarea_field($value) : sanitize_text_field($value);
    }

    private static function linkedPost($factory): bool
    {
        return self::truthy($factory->get_arg('has_single'));
    }

    private static function creationSupported($factory): bool
    {
        if (self::linkedPost($factory)) {
            return false;
        }
        $schema = self::schema($factory);
        foreach ((array) $factory->get_formatted_fields() as $key => $field) {
            if (is_array($field) && self::truthy($field['is_required'] ?? $field['required'] ?? false)
                && (! isset($schema[$key]) || ! $schema[$key]['writable'])) {
                return false;
            }
        }

        return true;
    }

    private static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $key);
    }

    private static function validType(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9_-]{1,64}$/D', $slug);
    }

    private static function protectedKey(string $key): bool
    {
        return strpos($key, 'cct_') === 0 || (bool) preg_match('/(?:password|passwd|secret|token|api_?key|private_?key|authorization)/i', $key);
    }

    private static function truthy($value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private static function positiveInt($value): int
    {
        if (! is_int($value) || $value < 1) {
            self::fail('מזהה ומספר עמוד חייבים להיות מספרים שלמים חיוביים.');
        }

        return $value;
    }

    private static function fail(string $message): void
    {
        throw new Multioto_Agent_Rpc_Error(-32602, $message);
    }
}
