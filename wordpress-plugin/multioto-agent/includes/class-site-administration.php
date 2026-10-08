<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Narrow, reversible administration tools exposed through authenticated MCP.
 * No credentials, user capabilities, arbitrary options, theme files or deletion
 * enter this interface. Every write carries the values the customer approved.
 */
class Multioto_Agent_Site_Administration
{
    private const PROFILE_FIELDS = ['display_name', 'first_name', 'last_name', 'description'];

    public static function definitions(): array
    {
        $profile = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $profile[$field] = ['type' => 'string'];
        }

        $profileValues = ['type' => 'object', 'properties' => $profile, 'additionalProperties' => false, 'minProperties' => 1];
        $themeValues = ['type' => 'object', 'properties' => ['stylesheet' => ['type' => 'string']], 'required' => ['stylesheet'], 'additionalProperties' => false];

        return [
            self::definition('wp_user_profile_get', 'קריאת שם ותיאור ציבורי של משתמש שאינו מנהל. לא מחזיר סיסמה, אימייל או הרשאות.', true, ['id' => ['type' => 'integer', 'minimum' => 1]], ['id']),
            self::definition('wp_user_profile_update', 'עריכת שם מוצג, שם פרטי, שם משפחה ותיאור של משתמש שאינו מנהל. קראו קודם wp_user_profile_get והעבירו expected לכל שדה שנערך. מחזיר before/after לביטול; אינו משנה פרטי התחברות או תפקידים.', false, ['id' => ['type' => 'integer', 'minimum' => 1], 'values' => $profileValues, 'expected' => $profileValues], ['id', 'values', 'expected']),
            self::definition('wp_theme_active_get', 'קריאת התבנית הפעילה לפי stylesheet לצורך הצגת שינוי וביטולו.', true, [], []),
            self::definition('wp_theme_active_set', 'מעבר לתבנית מותקנת ותקינה בלבד. קראו קודם wp_theme_active_get והעבירו expected.stylesheet. דורש שגם התבנית הקודמת זמינה לחזרה. משנה את עיצוב האתר; החזרה משחזרת את בחירת התבנית ואינה גיבוי של שינויים שתוספים מבצעים בהחלפת תבנית.', false, ['values' => $themeValues, 'expected' => $themeValues], ['values', 'expected']),
        ];
    }

    public static function handles(string $name): bool
    {
        return in_array($name, ['wp_user_profile_get', 'wp_user_profile_update', 'wp_theme_active_get', 'wp_theme_active_set'], true);
    }

    public static function call(string $name, array $args): array
    {
        switch ($name) {
            case 'wp_user_profile_get':
                self::onlyKeys($args, ['id']);

                return self::profile($args);
            case 'wp_user_profile_update':
                self::onlyKeys($args, ['id', 'values', 'expected']);

                return self::updateProfile($args);
            case 'wp_theme_active_get':
                self::onlyKeys($args, []);

                return self::activeTheme();
            case 'wp_theme_active_set':
                self::onlyKeys($args, ['values', 'expected']);

                return self::setActiveTheme($args);
        }

        throw new Multioto_Agent_Rpc_Error(-32601, 'כלי ניהול האתר אינו מוכר.');
    }

    private static function definition(string $name, string $description, bool $read, array $properties, array $required): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'annotations' => ['readOnlyHint' => $read, 'destructiveHint' => false],
            'inputSchema' => ['type' => 'object', 'properties' => $properties ?: (object) [], 'required' => $required, 'additionalProperties' => false],
        ];
    }

    private static function profile(array $args): array
    {
        $id = $args['id'] ?? null;
        if ((! is_int($id) && ! (is_string($id) && ctype_digit($id))) || (int) $id < 1) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'נדרש מזהה משתמש חיובי.');
        }

        $user = get_user_by('id', (int) $id);
        if (! $user instanceof WP_User) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'המשתמש לא נמצא באתר.');
        }

        // Role names alone miss a custom role or a direct capability grant.
        if (in_array('administrator', (array) $user->roles, true) || (is_multisite() && is_super_admin((int) $user->ID))) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'לא ניתן לנהל פרופיל של מנהל אתר דרך הבוט.');
        }
        foreach (['manage_options', 'manage_network', 'promote_users', 'install_plugins', 'edit_plugins'] as $capability) {
            if (user_can($user, $capability)) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'לא ניתן לנהל פרופיל בעל הרשאות ניהול דרך הבוט.');
            }
        }

        $values = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $values[$field] = (string) $user->$field;
        }

        return ['id' => (int) $user->ID, 'label' => $values['display_name'], 'values' => $values];
    }

    private static function updateProfile(array $args): array
    {
        list($values, $expected) = self::changeValues($args, self::PROFILE_FIELDS);
        $values = self::profileValues($values);

        $current = self::profile($args);
        $before = array_intersect_key($current['values'], $values);
        self::expect($before, $expected);

        // Legacy data that this narrow interface cannot round-trip is not
        // editable here: otherwise "undo" would silently drop markup or fail.
        if (self::profileValues($before) !== $before) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'הערך הקיים אינו מתאים לשחזור בטוח דרך הבוט. יש לערוך אותו בלוח הבקרה.');
        }
        $values = array_replace($before, $values);

        if ($before !== $values) {
            $result = wp_update_user(['ID' => $current['id']] + $values);
            if (is_wp_error($result) || (int) $result !== $current['id']) {
                throw new Multioto_Agent_Rpc_Error(-32000, 'שמירת פרופיל המשתמש נכשלה.');
            }
        }

        // Read the actual saved values: WordPress plugins may filter profile
        // text. Undo must compare against what was stored, not what was sent.
        $saved = self::profile($args);
        $after = array_intersect_key($saved['values'], $values);

        return ['id' => $saved['id'], 'label' => $saved['label'], 'before' => $before, 'after' => $after, 'changed' => $before !== $after];
    }

    private static function profileValues(array $values): array
    {
        foreach ($values as $field => $value) {
            if (strlen($value) > ($field === 'description' ? 10000 : 250)) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'ערך הפרופיל ארוך מדי.');
            }
            $values[$field] = $field === 'description' ? wp_kses_data($value) : sanitize_text_field($value);
            if ($field === 'display_name' && trim($values[$field]) === '') {
                throw new Multioto_Agent_Rpc_Error(-32602, 'השם המוצג אינו יכול להיות ריק.');
            }
        }

        return $values;
    }

    private static function activeTheme(): array
    {
        $stylesheet = (string) get_stylesheet();
        $theme = wp_get_theme($stylesheet);

        return ['id' => $stylesheet, 'label' => (string) $theme->get('Name'), 'values' => ['stylesheet' => $stylesheet]];
    }

    private static function setActiveTheme(array $args): array
    {
        list($values, $expected) = self::changeValues($args, ['stylesheet']);
        $current = self::activeTheme();
        self::expect($current['values'], $expected);
        self::usableTheme($current['values']['stylesheet']);
        self::usableTheme($values['stylesheet']);

        if ($current['values'] !== $values) {
            switch_theme($values['stylesheet']);
        }

        $saved = self::activeTheme();
        if ($saved['values'] !== $values) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'וורדפרס לא הפעיל את התבנית המבוקשת.');
        }

        return ['id' => $saved['id'], 'label' => $saved['label'], 'before' => $current['values'], 'after' => $saved['values'], 'changed' => $current['values'] !== $saved['values']];
    }

    /** Both the destination and the return path must still be usable. */
    private static function usableTheme(string $stylesheet): void
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,199}$/D', $stylesheet) || strpos($stylesheet, '..') !== false) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'מזהה התבנית אינו תקין.');
        }

        $theme = wp_get_theme($stylesheet);
        if (! $theme->exists() || $theme->errors()) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'התבנית או תבנית האב חסרה או אינה תקינה.');
        }

        $parent = $theme->parent();
        if ($parent && (! $parent->exists() || $parent->errors())) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'תבנית האב אינה זמינה להפעלה.');
        }

        if (is_multisite() && ! $theme->is_allowed()) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'התבנית אינה מאושרת לאתר ברשת.');
        }

        foreach (array_filter([$theme, $parent]) as $candidate) {
            $php = (string) $candidate->get('RequiresPHP');
            $wordpress = (string) $candidate->get('RequiresWP');
            if (($php !== '' && version_compare(PHP_VERSION, $php, '<')) || ($wordpress !== '' && version_compare((string) get_bloginfo('version'), $wordpress, '<'))) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'התבנית דורשת גרסת PHP או וורדפרס חדשה יותר.');
            }
        }
    }

    /** Strict input maps prevent quietly ignoring a requested sensitive field. */
    private static function changeValues(array $args, array $allowed): array
    {
        $values = $args['values'] ?? null;
        $expected = $args['expected'] ?? null;
        if (! is_array($values) || $values === [] || ! is_array($expected) || $expected === []) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'נדרשים values ו-expected עם אותם השדות.');
        }
        self::onlyKeys($values, $allowed);
        self::onlyKeys($expected, array_keys($values));
        if (count($expected) !== count($values)) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'חסר ערך expected לשדה שנערך.');
        }
        foreach ($values as $field => $value) {
            if (! is_string($value) || ! is_string($expected[$field])) {
                throw new Multioto_Agent_Rpc_Error(-32602, 'ערכי השדות חייבים להיות טקסט.');
            }
        }

        return [$values, $expected];
    }

    private static function expect(array $current, array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $current) || $current[$key] !== $value) {
                throw new Multioto_Agent_Rpc_Error(-32000, 'המידע השתנה מאז האישור. יש לקרוא אותו מחדש לפני שינוי או ביטול.');
            }
        }
    }

    private static function onlyKeys(array $values, array $allowed): void
    {
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw new Multioto_Agent_Rpc_Error(-32602, 'הבקשה מכילה שדה שאינו נתמך בכלי הזה.');
        }
    }
}
