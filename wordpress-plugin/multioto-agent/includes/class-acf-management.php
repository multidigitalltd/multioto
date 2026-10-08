<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Typed, optimistic and reversible native ACF edits. Snapshots never disclose stored secrets. */
class Multioto_Agent_Acf_Management
{
    private const LIMIT = 180000;

    private const MAX_ROWS = 200;

    private const LAYOUT_TYPES = ['tab', 'accordion', 'message', 'separator', 'output'];

    public static function definitions(): array
    {
        $properties = [
            'context' => ['type' => 'string', 'enum' => ['post', 'term', 'user', 'options']],
            'id' => ['type' => 'integer'],
            'options_page' => ['type' => 'string'],
            'field_key' => ['type' => 'string'],
            'expected' => ['type' => 'object'],
        ];

        return [
            ['name' => 'wp_acf_prepare', 'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false], 'description' => 'Validate a native ACF/ACF Pro edit and return a masked preview and sealed proposal without writing.', 'inputSchema' => ['type' => 'object', 'properties' => $properties + ['operations' => ['type' => 'array', 'items' => ['type' => 'object']]], 'required' => ['context', 'field_key', 'expected', 'operations']]],
            ['name' => 'wp_acf_update', 'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false], 'description' => 'Apply a sealed ACF proposal or restore a sealed snapshot after an exact concurrency check.', 'inputSchema' => ['type' => 'object', 'properties' => $properties + ['prepared' => ['type' => 'object'], 'restore' => ['type' => 'object']], 'required' => ['context', 'field_key', 'expected']]],
        ];
    }

    public static function handles(string $name): bool
    {
        return in_array($name, ['wp_acf_prepare', 'wp_acf_update'], true);
    }

    public static function call(string $name, array $args): array
    {
        if (! self::handles($name)) {
            self::fail('כלי ACF לא מוכר.');
        }
        $target = Multioto_Agent_Acf_Schema::resolveTarget($args);
        $field = self::findField($target, (string) ($args['field_key'] ?? ''));
        $expected = self::open((array) ($args['expected'] ?? []));
        self::assertBound($expected, $target, $field);
        self::assertCurrent($expected, $field);
        if ($name === 'wp_acf_prepare') {
            return self::prepare($target, $field, $expected, $args);
        }
        if (isset($args['restore']) === isset($args['prepared'])) {
            self::fail('יש לספק הצעה חתומה או צילום שחזור אחד.');
        }
        if (isset($args['restore'])) {
            $desired = self::open((array) $args['restore']);
            self::assertBound($desired, $target, $field);
            if (isset($desired['purpose'])) {
                self::fail('צילום השחזור אינו תקין.');
            }
        } else {
            $proposal = self::open((array) $args['prepared']);
            self::assertBound($proposal, $target, $field);
            if (($proposal['purpose'] ?? '') !== 'proposal' || ! hash_equals((string) ($proposal['expected_version'] ?? ''), (string) ($args['expected']['version'] ?? ''))) {
                self::fail('ההצעה אינה תואמת לצילום שאושר.');
            }
            $desired = $expected;
            $desired['raw'] = $proposal['desired'];
            $desired['exists'] = true;
            $desired['reference'] = $field['key'];
            // Capture new inverse relationships before the first write, too.
            $desired['related'] = $proposal['related'];
            $expected['related'] = $proposal['related'];
            self::assertCurrent($expected, $field);
        }

        return self::apply($target, $field, $expected, $desired, isset($args['restore']));
    }

    /** The stable version is separate from the randomized authenticated ciphertext. */
    public static function state(array $target, array $field): array
    {
        return self::seal(self::capture($target, $field));
    }

    /** Values exposed to the model and approval preview must never contain passwords. */
    public static function redact(array $field, $value)
    {
        if (Multioto_Agent_Acf_Schema::isProtected($field) || ($field['type'] ?? '') === 'password') {
            return ['redacted' => true, 'has_value' => $value !== null && $value !== false && $value !== ''];
        }
        if (method_exists('Multioto_Agent_Acf_Schema', 'redact')) {
            return Multioto_Agent_Acf_Schema::redact($field, $value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $type = $field['type'] ?? '';
        if (in_array($type, ['repeater', 'flexible_content'], true)) {
            $out = [];
            foreach ($value as $row) {
                $out[] = self::redactChildren(self::children($field, $row), (array) $row);
            }

            return $out;
        }
        if (in_array($type, ['group', 'clone'], true)) {
            return self::redactChildren(self::children($field, $value), $value);
        }

        return $value;
    }

    private static function redactChildren(array $children, array $value): array
    {
        $out = [];
        if (isset($value['acf_fc_layout'])) {
            $out['acf_fc_layout'] = $value['acf_fc_layout'];
        }
        foreach ($children as $child) {
            $key = (string) $child['key'];
            if (array_key_exists($key, $value)) {
                $out[$key] = self::redact($child, $value[$key]);
            }
        }

        return $out;
    }

    private static function prepare(array $target, array $field, array $expected, array $args): array
    {
        $operations = $args['operations'] ?? null;
        if (! is_array($operations) || $operations === [] || count($operations) > 30) {
            self::fail('נדרשות 1–30 פעולות ACF ממוקדות.');
        }
        $desired = $expected['raw'];
        foreach ($operations as $operation) {
            if (! is_array($operation) || ! isset($operation['path']) || ! is_array($operation['path']) || count($operation['path']) > 16) {
                self::fail('נתיב שדה ACF אינו תקין.');
            }
            $desired = self::patch($field, $desired, array_values($operation['path']), $operation, 0);
        }
        self::bounded($desired);
        // Validate the entire candidate before ACF can execute any update hooks.
        $desired = self::validate($field, $desired, $expected['raw'], 0);
        self::assertNoSchemaSideEffects($field, $expected['raw']);
        self::assertNoSchemaSideEffects($field, $desired);
        self::nativeValidate($field, $desired);
        $related = self::related($target, $field, [$expected['raw'], $desired]);
        $proposal = [
            'purpose' => 'proposal', 'acf_id' => $expected['acf_id'], 'field_key' => $field['key'],
            'schema' => $expected['schema'], 'target' => $expected['target'], 'expected_version' => $args['expected']['version'],
            'desired' => $desired, 'related' => $related,
        ];
        $before = self::redact($field, $expected['raw']);
        $after = self::redact($field, $desired);
        $notes = self::notes($field);
        if (self::passwordValues($field, $expected['raw']) !== self::passwordValues($field, $desired)) {
            $notes[] = 'הפעולה תעדכן גם ערך סיסמה מוסתר; תוכנו לא יוצג בתצוגת האישור.';
        }

        return ['target' => $target, 'field_key' => $field['key'], 'before' => $before, 'after' => $after,
            'expected' => $args['expected'], 'prepared' => self::seal($proposal),
            'changed' => $desired !== $expected['raw'], 'notes' => $notes, 'writing_text' => self::writingText($field, $expected['raw'], $desired)];
    }

    private static function patch(array $field, $current, array $path, array $operation, int $depth)
    {
        if ($depth > 16 || Multioto_Agent_Acf_Schema::isProtected($field)) {
            self::fail('שדה זה מוגן.');
        }
        if ($path !== []) {
            $segment = array_shift($path);
            $type = $field['type'] ?? '';
            if (in_array($type, ['repeater', 'flexible_content'], true)) {
                if (! is_int($segment) || ! is_array($current) || ! array_key_exists($segment, $current) || $path === []) {
                    self::fail('יש לציין אינדקס שורה קיים ומפתח שדה יציב.');
                }
                $row = (array) $current[$segment];
                $key = array_shift($path);
                $child = self::child(self::children($field, $row), $key);
                $row[$key] = self::patch($child, $row[$key] ?? false, $path, $operation, $depth + 1);
                $current[$segment] = $row;

                return $current;
            }
            if (in_array($type, ['group', 'clone'], true)) {
                $child = self::child(self::children($field, $current), $segment);
                $current = is_array($current) ? $current : [];
                $current[$segment] = self::patch($child, $current[$segment] ?? false, $path, $operation, $depth + 1);

                return $current;
            }
            self::fail('הנתיב אינו תואם למבנה השדה.');
        }
        $op = $operation['op'] ?? '';
        if ($op === 'set') {
            if (! array_key_exists('value', $operation)) {
                self::fail('חסר ערך שדה.');
            }

            self::preserveProtected($field, $current, $operation['value']);

            return self::validate($field, $operation['value'], $current, $depth);
        }
        if ($op === 'clear') {
            $value = self::clearValue($field);

            self::preserveProtected($field, $current, $value);

            return self::validate($field, $value, $current, $depth);
        }
        if (! self::isList($field) || ! in_array($op, ['insert', 'remove', 'move'], true)) {
            self::fail('פעולת השדה אינה נתמכת.');
        }
        $list = is_array($current) ? array_values($current) : [];
        $index = $operation['index'] ?? null;
        if (! is_int($index) || $index < 0 || $index > count($list) || ($op !== 'insert' && $index === count($list))) {
            self::fail('אינדקס השורה אינו תקין.');
        }
        if ($op === 'insert') {
            if (! array_key_exists('value', $operation)) {
                self::fail('חסר ערך שורה.');
            }
            array_splice($list, $index, 0, [$operation['value']]);
        } elseif ($op === 'remove') {
            array_splice($list, $index, 1);
        } else {
            $to = $operation['to'] ?? null;
            if (! is_int($to) || $to < 0 || $to >= count($list)) {
                self::fail('מיקום היעד אינו תקין.');
            }
            $row = array_splice($list, $index, 1);
            array_splice($list, $to, 0, $row);
        }

        self::preserveProtected($field, $current, $list);

        return self::validate($field, $list, $current, $depth);
    }

    private static function validate(array $field, $value, $old, int $depth)
    {
        if ($depth > 16) {
            self::fail('מבנה השדה עמוק מדי.');
        }
        if ($value === $old) {
            return $value; // Preserve untouched opaque values and native raw storage types.
        }
        $type = (string) ($field['type'] ?? '');
        if (Multioto_Agent_Acf_Schema::isProtected($field) || in_array($type, self::LAYOUT_TYPES, true)) {
            self::fail('לא ניתן לשנות שדה מוגן או רכיב תצוגה.');
        }
        $empty = $value === null || $value === false || $value === '' || $value === [];
        if ($empty && ! empty($field['required'])) {
            self::fail('אי אפשר לרוקן שדה חובה.');
        }
        if (in_array($type, ['group', 'clone'], true)) {
            return self::validateChildren(self::children($field, $value), $value, is_array($old) ? $old : [], $depth);
        }
        if (in_array($type, ['repeater', 'flexible_content'], true)) {
            if ($empty) {
                $value = [];
            }
            self::listValue($value);
            self::countBounds($field, count($value));
            $out = [];
            $layoutCounts = [];
            foreach ($value as $index => $row) {
                if (! is_array($row)) {
                    self::fail('ערך השורה חייב להיות אובייקט שדות.');
                }
                $children = self::children($field, $row);
                $original = is_array($old) ? array_search($row, $old, true) : false;
                $prior = $original !== false ? $old[$original] : ($old[$index] ?? []);
                $out[$index] = self::validateChildren($children, $row, (array) $prior, $depth + 1, $type === 'flexible_content');
                if ($type === 'flexible_content') {
                    $layout = (string) $row['acf_fc_layout'];
                    $layoutCounts[$layout] = ($layoutCounts[$layout] ?? 0) + 1;
                }
            }
            foreach ((array) ($field['layouts'] ?? []) as $layout) {
                self::countBounds($layout, $layoutCounts[$layout['name']] ?? 0);
            }

            return $out;
        }
        if (in_array($type, ['text', 'textarea', 'password', 'wysiwyg', 'email', 'url', 'oembed'], true)) {
            if ($empty) {
                return '';
            }
            if (! is_string($value)) {
                self::fail('שדה הטקסט דורש מחרוזת.');
            }
            if (! empty($field['maxlength']) && self::length($value) > (int) $field['maxlength']) {
                self::fail('הטקסט ארוך מהמותר בשדה.');
            }
            if ($type === 'password') {
                return $value;
            }
            if ($type === 'email' && ! is_email($value)) {
                self::fail('כתובת האימייל אינה תקינה.');
            }
            if (in_array($type, ['url', 'oembed'], true)) {
                return self::url($value);
            }
            if ($type === 'wysiwyg') {
                return wp_kses_post($value);
            }

            return $type === 'textarea' ? sanitize_textarea_field($value) : sanitize_text_field($value);
        }
        if (in_array($type, ['number', 'range'], true)) {
            if ($empty && $type === 'number') {
                return '';
            }
            if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                self::fail('נדרש מספר תקין.');
            }
            $number = (float) $value;
            if (! is_finite($number)) {
                self::fail('המספר אינו תקין.');
            }
            foreach (['min', 'max'] as $bound) {
                if (isset($field[$bound]) && $field[$bound] !== '' && ($bound === 'min' ? $number < (float) $field[$bound] : $number > (float) $field[$bound])) {
                    self::fail('המספר מחוץ לטווח השדה.');
                }
            }
            if (! empty($field['step'])) {
                $steps = ($number - (float) ($field['min'] ?? 0)) / (float) $field['step'];
                if (abs($steps - round($steps)) > 0.000001) {
                    self::fail('המספר אינו תואם לקפיצות המותרות בשדה.');
                }
            }

            return (string) $value;
        }
        if ($type === 'true_false') {
            if (! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                self::fail('נדרש ערך אמת או שקר.');
            }

            return $value ? 1 : 0;
        }
        if (in_array($type, ['select', 'checkbox', 'radio', 'button_group'], true)) {
            $multiple = $type === 'checkbox' || ! empty($field['multiple']);
            $values = $multiple ? ($empty ? [] : $value) : [$value];
            self::listValue($values);
            $choices = self::choiceKeys((array) ($field['choices'] ?? []));
            foreach ($values as $choice) {
                if (! is_string($choice) && ! is_int($choice)) {
                    self::fail('אפשרות הבחירה אינה תקינה.');
                }
                if (! in_array((string) $choice, $choices, true) && ! self::allowsCustomChoice($field) && ! ($empty && ! empty($field['allow_null']))) {
                    self::fail('הערך אינו ברשימת האפשרויות המותרות.');
                }
            }

            return $multiple ? array_map('strval', $values) : ($empty ? '' : (string) $value);
        }
        if (in_array($type, ['image', 'file', 'gallery', 'relationship', 'post_object', 'page_link', 'taxonomy', 'user'], true)) {
            return self::validateReferences($field, $value);
        }
        if (in_array($type, ['date_picker', 'date_time_picker', 'time_picker'], true)) {
            if ($empty) {
                return '';
            }
            $format = ['date_picker' => 'Ymd', 'date_time_picker' => 'Y-m-d H:i:s', 'time_picker' => 'H:i:s'][$type];
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!'.$format, $value) : false;
            if (! $date || $date->format($format) !== $value) {
                self::fail('התאריך או השעה אינם בפורמט האחסון של ACF: '.$format);
            }

            return $value;
        }
        if ($type === 'color_picker') {
            if ($empty) {
                return '';
            }
            if (! is_string($value)) {
                self::fail('ערך הצבע אינו תקין.');
            }
            $valid = (bool) preg_match('/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/i', $value);
            if (! $valid && ! empty($field['enable_opacity']) && preg_match('/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(0(?:\.\d+)?|1(?:\.0+)?)\s*\)$/', $value, $parts)) {
                $valid = (int) $parts[1] <= 255 && (int) $parts[2] <= 255 && (int) $parts[3] <= 255;
            }
            if (! $valid) {
                self::fail('ערך הצבע אינו תקין.');
            }

            return $value;
        }
        if ($type === 'icon_picker') {
            if ($empty) {
                return '';
            }
            if (! is_array($value) || array_diff(array_keys($value), ['type', 'value']) || ! isset($value['type'], $value['value']) || ! in_array($value['type'], (array) ($field['tabs'] ?? ['dashicons', 'media_library', 'url']), true)) {
                self::fail('ערך האייקון או מקורו אינם תקינים.');
            }
            if ($value['type'] === 'dashicons') {
                if (! is_string($value['value']) || ! preg_match('/^dashicons-[a-z0-9-]+$/', $value['value'])) {
                    self::fail('נדרש שם Dashicon תקין.');
                }
            } elseif ($value['type'] === 'url') {
                if (! is_string($value['value'])) {
                    self::fail('נדרשת כתובת אייקון.');
                }
                $value['value'] = self::url($value['value']);
            } elseif ($value['type'] === 'media_library') {
                $value['value'] = self::validateReferences(['type' => 'image'], $value['value']);
            } else {
                self::fail('מקור האייקון החיצוני אינו נתמך.');
            }

            return $value;
        }
        if ($type === 'link') {
            if ($empty) {
                return '';
            }
            if (! is_array($value) || array_diff(array_keys($value), ['url', 'title', 'target']) || ! isset($value['url']) || ! is_string($value['url']) || ! is_string($value['title'] ?? '') || ! in_array($value['target'] ?? '', ['', '_blank'], true)) {
                self::fail('ערך הקישור אינו תקין.');
            }

            return ['url' => self::url($value['url']), 'title' => sanitize_text_field($value['title'] ?? ''), 'target' => $value['target'] ?? ''];
        }
        if ($type === 'google_map') {
            if ($empty) {
                return '';
            }
            if (! is_array($value) || ! isset($value['address'], $value['lat'], $value['lng']) || ! is_string($value['address']) || ! is_numeric($value['lat']) || ! is_numeric($value['lng']) || abs((float) $value['lat']) > 90 || abs((float) $value['lng']) > 180) {
                self::fail('נדרשים כתובת וקווי אורך ורוחב תקינים.');
            }
            $allowed = ['address', 'lat', 'lng', 'zoom', 'place_id', 'name', 'street_number', 'street_name', 'street_name_short', 'city', 'state', 'state_short', 'post_code', 'country', 'country_short'];
            if (array_diff(array_keys($value), $allowed)) {
                self::fail('פרטי מפה לא מוכרים.');
            }
            foreach ($value as $key => $item) {
                if (in_array($key, ['lat', 'lng', 'zoom'], true)) {
                    if (! is_numeric($item) || ! is_finite((float) $item)) {
                        self::fail('ערך מפה מספרי אינו תקין.');
                    }
                    $value[$key] = $key === 'zoom' ? (int) $item : (float) $item;
                } else {
                    if (! is_string($item)) {
                        self::fail('פרט מפה חייב להיות טקסט.');
                    }
                    $value[$key] = sanitize_text_field($item);
                }
            }

            return $value;
        }
        self::fail('זהו סוג שדה של הרחבה חיצונית שאינו ניתן לעריכה בטוחה.');
    }

    private static function preserveProtected(array $field, $old, $new): void
    {
        $before = self::protectedLeaves($field, $old);
        $after = self::protectedLeaves($field, $new);
        sort($before);
        sort($after);
        if ($before !== $after) {
            self::fail('הפעולה תסיר או תשנה שדה מוגן בתוך המבנה.');
        }
    }

    private static function protectedLeaves(array $field, $value, int $depth = 0): array
    {
        if ($depth > 16) {
            self::fail('מבנה שדה עמוק מדי.');
        }
        $type = $field['type'] ?? '';
        if (in_array($type, self::LAYOUT_TYPES, true)) {
            return [];
        }
        $definition = method_exists('Multioto_Agent_Acf_Schema', 'fieldDefinition') ? Multioto_Agent_Acf_Schema::fieldDefinition($field) : ['editable' => true];
        if (Multioto_Agent_Acf_Schema::isProtected($field) || empty($definition['editable'])) {
            return $value === false || $value === null || $value === '' || $value === [] ? [] : [$field['key'].':'.hash('sha256', self::json($value))];
        }
        $out = [];
        if (in_array($type, ['group', 'clone'], true)) {
            foreach (self::children($field, $value) as $child) {
                $out = array_merge($out, self::protectedLeaves($child, is_array($value) ? ($value[$child['key']] ?? false) : false, $depth + 1));
            }
        } elseif (in_array($type, ['repeater', 'flexible_content'], true)) {
            foreach ((array) $value as $row) {
                foreach (self::children($field, $row) as $child) {
                    $out = array_merge($out, self::protectedLeaves($child, $row[$child['key']] ?? false, $depth + 1));
                }
            }
        }

        return $out;
    }

    private static function validateChildren(array $children, $value, array $old, int $depth, bool $layout = false): array
    {
        if (! is_array($value)) {
            self::fail('נדרש אובייקט שדות עם מפתחות field_.');
        }
        $keys = array_column($children, 'key');
        if ($layout) {
            $keys[] = 'acf_fc_layout';
        }
        if (array_diff(array_keys($value), $keys)) {
            self::fail('מפתח שדה לא מוכר במבנה.');
        }
        $out = $layout ? ['acf_fc_layout' => $value['acf_fc_layout']] : [];
        foreach ($children as $child) {
            $key = $child['key'];
            if (in_array($child['type'] ?? '', self::LAYOUT_TYPES, true)) {
                continue;
            }
            if (! array_key_exists($key, $value)) {
                if (array_key_exists($key, $old) && $old[$key] !== false && $old[$key] !== null && $old[$key] !== '') {
                    self::fail('החלפת מבנה מחייבת לכלול את השדות הקיימים; לעדכון ממוקד יש להשתמש בנתיב.');
                }
                if (! empty($child['required'])) {
                    self::fail('חסר שדה חובה בשורה החדשה.');
                }

                continue;
            }
            $out[$key] = self::validate($child, $value[$key], $old[$key] ?? null, $depth + 1);
        }

        return $out;
    }

    private static function validateReferences(array $field, $value)
    {
        $type = $field['type'];
        $multiple = self::isList($field);
        $empty = $value === '' || $value === false || $value === null || $value === [];
        $values = $multiple ? ($empty ? [] : $value) : ($empty ? [] : [$value]);
        self::listValue($values);
        self::countBounds($field, count($values));
        $out = [];
        foreach ($values as $id) {
            if ($type === 'page_link' && is_string($id) && preg_match('~^https?://~i', $id)) {
                $out[] = self::url($id);

                continue;
            }
            if (! (is_int($id) || is_string($id) && ctype_digit($id)) || (int) $id < 1) {
                self::fail('קישור שדה דורש מזהה חיובי קיים.');
            }
            $id = (int) $id;
            if ($type === 'user') {
                $object = get_userdata($id);
                if (! $object || ! empty($field['role']) && ! array_intersect((array) $field['role'], (array) $object->roles)) {
                    self::fail('המשתמש אינו קיים או שאינו בתפקיד המותר לשדה.');
                }
            } elseif ($type === 'taxonomy') {
                $object = get_term($id, (string) ($field['taxonomy'] ?? ''));
                if (! $object || is_wp_error($object)) {
                    self::fail('מונח הטקסונומיה אינו קיים.');
                }
            } else {
                $object = get_post($id);
                if (! $object || in_array($object->post_status, ['trash', 'auto-draft'], true)) {
                    self::fail('הפריט המקושר אינו קיים או נמצא בפח.');
                }
                if (in_array($type, ['image', 'file', 'gallery'], true)) {
                    if ($object->post_type !== 'attachment' || ($type !== 'file' && strpos((string) $object->post_mime_type, 'image/') !== 0)) {
                        self::fail('המזהה אינו קובץ מדיה מתאים.');
                    }
                    if (! empty($field['mime_types'])) {
                        $extension = strtolower(pathinfo((string) get_attached_file($id), PATHINFO_EXTENSION));
                        if (! in_array($extension, array_map('trim', explode(',', strtolower($field['mime_types']))), true)) {
                            self::fail('סוג הקובץ אינו מותר בשדה.');
                        }
                    }
                    $metadata = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($id) : [];
                    foreach (['width', 'height'] as $dimension) {
                        foreach (['min', 'max'] as $bound) {
                            $limit = $field[$bound.'_'.$dimension] ?? '';
                            if ($limit !== '' && $limit !== 0 && $limit !== '0' && (! isset($metadata[$dimension]) || ($bound === 'min' ? $metadata[$dimension] < $limit : $metadata[$dimension] > $limit))) {
                                self::fail('מידות התמונה אינן תואמות להגדרות השדה.');
                            }
                        }
                    }
                } elseif (! empty($field['post_type']) && ! in_array($object->post_type, (array) $field['post_type'], true)) {
                    self::fail('סוג הפוסט אינו מותר בשדה.');
                }
                if (! empty($field['post_status']) && ! in_array($object->post_status, (array) $field['post_status'], true)) {
                    self::fail('מצב הפוסט אינו מותר בשדה.');
                }
            }
            if (in_array($id, $out, true)) {
                self::fail('אין להוסיף אותו מזהה פעמיים.');
            }
            $out[] = $id;
        }

        return $multiple ? $out : ($out[0] ?? false);
    }

    private static function apply(array $target, array $field, array $expected, array $desired, bool $restore): array
    {
        $before = $expected;
        $before['related'] = self::refreshRelated((array) ($expected['related'] ?? []));
        $before['terms'] = self::captureTerms($target, $field);
        self::assertCurrent($expected, $field);
        if ($restore) {
            foreach ((array) ($desired['related'] ?? []) as $key => $related) {
                if (! array_key_exists($key, (array) ($expected['related'] ?? []))) {
                    self::fail('צילום השחזור אינו מכסה את כל הקשרים המעורבים.');
                }
            }
        }
        if ($desired['raw'] === $before['raw'] && $desired['exists'] === $before['exists'] && ! $restore) {
            return ['target' => $target, 'field_key' => $field['key'], 'changed' => false, 'before' => self::seal($before), 'after' => self::seal($before)];
        }
        if (! $restore) {
            self::assertNoSchemaSideEffects($field, $desired['raw']);
            self::nativeValidate($field, $desired['raw']);
        }
        try {
            self::writeRecord($desired, $field, $restore);
            if ($restore) {
                self::restoreRelated($desired['related'] ?? []);
                self::restoreTerms($target, $desired['terms'] ?? []);
            }
            self::flush($target['acf_id'], $field);
            $after = self::capture($target, $field);
            $after['related'] = self::refreshRelated($before['related']);
            // ACF commonly persists numeric IDs as strings. Compare its normalized raw representation.
            if (self::canonicalValue($field, $after['raw']) !== self::canonicalValue($field, $desired['raw']) || ($restore && $after['exists'] !== $desired['exists'])) {
                self::fail('ACF לא שמר את הערך המבוקש במלואו.');
            }
            if ($restore && ($after['related'] !== $desired['related'] || $after['terms'] !== $desired['terms'])) {
                self::fail('שחזור הקשרים לא הושלם.');
            }

            return ['target' => $target, 'field_key' => $field['key'], 'changed' => true, 'before' => self::seal($before), 'after' => self::seal($after)];
        } catch (Throwable $error) {
            try {
                self::writeRecord($before, $field, true);
                self::restoreRelated($before['related']);
                self::restoreTerms($target, $before['terms']);
                self::assertCurrent($before, $field);
            } catch (Throwable $rollbackError) {
                self::fail('העדכון נכשל והשחזור האוטומטי לא הושלם. נדרשת בדיקה בלוח הבקרה.');
            }
            self::fail('העדכון לא הושלם; הערכים הקודמים שוחזרו.');
        }
    }

    private static function writeRecord(array $state, array $field, bool $disableRelations): void
    {
        $keepAttachmentParent = static function (): bool {
            return false;
        };
        if (function_exists('add_filter')) {
            add_filter('acf/connect_attachment_to_post', $keepAttachmentParent, PHP_INT_MAX, 3);
        }
        $oldGuard = function_exists('acf_get_data') ? acf_get_data('acf_doing_bidirectional_update') : false;
        if ($disableRelations && function_exists('acf_set_data')) {
            acf_set_data('acf_doing_bidirectional_update', true);
        }
        try {
            if ($state['exists'] || is_array($state['raw'])) {
                update_field($field['key'], wp_slash($state['raw']), $state['acf_id']);
                if (function_exists('acf_update_metadata')) {
                    if ($state['reference'] === null) {
                        acf_delete_metadata($state['acf_id'], $field['name'], true);
                    } else {
                        acf_update_metadata($state['acf_id'], $field['name'], wp_slash($state['reference']), true);
                    }
                }
            } else {
                delete_field($field['key'], $state['acf_id']);
                if (function_exists('acf_delete_metadata')) {
                    acf_delete_metadata($state['acf_id'], $field['name'], true);
                }
            }
            if ($disableRelations && isset($state['storage'])) {
                self::restoreStorage($state, $field);
            }
            self::flush($state['acf_id'], $field);
        } finally {
            if (function_exists('remove_filter')) {
                remove_filter('acf/connect_attachment_to_post', $keepAttachmentParent, PHP_INT_MAX);
            }
            if ($disableRelations && function_exists('acf_set_data')) {
                acf_set_data('acf_doing_bidirectional_update', $oldGuard);
            }
        }
    }

    private static function capture(array $target, array $field): array
    {
        $record = self::record($target['acf_id'], $field);
        $record['schema'] = self::fingerprint($field);
        $record['target'] = self::targetKey($target);
        $record['storage'] = self::storage($target['acf_id'], $field, $record['raw']);
        $record['related'] = self::related($target, $field, [$record['raw']]);
        $record['terms'] = self::captureTerms($target, $field);
        self::bounded($record);

        return $record;
    }

    private static function record($acfId, array $field): array
    {
        if (! function_exists('acf_get_metadata')) {
            self::fail('גרסת ACF אינה מאפשרת צילום שחזור מדויק.');
        }
        self::flush($acfId, $field);

        return ['acf_id' => $acfId, 'field_key' => $field['key'], 'raw' => get_field($field['key'], $acfId, false),
            'exists' => acf_get_metadata($acfId, $field['name']) !== null,
            'reference' => acf_get_metadata($acfId, $field['name'], true), 'schema' => self::fingerprint($field)];
    }

    /** Capture exact ACF metadata presence, including native nested field references. */
    private static function storage($acfId, array $field, $value, int $depth = 0): array
    {
        if ($depth > 16) {
            self::fail('מבנה אחסון ACF עמוק מדי.');
        }
        $name = (string) $field['name'];
        $out = [$name => ['value' => acf_get_metadata($acfId, $name), 'reference' => acf_get_metadata($acfId, $name, true)]];
        $type = $field['type'] ?? '';
        if (in_array($type, ['group', 'clone'], true)) {
            $prepared = $field;
            $adapter = function_exists('acf_get_field_type') ? acf_get_field_type($type) : null;
            if (is_object($adapter) && is_callable([$adapter, 'prepare_field_for_db'])) {
                $prepared = $adapter->prepare_field_for_db($field);
            } elseif ($type === 'group') {
                foreach ($prepared['sub_fields'] as &$child) {
                    $child['name'] = $name.'_'.($child['_name'] ?? $child['name']);
                }
                unset($child);
            }
            foreach ((array) ($prepared['sub_fields'] ?? []) as $child) {
                if (in_array($child['type'] ?? '', self::LAYOUT_TYPES, true)) {
                    continue;
                }
                $out += self::storage($acfId, $child, is_array($value) ? ($value[$child['key']] ?? false) : false, $depth + 1);
            }
        } elseif (in_array($type, ['repeater', 'flexible_content'], true)) {
            foreach ((array) $value as $index => $row) {
                foreach (self::children($field, $row) as $child) {
                    if (in_array($child['type'] ?? '', self::LAYOUT_TYPES, true)) {
                        continue;
                    }
                    $child['name'] = $name.'_'.$index.'_'.($child['_name'] ?? $child['name']);
                    $out += self::storage($acfId, $child, $row[$child['key']] ?? false, $depth + 1);
                }
            }
        }
        ksort($out);

        return $out;
    }

    private static function restoreStorage(array $state, array $field): void
    {
        if (! function_exists('acf_update_metadata') || ! function_exists('acf_delete_metadata')) {
            self::fail('ACF אינו מאפשר שחזור אחסון מדויק.');
        }
        $current = self::storage($state['acf_id'], $field, get_field($field['key'], $state['acf_id'], false));
        foreach (array_unique(array_merge(array_keys($current), array_keys($state['storage']))) as $name) {
            foreach (['value' => false, 'reference' => true] as $part => $hidden) {
                $value = $state['storage'][$name][$part] ?? null;
                if ($value === null) {
                    acf_delete_metadata($state['acf_id'], $name, $hidden);
                } else {
                    acf_update_metadata($state['acf_id'], $name, wp_slash($value), $hidden);
                }
            }
        }
    }

    private static function related(array $target, array $field, array $values): array
    {
        $out = [];
        self::walk($field, $values, static function (array $child, array $childValues) use (&$out): void {
            if (empty($child['bidirectional']) || empty($child['bidirectional_target'])) {
                return;
            }
            $prefix = $child['type'] === 'user' ? 'user_' : ($child['type'] === 'taxonomy' ? 'term_' : '');
            foreach ((array) $child['bidirectional_target'] as $key) {
                $inverse = acf_get_field($key);
                if (! is_array($inverse) || Multioto_Agent_Acf_Schema::isProtected($inverse) || ! in_array($inverse['type'], ['relationship', 'post_object', 'user', 'taxonomy'], true)) {
                    self::fail('הקשר ההדדי מפנה לשדה שלא ניתן לצלם ולשחזר.');
                }
                foreach ($childValues as $value) {
                    foreach ((array) $value as $id) {
                        if (! is_numeric($id) || (int) $id < 1) {
                            continue;
                        }
                        $acfId = $prefix === '' ? (int) $id : $prefix.(int) $id;
                        $inverseTarget = Multioto_Agent_Acf_Schema::resolveTarget(['context' => $prefix === 'user_' ? 'user' : ($prefix === 'term_' ? 'term' : 'post'), 'id' => (int) $id]);
                        $inverse = self::findField($inverseTarget, (string) $key);
                        $out[(string) $acfId.':'.$key] = self::record($acfId, $inverse);
                        if (count($out) > 200) {
                            self::fail('מספר הקשרים גדול מדי לפעולה אחת.');
                        }
                    }
                }
            }
        });
        ksort($out);

        return $out;
    }

    private static function walk(array $field, array $values, callable $callback, int $depth = 0): void
    {
        if ($depth > 16) {
            self::fail('מבנה שדות עמוק מדי.');
        }
        $callback($field, $values);
        $type = $field['type'] ?? '';
        if (in_array($type, ['group', 'clone'], true)) {
            foreach (self::children($field, []) as $child) {
                $childValues = [];
                foreach ($values as $value) {
                    $childValues[] = is_array($value) ? ($value[$child['key']] ?? false) : false;
                }
                self::walk($child, $childValues, $callback, $depth + 1);
            }
        } elseif (in_array($type, ['repeater', 'flexible_content'], true)) {
            foreach ($values as $value) {
                foreach ((array) $value as $row) {
                    foreach (self::children($field, $row) as $child) {
                        self::walk($child, [(array) $row ? ($row[$child['key']] ?? false) : false], $callback, $depth + 1);
                    }
                }
            }
        }
    }

    private static function walkSchema(array $field, callable $callback, int $depth = 0): void
    {
        if ($depth > 16) {
            self::fail('סכמת השדה עמוקה מדי.');
        }
        $callback($field);
        foreach ((array) ($field['sub_fields'] ?? []) as $child) {
            self::walkSchema($child, $callback, $depth + 1);
        }
        foreach ((array) ($field['layouts'] ?? []) as $layout) {
            foreach ((array) ($layout['sub_fields'] ?? []) as $child) {
                self::walkSchema($child, $callback, $depth + 1);
            }
        }
    }

    private static function refreshRelated(array $related): array
    {
        foreach ($related as $key => $record) {
            $inverseTarget = Multioto_Agent_Acf_Schema::resolveTarget(self::targetFor($record['acf_id']));
            $field = self::findField($inverseTarget, $record['field_key']);
            if (! is_array($field)) {
                self::fail('הגדרת קשר הדדי השתנתה.');
            }
            $related[$key] = self::record($record['acf_id'], $field);
        }

        return $related;
    }

    private static function restoreRelated(array $related): void
    {
        foreach ($related as $record) {
            $inverseTarget = Multioto_Agent_Acf_Schema::resolveTarget(self::targetFor($record['acf_id']));
            $field = self::findField($inverseTarget, $record['field_key']);
            if (! is_array($field)) {
                self::fail('לא ניתן לשחזר קשר ללא הגדרת שדה.');
            }
            self::writeRecord($record, $field, true);
        }
    }

    private static function captureTerms(array $target, array $field): array
    {
        $out = [];
        self::walkSchema($field, static function (array $child) use ($target, &$out): void {
            if (($child['type'] ?? '') !== 'taxonomy' || empty($child['save_terms'])) {
                return;
            }
            if (($target['context'] ?? '') !== 'post') {
                self::fail('סנכרון מונחים של ACF זמין רק לפוסטים.');
            }
            $taxonomy = (string) $child['taxonomy'];
            $terms = wp_get_object_terms((int) $target['id'], $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($terms)) {
                self::fail('לא ניתן לצלם את שיוך המונחים.');
            }
            $out[$taxonomy] = array_map('intval', (array) $terms);
            sort($out[$taxonomy]);
        });
        ksort($out);

        return $out;
    }

    private static function restoreTerms(array $target, array $terms): void
    {
        foreach ($terms as $taxonomy => $ids) {
            $result = wp_set_object_terms((int) $target['id'], $ids, $taxonomy, false);
            if (is_wp_error($result)) {
                self::fail('שחזור שיוך מונחים נכשל.');
            }
        }
    }

    private static function assertCurrent(array $expected, array $field): void
    {
        $record = self::record($expected['acf_id'], $field);
        foreach (['raw', 'exists', 'reference'] as $key) {
            if ($record[$key] !== $expected[$key]) {
                self::fail('השדה השתנה מאז ההצעה. יש לקרוא אותו מחדש לפני אישור.');
            }
        }
        if (isset($expected['storage']) && self::storage($expected['acf_id'], $field, $record['raw']) !== $expected['storage']) {
            self::fail('אחסון השדה השתנה מאז ההצעה.');
        }
        if (self::refreshRelated($expected['related'] ?? []) !== ($expected['related'] ?? [])) {
            self::fail('קשר הדדי השתנה מאז ההצעה.');
        }
        $target = ($expected['target'] ?? self::targetFor($expected['acf_id'])) + ['acf_id' => $expected['acf_id']];
        if (self::captureTerms($target, $field) !== ($expected['terms'] ?? [])) {
            self::fail('שיוך המונחים השתנה מאז ההצעה.');
        }
    }

    private static function targetFor($acfId): array
    {
        if (is_numeric($acfId)) {
            return ['context' => 'post', 'id' => (int) $acfId, 'acf_id' => $acfId];
        }

        return ['context' => strpos((string) $acfId, 'user_') === 0 ? 'user' : (strpos((string) $acfId, 'term_') === 0 ? 'term' : 'options'), 'id' => (int) substr((string) $acfId, 5), 'acf_id' => $acfId];
    }

    private static function targetKey(array $target): array
    {
        return ['context' => $target['context'], 'id' => $target['id'] ?? null, 'options_page' => $target['options_page'] ?? null];
    }

    private static function assertBound(array $state, array $target, array $field): void
    {
        if (($state['target'] ?? null) !== self::targetKey($target) || ($state['acf_id'] ?? null) !== $target['acf_id'] || ($state['field_key'] ?? '') !== $field['key'] || ! hash_equals(self::fingerprint($field), (string) ($state['schema'] ?? ''))) {
            self::fail('הצילום אינו תואם לרשומה או שהגדרת השדה השתנתה.');
        }
    }

    private static function findField(array $target, string $key): array
    {
        foreach (Multioto_Agent_Acf_Schema::fieldsForTarget($target) as $field) {
            if (($field['key'] ?? '') === $key && ! Multioto_Agent_Acf_Schema::isProtected($field) && ! in_array($field['type'] ?? '', self::LAYOUT_TYPES, true)) {
                return $field;
            }
        }
        self::fail('השדה אינו זמין לרשומה זו.');
    }

    private static function children(array $field, $value): array
    {
        if (($field['type'] ?? '') === 'flexible_content') {
            foreach ((array) ($field['layouts'] ?? []) as $layout) {
                if (($layout['name'] ?? '') === ($value['acf_fc_layout'] ?? null)) {
                    return (array) ($layout['sub_fields'] ?? []);
                }
            }
            self::fail('פריסת התוכן הגמיש אינה מוכרת.');
        }

        return (array) ($field['sub_fields'] ?? []);
    }

    private static function child(array $children, $key): array
    {
        if (! is_string($key)) {
            self::fail('יש להשתמש במפתח השדה היציב.');
        }
        foreach ($children as $child) {
            if (($child['key'] ?? '') === $key) {
                return $child;
            }
        }
        self::fail('מפתח השדה אינו קיים במבנה הנוכחי.');
    }

    private static function isList(array $field): bool
    {
        return in_array($field['type'] ?? '', ['repeater', 'flexible_content', 'gallery', 'relationship', 'checkbox'], true)
            || ! empty($field['multiple'])
            || (($field['type'] ?? '') === 'taxonomy' && in_array($field['field_type'] ?? 'checkbox', ['checkbox', 'multi_select'], true));
    }

    private static function listValue($value): void
    {
        if (! is_array($value) || ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) || count($value) > self::MAX_ROWS) {
            self::fail('נדרשת רשימה רציפה של עד 200 ערכים.');
        }
    }

    private static function countBounds(array $field, int $count): void
    {
        $min = (int) ($field['min'] ?? 0);
        $max = (int) ($field['max'] ?? 0);
        if ($count < $min || ($max > 0 && $count > $max)) {
            self::fail('מספר הפריטים חורג מהגדרות השדה.');
        }
    }

    private static function choiceKeys(array $choices): array
    {
        $keys = [];
        foreach ($choices as $key => $label) {
            $keys = array_merge($keys, is_array($label) ? self::choiceKeys($label) : [(string) $key]);
        }

        return $keys;
    }

    private static function url(string $value): string
    {
        $url = esc_url_raw($value, ['http', 'https', 'mailto', 'tel']);
        if ($url === '' || $url !== $value) {
            self::fail('כתובת הקישור אינה תקינה.');
        }

        return $url;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private static function clearValue(array $field)
    {
        if (($field['type'] ?? '') === 'true_false') {
            return 0;
        }
        if (self::isList($field)) {
            return [];
        }
        if (in_array($field['type'] ?? '', ['group', 'clone'], true)) {
            $out = [];
            foreach (self::children($field, []) as $child) {
                if (! in_array($child['type'] ?? '', self::LAYOUT_TYPES, true)) {
                    $out[$child['key']] = self::clearValue($child);
                }
            }

            return $out;
        }

        return '';
    }

    /** Compare the native stored meaning; ACF loads empty repeaters as false, not []. */
    private static function canonicalValue(array $field, $value)
    {
        if (($field['type'] ?? '') === 'true_false') {
            return $value ? '1' : '0';
        }
        if ($value === false || $value === null || $value === '' || $value === []) {
            return null;
        }
        $type = $field['type'] ?? '';
        if (is_array($value) && in_array($type, ['group', 'clone'], true)) {
            $out = [];
            foreach (self::children($field, $value) as $child) {
                $out[$child['key']] = self::canonicalValue($child, $value[$child['key']] ?? false);
            }
            ksort($out);

            return $out;
        }
        if (is_array($value) && in_array($type, ['repeater', 'flexible_content'], true)) {
            $out = [];
            foreach ($value as $row) {
                $entry = isset($row['acf_fc_layout']) ? ['acf_fc_layout' => $row['acf_fc_layout']] : [];
                foreach (self::children($field, $row) as $child) {
                    $entry[$child['key']] = self::canonicalValue($child, $row[$child['key']] ?? false);
                }
                ksort($entry);
                $out[] = $entry;
            }

            return $out;
        }
        if (is_array($value) && ! self::isList($field)) {
            ksort($value);
        }

        return self::comparable($value);
    }

    private static function comparable($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::comparable($item);
            }

            return $value;
        }

        return is_int($value) || is_float($value) || is_bool($value) ? (string) $value : $value;
    }

    private static function fingerprint(array $field): string
    {
        unset($field['value']);

        return hash('sha256', self::json($field));
    }

    private static function flush($acfId, array $field): void
    {
        if (function_exists('acf_flush_value_cache')) {
            acf_flush_value_cache($acfId, $field['name']);
        }
    }

    private static function notes(array $field): array
    {
        $notes = [];
        self::walkSchema($field, static function (array $child) use (&$notes): void {
            if (($child['type'] ?? '') === 'password') {
                $notes['password'] = 'ערכי סיסמה מוסתרים ונשמרים רק בצילום מוצפן לצורך שחזור.';
            }
            if (! empty($child['bidirectional'])) {
                $notes['bidirectional'] = 'גם הקשרים ההדדיים המקושרים יישמרו וייכללו בשחזור.';
            }
            if (! empty($child['save_terms'])) {
                $notes['terms'] = 'גם שיוך המונחים המקושר ייכלל בשחזור.';
            }
        });

        return array_values($notes);
    }

    /** Honor native and site-defined ACF validation without returning secret-bearing errors. */
    private static function allowsCustomChoice(array $field): bool
    {
        if (! empty($field['save_custom']) || ! empty($field['save_other_choice']) || ! empty($field['save_options'])) {
            return false;
        }

        return ! empty($field['allow_custom']) || ! empty($field['other_choice']) || ! empty($field['create_options']);
    }

    /** Choice creation that changes the shared field schema needs its own administrative workflow. */
    private static function assertNoSchemaSideEffects(array $field, $value): void
    {
        self::walk($field, [$value], static function (array $child, array $values): void {
            if (empty($child['save_custom']) && empty($child['save_other_choice']) && empty($child['save_options'])) {
                return;
            }
            $choices = self::choiceKeys((array) ($child['choices'] ?? []));
            foreach ($values as $value) {
                foreach ((array) $value as $choice) {
                    if ($choice !== '' && $choice !== false && $choice !== null && ! in_array((string) $choice, $choices, true)) {
                        self::fail('העדכון ישנה גם את רשימת האפשרויות המשותפת של ACF. יש להסדיר אפשרות זו בלוח הבקרה לפני עריכת הערך.');
                    }
                }
            }
        });
    }

    private static function nativeValidate(array $field, $value): void
    {
        if (! function_exists('acf_validate_value')) {
            return;
        }
        if (function_exists('acf_reset_validation_errors')) {
            acf_reset_validation_errors();
        }
        $valid = acf_validate_value($value, $field, 'acf['.$field['key'].']');
        $errors = function_exists('acf_get_validation_errors') ? acf_get_validation_errors() : [];
        if (! $valid || ! empty($errors)) {
            self::fail('הערך אינו עומד בכללי האימות של השדה באתר.');
        }
    }

    /** Bill only added/changed textual leaf values; moving existing rows costs no writing. */
    private static function passwordValues(array $field, $value): array
    {
        $out = [];
        self::walk($field, [$value], static function (array $child, array $values) use (&$out): void {
            if (($child['type'] ?? '') === 'password') {
                foreach ($values as $item) {
                    $out[] = $child['key'].':'.hash('sha256', self::json($item));
                }
            }
        });
        sort($out);

        return $out;
    }

    private static function writingText(array $field, $before, $after): string
    {
        $old = self::textLeaves($field, $before);
        $new = self::textLeaves($field, $after);
        foreach ($old as $text) {
            $index = array_search($text, $new, true);
            if ($index !== false) {
                unset($new[$index]);
            }
        }

        return implode("\n", $new);
    }

    private static function textLeaves(array $field, $value, int $depth = 0): array
    {
        if ($depth > 16 || Multioto_Agent_Acf_Schema::isProtected($field)) {
            return [];
        }
        $type = $field['type'] ?? '';
        if (in_array($type, ['text', 'textarea', 'wysiwyg'], true)) {
            return is_string($value) && $value !== '' ? [$value] : [];
        }
        $out = [];
        if (in_array($type, ['group', 'clone'], true)) {
            foreach (self::children($field, $value) as $child) {
                $out = array_merge($out, self::textLeaves($child, is_array($value) ? ($value[$child['key']] ?? false) : false, $depth + 1));
            }
        } elseif (in_array($type, ['repeater', 'flexible_content'], true)) {
            foreach ((array) $value as $row) {
                foreach (self::children($field, $row) as $child) {
                    $out = array_merge($out, self::textLeaves($child, $row[$child['key']] ?? false, $depth + 1));
                }
            }
        }

        return $out;
    }

    private static function seal(array $payload): array
    {
        if (! function_exists('openssl_encrypt') || ! function_exists('wp_salt')) {
            self::fail('אין מנגנון הצפנה זמין לצילום השחזור.');
        }
        $plain = self::json($payload);
        self::bounded($payload);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag, 'multioto-acf-v1');
        if ($cipher === false) {
            self::fail('הצפנת צילום השחזור נכשלה.');
        }

        return ['version' => hash_hmac('sha256', $plain, wp_salt('auth')), 'token' => base64_encode($iv.$tag.$cipher)];
    }

    private static function open(array $snapshot): array
    {
        $token = $snapshot['token'] ?? null;
        if (! is_string($token) || strlen($token) > self::LIMIT * 2 || ! isset($snapshot['version']) || ! is_string($snapshot['version'])) {
            self::fail('צילום ACF חסר או לא תקין.');
        }
        $bytes = base64_decode($token, true);
        if ($bytes === false || strlen($bytes) < 29 || (! function_exists('openssl_decrypt') || ! function_exists('wp_salt'))) {
            self::fail('צילום ACF אינו תקין.');
        }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'multioto-acf-v1');
        if ($plain === false || ! hash_equals(hash_hmac('sha256', $plain, wp_salt('auth')), $snapshot['version'])) {
            self::fail('אימות צילום ACF נכשל.');
        }
        $payload = json_decode($plain, true);
        if (! is_array($payload)) {
            self::fail('תוכן צילום ACF אינו תקין.');
        }

        return $payload;
    }

    private static function bounded($value): void
    {
        if (strlen(self::json($value)) > self::LIMIT) {
            self::fail('השדה גדול מדי לשינוי אחד; יש לפצל את התוכן בלוח הבקרה.');
        }
    }

    private static function json($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION, 32);
        if ($json === false) {
            self::fail('מבנה השדה אינו תקין.');
        }

        return $json;
    }

    private static function fail(string $message): void
    {
        throw new Multioto_Agent_Rpc_Error(-32602, $message);
    }
}
