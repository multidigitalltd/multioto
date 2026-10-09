<?php

namespace App\Services\SiteAgent\Evaluation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Isolated companion-plugin contracts for evaluation, never a live site client.
 * Reads expose fixture facts; writes require matching snapshots and alter only
 * the supplied world. The token registry models scope/CAS, not production crypto.
 */
final class EvaluationAdvancedWorld
{
    private array $tokens = [];

    public function seed(array &$state): void
    {
        $this->tokens = [];
        $state['settings'] = ($state['settings'] ?? []) + ['blogname' => 'חנות הדוגמה', 'blogdescription' => 'מוצרים ושירותים',
            'timezone_string' => 'Asia/Jerusalem', 'gmt_offset' => 3, 'date_format' => 'd/m/Y', 'time_format' => 'H:i',
            'show_on_front' => 'page', 'page_on_front' => 43, 'page_for_posts' => 0, 'posts_per_page' => 10, 'start_of_week' => 0];
        $state['seo'] = [43 => ['provider' => 'yoast', 'title' => 'החנות שלנו', 'description' => 'מוצרים נהדרים']];
        $state['optimole'] = ['active' => true, 'connected' => true, 'quality' => 80, 'autoquality' => 'enabled', 'lazyload' => 'enabled',
            'image_replacer' => 'enabled', 'lazyload_placeholder' => 'enabled', 'retina_images' => 'enabled', 'resize_smart' => 'enabled', 'native_lazyload' => 'disabled'];
        $state['cct'] = ['houses' => [301 => ['title' => 'בית ליד הים', 'price' => 2000000, 'available' => true, 'cct_status' => 'publish']]];
        $state['acf'] = ['post' => [43 => ['field_heading' => 'ברוכים הבאים', 'field_score' => 5,
            'field_faq' => [['field_question' => 'מי אנחנו?', 'field_answer' => 'צוות האתר']],
            'field_sections' => [['acf_fc_layout' => 'hero', 'field_title' => 'הסיפור שלנו']],
            'field_contact' => ['field_phone' => '03-1234567', 'field_email' => 'info@example.test'],
            'field_gallery' => [90], 'field_api_key' => 'evaluation-private-placeholder']],
            'options' => ['site-options' => ['field_footer' => 'כל הזכויות שמורות']]];
        $state['learndash'] = ['direct' => [5 => [201 => false]], 'groups' => [5 => [211 => false]], 'progress' => [5 => [201 => ['completed' => 0, 'total' => 3]]]];
        $state['category_sales'] = [];
    }

    public function supportedTools(): array
    {
        return ['wp_site_settings_get', 'wp_site_settings_update', 'wp_seo_get', 'wp_seo_update', 'wp_optimole_get', 'wp_optimole_update',
            'jet_cct_types', 'jet_cct_list', 'jet_cct_get', 'jet_cct_create', 'jet_cct_update',
            'wp_acf_options_pages', 'wp_acf_schema', 'wp_acf_get', 'wp_acf_prepare', 'wp_acf_update',
            'ld_capabilities', 'ld_courses_list', 'ld_course_get', 'ld_student_course_get', 'ld_groups_list', 'ld_group_get',
            'ld_membership_get', 'ld_membership_prepare', 'ld_membership_apply', 'ld_membership_revert',
            'wc_category_sale_get', 'wc_category_sale_prepare', 'wc_category_sale_apply', 'wc_category_sale_revert'];
    }

    public function isWrite(string $name): bool
    {
        return in_array($name, ['wp_site_settings_update', 'wp_seo_update', 'wp_optimole_update', 'jet_cct_create', 'jet_cct_update',
            'wp_acf_update', 'ld_membership_apply', 'ld_membership_revert', 'wc_category_sale_apply', 'wc_category_sale_revert'], true);
    }

    public function handle(string $name, array $args, array &$state): ?array
    {
        if (! in_array($name, $this->supportedTools(), true)) {
            return null;
        }
        if (str_starts_with($name, 'wp_acf_')) {
            return $this->acf($name, $args, $state);
        }
        if (str_starts_with($name, 'ld_')) {
            return $this->learnDash($name, $args, $state);
        }
        if (str_starts_with($name, 'wc_category_sale_')) {
            return $this->categorySale($name, $args, $state);
        }
        if (str_starts_with($name, 'jet_cct_')) {
            return $this->cct($name, $args, $state);
        }
        if (str_starts_with($name, 'wp_site_settings_')) {
            if ($name === 'wp_site_settings_get') {
                return ['label' => 'הגדרות האתר', 'values' => $state['settings']];
            }
            $values = $this->values($args, array_keys($state['settings']));
            foreach ($values as $key => $value) {
                if (in_array($key, ['page_on_front', 'page_for_posts'], true)) {
                    $this->require(is_int($value) && $value >= 0 && ($value === 0 || ($state['content'][$value]['type'] ?? null) === 'page'), 'נדרש מזהה עמוד קיים.');
                } elseif ($key === 'show_on_front') {
                    $this->require(in_array($value, ['page', 'posts'], true), 'מצב עמוד הבית אינו תקין.');
                } elseif (in_array($key, ['posts_per_page', 'start_of_week'], true)) {
                    $this->require(is_int($value) && $value >= ($key === 'posts_per_page' ? 1 : 0) && $value <= ($key === 'posts_per_page' ? 100 : 6), 'הערך המספרי אינו תקין.');
                } elseif ($key === 'gmt_offset') {
                    $this->require((is_int($value) || is_float($value)) && abs($value) <= 14, 'הפרש השעות אינו תקין.');
                } else {
                    $this->require(is_string($value), 'נדרש ערך טקסט.');
                    if ($key === 'timezone_string') {
                        $this->require(in_array($value, DateTimeZone::listIdentifiers(), true), 'אזור הזמן אינו תקין.');
                    }
                }
            }
            $result = $this->change($state['settings'], $values, $args['expected'] ?? []);

            return ['label' => 'הגדרות האתר'] + $result;
        }
        if (str_starts_with($name, 'wp_seo_')) {
            $id = $this->id($args, 'id');
            $this->require(isset($state['content'][$id]) || isset($state['products'][$id]), 'פריט התוכן אינו קיים.');
            $record = $state['seo'][$id] ?? ['provider' => 'yoast', 'title' => null, 'description' => null];
            $this->require(! isset($args['provider']) || $args['provider'] === $record['provider'], 'ספק SEO אינו תואם.');
            $identity = ['id' => $id, 'label' => $state['content'][$id]['title'] ?? $state['products'][$id]['name'], 'provider' => $record['provider']];
            if ($name === 'wp_seo_get') {
                return $identity + ['values' => array_intersect_key($record, array_flip(['title', 'description']))];
            }
            $values = $this->values($args, ['title', 'description']);
            foreach ($values as $value) {
                $this->require($value === null || is_string($value), 'ערך SEO חייב להיות טקסט או null.');
            }

            $result = $this->change($record, $values, $args['expected'] ?? []);
            $state['seo'][$id] = $record;

            return $identity + $result;
        }
        $record = &$state['optimole'];
        $allowed = array_values(array_diff(array_keys($record), ['active', 'connected']));
        if ($name === 'wp_optimole_get') {
            return ['id' => 1, 'label' => 'Optimole', 'active' => $record['active'], 'connected' => $record['connected'],
                'editable_fields' => $allowed, 'values' => array_intersect_key($record, array_flip($allowed))];
        }
        $this->require($record['active'] && $record['connected'], 'Optimole אינו מחובר.');
        $values = $this->values($args, $allowed);
        foreach ($values as $key => $value) {
            $this->require($key === 'quality' ? is_int($value) && $value >= 50 && $value <= 100 : in_array($value, ['enabled', 'disabled'], true), 'ערך Optimole אינו נתמך.');
        }

        return ['id' => 1, 'label' => 'Optimole'] + $this->change($record, $values, $args['expected'] ?? []);
    }

    private function cct(string $name, array $args, array &$state): array
    {
        $fields = [['key' => 'title', 'label' => 'כותרת', 'type' => 'text', 'required' => true, 'writable' => true],
            ['key' => 'price', 'label' => 'מחיר', 'type' => 'number', 'required' => false, 'writable' => true, 'min' => 0],
            ['key' => 'available', 'label' => 'זמין', 'type' => 'switcher', 'required' => false, 'writable' => true],
            ['key' => 'private_token', 'label' => 'מפתח פרטי', 'type' => 'text', 'required' => false, 'writable' => false]];
        if ($name === 'jet_cct_types') {
            return ['available' => true, 'types' => [['type' => 'houses', 'label' => 'בתים', 'writable' => true, 'create_supported' => true, 'fields' => $fields]]];
        }
        $this->require(($args['type'] ?? null) === 'houses', 'סוג CCT לא מוכר.');
        if ($name === 'jet_cct_list') {
            $filters = $args['filters'] ?? [];
            $this->require(is_array($filters) && array_diff(array_keys($filters), ['title', 'price', 'available', 'cct_status']) === [], 'מסנן CCT לא מוכר.');
            if ($filters !== []) {
                $filters = $this->cctValues(['values' => $filters]);
            }
            $rows = [];
            foreach ($state['cct']['houses'] as $id => $values) {
                $matches = true;
                foreach ($filters as $key => $filter) {
                    $value = $values[$key] ?? null;
                    $matches = $matches && ($key === 'price' && $filter !== null && $value !== null
                        ? (float) $value === (float) $filter : $value === $filter);
                }
                if ($matches) {
                    $rows[] = ['type' => 'houses', 'id' => $id, 'label' => $values['title'], 'values' => $values];
                }
            }

            return $this->page($rows, $args, 'items') + ['type' => 'houses'];
        }
        if ($name === 'jet_cct_create') {
            $values = $this->cctValues($args);
            $this->require(isset($values['title']) && trim($values['title']) !== '', 'חסרה כותרת.');
            $id = max(array_keys($state['cct']['houses'])) + 1;
            $values += ['cct_status' => 'draft', 'available' => true, 'price' => 0];
            $state['cct']['houses'][$id] = $values;

            return ['type' => 'houses', 'id' => $id, 'label' => $values['title'], 'values' => $values, 'before' => [], 'after' => $values, 'created' => true, 'changed' => true];
        }
        $id = $this->id($args, 'id');
        $this->require(isset($state['cct']['houses'][$id]), 'רשומת CCT אינה קיימת.');
        $record = &$state['cct']['houses'][$id];
        $identity = ['type' => 'houses', 'id' => $id, 'label' => $record['title']];

        return $name === 'jet_cct_get' ? $identity + ['values' => $record]
            : $identity + $this->change($record, $this->cctValues($args), $args['expected'] ?? []);
    }

    private function cctValues(array $args): array
    {
        $values = $this->values($args, ['title', 'price', 'available', 'cct_status']);
        foreach ($values as $key => $value) {
            $valid = match ($key) {
                'title' => is_string($value) && $value !== '',
                'price' => $value === null || (! is_bool($value) && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0),
                'available' => in_array($value, [null, true, false, 'true', 'false'], true),
                'cct_status' => in_array($value, ['publish', 'draft'], true),
            };
            $this->require($valid, 'ערך שדה CCT אינו תקין.');
            // JetEngine's native adapter accepts bool/string true/false, never
            // numeric 0/1. The simulated state uses canonical booleans.
            if ($key === 'available' && $value !== null) {
                $values[$key] = in_array($value, [true, 'true'], true);
            }
        }

        return $values;
    }

    private function acf(string $name, array $args, array &$state): array
    {
        if ($name === 'wp_acf_options_pages') {
            return ['pages' => [['options_page' => 'site-options', 'label' => 'אפשרויות האתר', 'acf_id' => 'options']]];
        }
        $context = $args['context'] ?? null;
        $key = $context === 'options' ? ($args['options_page'] ?? null) : ($args['id'] ?? null);
        $this->require(in_array($context, ['post', 'options'], true) && isset($state['acf'][$context][$key]), 'מיקום ACF אינו קיים בעולם הבדיקה.');
        $target = ['context' => $context, 'id' => $context === 'post' ? $key : null, 'options_page' => $context === 'options' ? $key : null,
            'acf_id' => $key, 'label' => $context === 'post' ? ($state['content'][$key]['title'] ?? 'דף הבית') : 'אפשרויות האתר'];
        $fields = $this->acfFields($context);
        if (isset($args['field_key'])) {
            $fields = array_values(array_filter($fields, fn (array $field): bool => $field['key'] === $args['field_key']));
            $this->require($fields !== [], 'שדה ACF אינו קיים.');
        }
        if ($name === 'wp_acf_schema') {
            return ['target' => $target, 'fields' => $fields];
        }
        $values = &$state['acf'][$context][$key];
        if ($name === 'wp_acf_get') {
            $public = $snapshots = [];
            foreach ($fields as $field) {
                $fieldKey = $field['key'];
                $public[$fieldKey] = $field['editable'] ? $values[$fieldKey] : '[ערך מוגן]';
                if ($field['editable']) {
                    $snapshots[$fieldKey] = $this->seal('acf:'.$context.':'.$key.':'.$fieldKey, $values[$fieldKey]);
                }
            }

            return ['target' => $target, 'fields' => $fields, 'values' => $public, 'snapshots' => $snapshots];
        }
        $fieldKey = $args['field_key'] ?? '';
        $field = $fields[0];
        $this->require($field['key'] === $fieldKey && $field['editable'], 'לא ניתן לשנות שדה מוגן.');
        $scope = 'acf:'.$context.':'.$key.':'.$fieldKey;
        $before = $values[$fieldKey];
        $expected = $this->seal($scope, $before);
        $this->require(($args['expected'] ?? null) === $expected, 'stale');
        if ($name === 'wp_acf_prepare') {
            $operations = $args['operations'] ?? [];
            $this->require(is_array($operations) && array_is_list($operations) && count($operations) >= 1 && count($operations) <= 30, 'פעולות ACF אינן תקינות.');
            $after = $before;
            foreach ($operations as $operation) {
                $after = $this->patch($field, $after, $operation['path'] ?? null, $operation);
                $this->validateAcf($fieldKey, $after, $state);
            }

            return ['target' => $target, 'field_key' => $fieldKey, 'before' => $before, 'after' => $after, 'expected' => $expected,
                'prepared' => $this->seal('prepared:'.$scope, ['before' => $before, 'after' => $after]), 'changed' => $before !== $after, 'notes' => [], 'writing_text' => ''];
        }
        if (isset($args['restore'])) {
            $after = $this->unseal($args['restore'], $scope);
        } else {
            $prepared = $this->unseal($args['prepared'] ?? null, 'prepared:'.$scope);
            $this->require($prepared['before'] === $before, 'stale');
            $after = $prepared['after'];
        }
        $this->validateAcf($fieldKey, $after, $state);
        $values[$fieldKey] = $after;

        return ['changed' => $before !== $after, 'before' => $expected, 'after' => $this->seal($scope, $after)];
    }

    private function acfFields(string $context): array
    {
        $field = fn (string $key, string $label, string $type = 'text', array $extra = []): array => ['key' => $key, 'name' => substr($key, 6), 'label' => $label, 'type' => $type, 'editable' => true] + $extra;
        if ($context === 'options') {
            return [$field('field_footer', 'טקסט תחתון')];
        }

        return [$field('field_heading', 'כותרת ראשית'), $field('field_score', 'ציון', 'number'),
            $field('field_faq', 'שאלות נפוצות', 'repeater', ['sub_fields' => [$field('field_question', 'שאלה'), $field('field_answer', 'תשובה')]]),
            $field('field_sections', 'מקטעי תוכן', 'flexible_content', ['layouts' => [
                ['name' => 'hero', 'label' => 'כותרת פתיחה', 'sub_fields' => [$field('field_title', 'כותרת')]],
                ['name' => 'text', 'label' => 'תוכן', 'sub_fields' => [$field('field_body', 'תוכן')]],
            ]]),
            $field('field_contact', 'פרטי קשר', 'clone', ['sub_fields' => [$field('field_phone', 'טלפון'), $field('field_email', 'אימייל', 'email')]]),
            $field('field_gallery', 'גלריה', 'gallery'),
            ['key' => 'field_api_key', 'name' => 'api_key', 'label' => 'מפתח API מוגן', 'type' => 'password', 'editable' => false]];
    }

    private function patch(array $field, mixed $value, mixed $path, array $operation): mixed
    {
        $this->require(is_array($path) && array_is_list($path) && count($path) <= 16, 'נתיב ACF אינו תקין.');
        $this->require(($field['editable'] ?? false) === true, 'שדה ACF מוגן.');
        $type = $field['type'] ?? '';
        if ($path !== []) {
            $segment = array_shift($path);
            if (in_array($type, ['repeater', 'flexible_content'], true)) {
                $this->require(is_int($segment) && is_array($value) && array_key_exists($segment, $value) && $path !== [], 'נדרשים אינדקס שורה ומפתח שדה ילד.');
                $key = array_shift($path);
                $children = $type === 'repeater' ? ($field['sub_fields'] ?? [])
                    : (collect($field['layouts'] ?? [])->firstWhere('name', $value[$segment]['acf_fc_layout'] ?? '')['sub_fields'] ?? []);
                $child = collect($children)->firstWhere('key', $key);
                $this->require(is_string($key) && is_array($child), 'שדה ילד אינו קיים בפריסה.');
                $value[$segment][$key] = $this->patch($child, $value[$segment][$key] ?? false, $path, $operation);
            } else {
                $this->require(in_array($type, ['group', 'clone'], true), 'הנתיב אינו תואם למבנה השדה.');
                $child = collect($field['sub_fields'] ?? [])->firstWhere('key', $segment);
                $this->require(is_string($segment) && is_array($child), 'שדה ילד אינו קיים.');
                $value = is_array($value) ? $value : [];
                $value[$segment] = $this->patch($child, $value[$segment] ?? false, $path, $operation);
            }

            return $value;
        }
        if (($operation['op'] ?? '') === 'set') {
            $this->require(array_key_exists('value', $operation), 'חסר ערך.');

            return $operation['value'];
        }
        if (($operation['op'] ?? '') === 'clear') {
            return is_array($value) ? [] : '';
        }
        $index = $operation['index'] ?? null;
        $op = $operation['op'] ?? '';
        $this->require(in_array($type, ['repeater', 'flexible_content', 'gallery'], true)
            && is_array($value) && array_is_list($value) && in_array($op, ['insert', 'remove', 'move'], true)
            && is_int($index) && $index >= 0 && $index <= count($value) && ($op === 'insert' || $index < count($value)), 'פעולת שורה אינה תקינה.');
        if ($op === 'insert') {
            $this->require(array_key_exists('value', $operation), 'חסר ערך שורה.');
            array_splice($value, $index, 0, [$operation['value']]);
        } elseif ($op === 'remove') {
            array_splice($value, $index, 1);
        } else {
            $to = $operation['to'] ?? null;
            $this->require(is_int($to) && $to >= 0 && $to < count($value), 'מיקום היעד אינו תקין.');
            $row = array_splice($value, $index, 1);
            array_splice($value, $to, 0, $row);
        }

        return $value;
    }

    private function validateAcf(string $field, mixed $value, array $state): void
    {
        $valid = match ($field) {
            'field_heading', 'field_footer' => is_string($value),
            'field_score' => is_int($value) || is_float($value) || $value === '',
            'field_contact' => is_array($value) && array_diff(array_keys($value), ['field_phone', 'field_email']) === []
                && is_string($value['field_phone'] ?? '') && is_string($value['field_email'] ?? '')
                && (($value['field_email'] ?? '') === '' || filter_var($value['field_email'], FILTER_VALIDATE_EMAIL)),
            'field_gallery', 'field_faq', 'field_sections' => is_array($value) && array_is_list($value) && count($value) <= 50,
            default => false,
        };
        $this->require((bool) $valid, 'ערך ACF אינו תואם את הסכמה.');
        if ($field === 'field_gallery') {
            foreach ($value as $id) {
                $this->require(is_int($id) && isset($state['media'][$id]), 'תמונת גלריה אינה קיימת.');
            }
        }
        if ($field === 'field_faq' || $field === 'field_sections') {
            foreach ($value as $row) {
                $this->require(is_array($row), 'נדרשת שורת שדות.');
                $allowed = $field === 'field_faq' ? ['field_question', 'field_answer'] : match ($row['acf_fc_layout'] ?? '') {
                    'hero' => ['acf_fc_layout', 'field_title'], 'text' => ['acf_fc_layout', 'field_body'], default => [],
                };
                $this->require($allowed !== [] && array_diff(array_keys($row), $allowed) === [], 'שדה או פריסה אינם מוכרים.');
                foreach ($row as $text) {
                    $this->require(is_string($text), 'נדרש טקסט בשורת ACF.');
                }
            }
        }
    }

    private function learnDash(string $name, array $args, array &$state): array
    {
        $course = ['id' => 201, 'title' => 'קורס וורדפרס', 'type' => 'sfwd-courses', 'status' => 'publish'];
        $group = ['id' => 211, 'title' => 'תלמידי וורדפרס', 'type' => 'groups', 'status' => 'publish'];
        if ($name === 'ld_capabilities') {
            return ['active' => true, 'version' => '4.25.0', 'course_membership' => ['available' => true, 'reason' => null],
                'group_membership' => ['available' => true, 'reason' => null], 'progress_available' => true, 'hierarchy_available' => true,
                'limitations' => ['אין איפוס התקדמות, שינוי ציונים או שינוי תשלומים.']];
        }
        if ($name === 'ld_courses_list' || $name === 'ld_groups_list') {
            $row = $name === 'ld_courses_list' ? $course : $group;
            $search = (string) ($args['search'] ?? '');

            return $this->page($search === '' || mb_stripos($row['title'], $search) !== false ? [$row] : [], $args, $name === 'ld_courses_list' ? 'courses' : 'groups');
        }
        if ($name === 'ld_course_get') {
            $this->require(($args['course_id'] ?? null) === 201, 'קורס אינו קיים.');
            $lessons = array_map(fn (int $id): array => ['id' => $id, 'title' => ['מבוא', 'ניהול תוכן', 'הגדרות האתר'][$id - 202], 'type' => 'sfwd-lessons', 'status' => 'publish', 'topics' => []], [202, 203, 204]);

            return ['course' => $course, 'hierarchy_available' => true, 'reason' => null, 'hierarchy' => ['lessons' => $lessons, 'steps' => $lessons]];
        }
        if ($name === 'ld_group_get') {
            $this->require(($args['group_id'] ?? null) === 211, 'קבוצה אינה קיימת.');

            return ['group' => $group, 'courses' => [$course], 'members_available' => true, 'reason' => null]
                + $this->page(($state['learndash']['groups'][5][211] ?? false) ? [['id' => 5, 'display_name' => 'נועה כהן']] : [], $args, 'members');
        }
        $userId = $this->id($args, 'user_id');
        $this->require(isset($state['users'][$userId]), 'משתמש אינו קיים.');
        $user = ['id' => $userId, 'display_name' => $userId === 5 ? 'נועה כהן' : 'מנהל האתר'];
        if ($name === 'ld_student_course_get') {
            $this->require(($args['course_id'] ?? null) === 201, 'קורס אינו קיים.');

            return ['user' => $user, 'course' => $course, 'progress' => ['completed' => 0, 'total' => 3, 'percentage' => 0], 'access' => $this->membershipState($state, $userId, 'course')];
        }
        $kind = $args['kind'] ?? '';
        $targetId = $this->id($args, 'target_id');
        $this->require(($kind === 'course' && $targetId === 201) || ($kind === 'group' && $targetId === 211), 'יעד LearnDash אינו קיים.');
        $selector = ['user_id' => $userId, 'kind' => $kind, 'target_id' => $targetId];
        $scope = 'ld:'.$kind.':'.$targetId.':'.$userId;
        $target = ['id' => $targetId, 'type' => $kind, 'title' => $kind === 'course' ? $course['title'] : $group['title']];
        $snapshotValue = ['direct' => $state['learndash']['direct'][$userId][201] ?? false, 'group' => $state['learndash']['groups'][$userId][211] ?? false];
        $before = $this->membershipState($state, $userId, $kind);
        $expected = $this->seal($scope, $snapshotValue);
        $impacts = $kind === 'group' ? [['course_id' => 201, 'title' => $course['title'], 'effective_access' => $this->membershipState($state, $userId, 'course')['effective_access']]] : [];
        if ($name === 'ld_membership_get') {
            return compact('selector', 'user', 'target', 'impacts') + ['state' => $before, 'writable' => $userId !== 1, 'reason' => $userId === 1 ? 'חשבון מנהל מוגן' : null, 'snapshot' => $expected];
        }
        $this->require($userId !== 1, 'חשבון מנהל מוגן.');
        $this->require(($args['expected'] ?? null) === $expected, 'stale');
        if ($name === 'ld_membership_prepare') {
            $this->require(in_array($args['action'] ?? null, ['add', 'remove'], true), 'פעולת הרישום אינה תקינה.');
            $next = $snapshotValue;
            $next[$kind === 'course' ? 'direct' : 'group'] = $args['action'] === 'add';
            $copy = $state;
            $copy['learndash']['direct'][$userId][201] = $next['direct'];
            $copy['learndash']['groups'][$userId][211] = $next['group'];
            $after = $this->membershipState($copy, $userId, $kind);
            $preparedImpacts = array_map(fn (array $impact): array => ['course_id' => 201, 'title' => $course['title'], 'before_access' => $impact['effective_access'], 'after_access' => $next['direct'] || $next['group']], $impacts);

            return compact('selector', 'user', 'target', 'before', 'after', 'expected') + ['impacts' => $preparedImpacts, 'notes' => [],
                'changed' => $next !== $snapshotValue, 'prepared' => $this->seal('prepared:'.$scope, ['before' => $snapshotValue, 'after' => $next])];
        }
        if ($name === 'ld_membership_revert') {
            $next = $this->unseal($args['restore'] ?? null, $scope);
        } else {
            $prepared = $this->unseal($args['prepared'] ?? null, 'prepared:'.$scope);
            $this->require($prepared['before'] === $snapshotValue, 'stale');
            $next = $prepared['after'];
        }
        $state['learndash']['direct'][$userId][201] = $next['direct'];
        $state['learndash']['groups'][$userId][211] = $next['group'];

        return ['changed' => $next !== $snapshotValue, 'selector' => $selector, 'expected' => $expected, 'before' => $expected, 'after' => $this->seal($scope, $next)];
    }

    private function membershipState(array $state, int $user, string $kind): array
    {
        $direct = $state['learndash']['direct'][$user][201] ?? false;
        $group = $state['learndash']['groups'][$user][211] ?? false;

        return ['direct_member' => $kind === 'course' ? $direct : $group, 'effective_access' => $kind === 'course' ? ($direct || $group) : $group,
            'access_sources' => $kind === 'course' ? array_values(array_filter([$direct ? 'direct' : null, $group ? 'group:211' : null])) : ($group ? ['direct'] : []),
            'access_from' => null, 'expires_at' => null];
    }

    private function categorySale(string $name, array $args, array &$state): array
    {
        $this->require(($args['category_id'] ?? null) === 12 && (! isset($args['include_children']) || is_bool($args['include_children'])), 'קטגוריה אינה קיימת.');
        $children = $args['include_children'] ?? true;
        $scope = 'category:12:'.($children ? '1' : '0');
        $rows = [];
        foreach ([7, 8] as $id) {
            $product = $state['products'][$id];
            $row = ['id' => $id, 'parent_id' => 0, 'type' => $product['type'], 'name' => $product['name'], 'regular_price' => $product['regular_price'],
                'sale_price' => $product['sale_price'] ?? '', 'sale_from' => $product['sale_from'] ?? null, 'sale_to' => $product['sale_to'] ?? null];
            foreach (['sale_from', 'sale_to'] as $date) {
                if (is_string($row[$date]) && $row[$date] !== '') {
                    $row[$date] = (new DateTimeImmutable($row[$date], new DateTimeZone('Asia/Jerusalem')))->getTimestamp();
                } elseif ($row[$date] === '') {
                    $row[$date] = null;
                }
            }
            $rows[] = $row;
        }
        $identity = ['category' => ['id' => 12, 'name' => 'חולצות'], 'include_children' => $children, 'timezone' => 'Asia/Jerusalem', 'currency' => 'ILS', 'excluded' => []];
        $expected = $this->seal($scope, $rows);
        if ($name === 'wc_category_sale_get') {
            return $identity + ['products' => $rows, 'snapshot' => $expected];
        }
        $this->require(($args['expected'] ?? null) === $expected, 'stale');
        if ($name === 'wc_category_sale_prepare') {
            $discount = $this->agorot($args['discount_value'] ?? null);
            $type = $args['discount_type'] ?? '';
            $this->require(in_array($type, ['percent', 'fixed'], true) && $discount > 0 && ($type !== 'percent' || $discount <= 10000), 'הנחה אינה תקינה.');
            $zone = new DateTimeZone('Asia/Jerusalem');
            $startText = $args['starts_at'] ?? now($zone)->format('Y-m-d H:i');
            $endText = $args['ends_at'] ?? '';
            $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startText, $zone);
            $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $endText, $zone);
            $this->require($start && $end && $start->format('Y-m-d H:i') === $startText && $end->format('Y-m-d H:i') === $endText && $end > $start && $end->getTimestamp() > now()->timestamp, 'מועד המבצע אינו תקין.');
            $after = $rows;
            foreach ($after as &$row) {
                $this->require($row['sale_price'] === '' || ($args['replace_existing'] ?? false) === true || ($row['sale_to'] !== null && $row['sale_to'] < now()->timestamp), 'נדרש אישור להחלפת מבצע קיים.');
                $regular = $this->agorot($row['regular_price']);
                $price = $type === 'percent' ? intdiv($regular * (10000 - $discount) + 5000, 10000) : max(0, $regular - $discount);
                $row['sale_price'] = intdiv($price, 100).'.'.str_pad((string) ($price % 100), 2, '0', STR_PAD_LEFT);
                $row['sale_from'] = $start->getTimestamp();
                $row['sale_to'] = $end->getTimestamp() - 1;
            }
            unset($row);

            return $identity + ['changed' => true, 'before' => $rows, 'after' => $after, 'expected' => $expected,
                'prepared' => $this->seal('prepared:'.$scope, ['before' => $rows, 'after' => $after]), 'notes' => [],
                'schedule' => ['starts_at' => $startText, 'ends_at' => $endText, 'timezone' => 'Asia/Jerusalem']];
        }
        if ($name === 'wc_category_sale_revert') {
            $after = $this->unseal($args['restore'] ?? null, $scope);
        } else {
            $prepared = $this->unseal($args['prepared'] ?? null, 'prepared:'.$scope);
            $this->require($prepared['before'] === $rows, 'stale');
            $after = $prepared['after'];
        }
        foreach ($after as $row) {
            foreach (['sale_price', 'sale_from', 'sale_to'] as $field) {
                $state['products'][$row['id']][$field] = $row[$field];
            }
        }
        $campaign = 'evaluation-category-12';
        $state['category_sales'][12] = ['active' => $name === 'wc_category_sale_apply', 'campaign_id' => $campaign];

        return ['changed' => true, 'campaign_id' => $campaign, 'before' => $expected, 'after' => $this->seal($scope, $after)];
    }

    private function agorot(mixed $value): int
    {
        $this->require(is_string($value) && preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $value), 'נדרש סכום עשרוני תקין.');
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function seal(string $scope, mixed $value): array
    {
        $version = hash('sha256', json_encode([$scope, $value], JSON_THROW_ON_ERROR));
        $token = hash('sha256', 'isolated-evaluation:'.$version);
        $this->tokens[$token] = ['scope' => $scope, 'value' => $value, 'version' => $version];

        return compact('version', 'token');
    }

    private function unseal(mixed $seal, string $scope): mixed
    {
        $record = is_array($seal) ? ($this->tokens[$seal['token'] ?? ''] ?? null) : null;
        $this->require($record !== null && $record['scope'] === $scope && $record['version'] === ($seal['version'] ?? null), 'צילום המצב אינו תקין או שייך ליעד אחר.');

        return $record['value'];
    }

    private function values(array $args, array $allowed): array
    {
        $values = $args['values'] ?? null;
        $this->require(is_array($values) && $values !== [] && array_diff(array_keys($values), $allowed) === [], 'שדות לא נתמכים או מוגנים.');

        return $values;
    }

    private function change(array &$record, array $values, mixed $expected): array
    {
        $this->require(is_array($expected) && array_diff_key($values, $expected) === [], 'stale');
        foreach ($expected as $key => $value) {
            $this->require(array_key_exists($key, $record) && $record[$key] === $value, 'stale');
        }
        $before = array_intersect_key($record, $values);
        $record = array_replace($record, $values);

        return ['changed' => $before !== $values, 'before' => $before, 'after' => $values, 'values' => $record];
    }

    private function page(array $rows, array $args, string $key): array
    {
        $page = max(1, (int) ($args['page'] ?? 1));
        $limit = min(100, max(1, (int) ($args['limit'] ?? 20)));

        return [$key => array_slice($rows, ($page - 1) * $limit, $limit), 'page' => $page, 'limit' => $limit, 'has_more' => count($rows) > $page * $limit];
    }

    private function id(array $args, string $key): int
    {
        $value = $args[$key] ?? null;
        $this->require(is_int($value) && $value > 0, 'נדרש מזהה חיובי.');

        return $value;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
