<?php

if (! defined('ABSPATH')) {
    exit;
}

/** Bounded, reversible content, SEO and presentation-setting operations. */
class Multioto_Agent_Content_Management
{
    private const CONTENT_KEYS = ['status', 'date', 'date_gmt', 'parent', 'menu_order', 'slug'];
    private const SETTINGS = [
        'blogname' => '', 'blogdescription' => '', 'timezone_string' => '', 'gmt_offset' => 0.0,
        'date_format' => 'F j, Y', 'time_format' => 'g:i a', 'start_of_week' => 1,
        'show_on_front' => 'posts', 'page_on_front' => 0, 'page_for_posts' => 0, 'posts_per_page' => 10,
    ];

    public static function definitions(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $map = ['type' => 'object'];
        $tools = [];
        $specs = [
            ['wp_content_details', 'פרטי תזמון וסידור תוכן: מצב, תאריך מקומי ו-UTC, אב, סדר ו-slug. מחזיר גם אזור זמן.', ['id' => $id], ['id'], true],
            ['wp_content_manage', 'תזמון וסידור תוכן. values: status/date/date_gmt/parent/menu_order/slug. תאריך מקומי בפורמט Y-m-d H:i:s; תזמון דורש status=future. expected מהקריאה האחרונה, כולל date_gmt בשינוי תאריך.', ['id' => $id, 'values' => $map, 'expected' => $map], ['id', 'values', 'expected'], false],
            ['wp_seo_get', 'קריאת כותרת ותיאור SEO של Yoast או Rank Math הפעיל. null מציין שלא נקבע ערך. אם שניהם פעילים יש לציין provider.', ['id' => $id, 'provider' => ['type' => 'string', 'enum' => ['yoast', 'rank_math']]], ['id'], true],
            ['wp_seo_update', 'עדכון כותרת ותיאור SEO בתוסף הפעיל בלבד. values: title/description; null מסיר התאמה אישית. provider מהקריאה; expected מחייב ערכים קודמים.', ['id' => $id, 'provider' => ['type' => 'string', 'enum' => ['yoast', 'rank_math']], 'values' => $map, 'expected' => $map], ['id', 'provider', 'values', 'expected'], false],
            ['wp_internal_links_get', 'קריאת תוכן וקישורים פנימיים לצורך הוספת קישור מדויק. Elementor אינו נתמך בכלי זה.', ['id' => $id], ['id'], true],
            ['wp_internal_link_update', 'קישור הופעה יחידה של טקסט קיים לפריט תוכן מפורסם באותו אתר. values: text,target_id; expected.content מהקריאה האחרונה. אין לשנות HTML או להשתמש בתוכן Elementor.', ['id' => $id, 'values' => $map, 'expected' => $map], ['id', 'values', 'expected'], false],
            ['wp_site_settings_get', 'קריאת הגדרות תצוגה בטוחות: שם, תיאור, זמן, עמוד בית, בלוג ומספר פוסטים.', [], [], true],
            ['wp_site_settings_update', 'עדכון הגדרות תצוגה מהרשימה של wp_site_settings_get. expected לכל שדה ששונה. אין שינוי כתובות, הרשאות או סודות.', ['values' => $map, 'expected' => $map], ['values', 'expected'], false],
        ];
        foreach ($specs as $spec) {
            $tools[] = ['name' => $spec[0], 'description' => $spec[1], 'annotations' => ['readOnlyHint' => $spec[4], 'destructiveHint' => false], 'inputSchema' => ['type' => 'object', 'properties' => $spec[2] ?: (object) [], 'required' => $spec[3]]];
        }
        return $tools;
    }

    public static function handles(string $name): bool
    {
        return in_array($name, array_column(self::definitions(), 'name'), true);
    }

    public static function call(string $name, array $args): array
    {
        switch ($name) {
            case 'wp_content_details': return self::contentDetails($args);
            case 'wp_content_manage': return self::contentManage($args);
            case 'wp_seo_get': return self::seoGet($args);
            case 'wp_seo_update': return self::seoUpdate($args);
            case 'wp_internal_links_get': return self::linksGet($args);
            case 'wp_internal_link_update': return self::linkUpdate($args);
            case 'wp_site_settings_get': return ['label' => 'הגדרות האתר', 'values' => self::settings()];
            case 'wp_site_settings_update': return self::settingsUpdate($args);
            default: self::fail('כלי לא מוכר.');
        }
    }

    private static function fail(string $message): void
    {
        throw new Multioto_Agent_Rpc_Error(-32602, $message);
    }

    private static function integer($value, int $min = 0, int $max = PHP_INT_MAX): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            self::fail('נדרש מספר שלם בטווח המותר.');
        }
        return $value;
    }

    private static function post(array $args, bool $allowProduct = false)
    {
        $id = self::integer($args['id'] ?? null, 1);
        $post = get_post($id);
        $excluded = ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'elementor_library', 'e-landing-page', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_subscription', 'shop_coupon'];
        if (! $allowProduct) {
            $excluded[] = 'product';
        }
        if (! $post instanceof WP_Post || in_array($post->post_type, $excluded, true) || ! in_array($post->post_type, get_post_types(['show_ui' => true], 'names'), true) || in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
            self::fail('פריט תוכן זמין לעריכה לא נמצא.');
        }
        if (class_exists('Multioto_Agent_Fields')) {
            Multioto_Agent_Fields::assertLearnDashContentAllowed($post->post_type);
        }
        return $post;
    }

    private static function changes(array $args, array $current, array $allowed): array
    {
        $values = $args['values'] ?? null;
        $expected = $args['expected'] ?? null;
        if (! is_array($values) || ! $values || ! is_array($expected)) {
            self::fail('נדרשים values לא ריק ו-expected מהקריאה האחרונה.');
        }
        foreach ($values as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                self::fail('שדה אינו ניתן לשינוי: '.(string) $key);
            }
            self::expect($key, $expected, $current);
        }
        return $values;
    }

    private static function expect(string $key, array $expected, array $current): void
    {
        $numericEqual = array_key_exists($key, $expected) && array_key_exists($key, $current)
            && (is_int($expected[$key]) || is_float($expected[$key])) && (is_int($current[$key]) || is_float($current[$key]))
            && (float) $expected[$key] === (float) $current[$key];
        if (! array_key_exists($key, $expected) || ! array_key_exists($key, $current) || ($expected[$key] !== $current[$key] && ! $numericEqual)) {
            self::fail('המידע השתנה או חסר ערך קודם עבור '.$key.'. יש לקרוא מחדש לפני אישור.');
        }
    }

    private static function sameValues(array $current, array $values): bool
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $current) || $current[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    private static function contentValues($post): array
    {
        return ['status' => (string) $post->post_status, 'date' => (string) $post->post_date, 'date_gmt' => (string) $post->post_date_gmt, 'parent' => (int) $post->post_parent, 'menu_order' => (int) $post->menu_order, 'slug' => (string) $post->post_name];
    }

    private static function contentDetails(array $args): array
    {
        $post = self::post($args);
        $result = ['id' => (int) $post->ID, 'label' => (string) $post->post_title, 'type' => $post->post_type, 'timezone' => wp_timezone_string(), 'values' => self::contentValues($post)];
        if (class_exists('Multioto_Agent_Fields') && Multioto_Agent_Fields::isLearnDashType($post->post_type)) {
            $result['structure_note'] = 'שדות WordPress אלה אינם מייצגים את מבנה הקורס או שיוך השיעורים של LearnDash.';
        }
        return $result;
    }

    private static function contentManage(array $args): array
    {
        $post = self::post($args);
        $current = self::contentValues($post);
        $values = self::changes($args, $current, self::CONTENT_KEYS);
        if (class_exists('Multioto_Agent_Fields') && Multioto_Agent_Fields::isLearnDashType($post->post_type)
            && in_array($post->post_type, ['sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question'], true)) {
            foreach (['parent', 'menu_order'] as $key) {
                if (array_key_exists($key, $values) && $values[$key] !== $current[$key]) {
                    self::fail('שיוך וסידור שלבי LearnDash דורשים את בונה הקורסים המקורי; post_parent ו-menu_order אינם מחליפים אותו.');
                }
            }
        }
        $all = array_replace($current, $values);
        if (! is_string($all['status']) || ! in_array($all['status'], ['draft', 'pending', 'publish', 'private', 'future'], true)) {
            self::fail('מצב התוכן אינו מותר.');
        }
        if (array_key_exists('date_gmt', $values) && ! array_key_exists('date', $values)) {
            self::fail('date_gmt ניתן לשינוי רק עם date.');
        }
        if (array_key_exists('date', $values) || array_key_exists('status', $values)) {
            foreach (['status', 'date', 'date_gmt'] as $key) {
                self::expect($key, $args['expected'], $current);
            }
        }
        if (array_key_exists('date', $values)) {
            self::expect('date_gmt', $args['expected'], $current);
            $date = self::date($values['date'], wp_timezone());
            $gmt = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            // WordPress leaves GMT unset for some drafts. Preserve this exact
            // snapshot on undo, while never accepting an inconsistent live date.
            $unsetDraft = isset($values['date_gmt']) && $values['date_gmt'] === '0000-00-00 00:00:00' && in_array($all['status'], ['draft', 'pending'], true);
            if (array_key_exists('date_gmt', $values) && $values['date_gmt'] !== $gmt && ! $unsetDraft) {
                self::fail('התאריך המקומי ותאריך UTC אינם תואמים לאזור הזמן.');
            }
            $values['date_gmt'] = $unsetDraft ? $values['date_gmt'] : $gmt;
            $all = array_replace($current, $values);
        }
        if (isset($values['date']) || isset($values['status'])) {
            $date = self::date($all['date'], wp_timezone());
            if ($all['status'] === 'future' && $date->getTimestamp() <= time() + 60) {
                self::fail('תזמון פרסום דורש תאריך עתידי בעוד יותר מדקה.');
            }
            if ($all['status'] === 'publish' && $date->getTimestamp() > time()) {
                self::fail('לפרסום עתידי יש לבחור status=future.');
            }
        }
        if (array_key_exists('parent', $values)) {
            self::integer($values['parent']);
            $type = get_post_type_object($post->post_type);
            if (! $type || (! $type->hierarchical && $values['parent'] !== 0)) {
                self::fail('סוג התוכן אינו תומך בעמוד אב.');
            }
            $seen = [(int) $post->ID];
            $parent = $values['parent'];
            while ($parent) {
                if (in_array($parent, $seen, true)) {
                    self::fail('בחירת האב יוצרת מעגל.');
                }
                $seen[] = $parent;
                $ancestor = self::post(['id' => $parent]);
                if ($ancestor->post_type !== $post->post_type) {
                    self::fail('האב חייב להיות מאותו סוג תוכן.');
                }
                $parent = (int) $ancestor->post_parent;
            }
        }
        if (array_key_exists('menu_order', $values)) {
            self::integer($values['menu_order'], -100000, 100000);
        }
        if (array_key_exists('slug', $values)) {
            $blankDraft = $values['slug'] === '' && in_array($all['status'], ['draft', 'pending'], true);
            if (! is_string($values['slug']) || ($values['slug'] === '' && ! $blankDraft) || strlen($values['slug']) > 200 || sanitize_title($values['slug']) !== $values['slug']) {
                self::fail('נדרש slug תקין ומנורמל באורך עד 200 תווים.');
            }
            if (wp_unique_post_slug($values['slug'], $post->ID, $all['status'], $post->post_type, $all['parent']) !== $values['slug']) {
                self::fail('ה-slug כבר בשימוש.');
            }
        }
        $map = ['status' => 'post_status', 'date' => 'post_date', 'date_gmt' => 'post_date_gmt', 'parent' => 'post_parent', 'menu_order' => 'menu_order', 'slug' => 'post_name'];
        $update = ['ID' => (int) $post->ID];
        foreach ($values as $key => $value) {
            $update[$map[$key]] = $value;
        }
        if (self::sameValues($current, $values)) {
            return self::result((int) $post->ID, $current, $current, array_keys($values));
        }
        self::savePost($update);
        $after = self::contentValues(get_post($post->ID));
        $changed = array_keys($values);
        foreach ($after as $key => $value) {
            if ($value !== $current[$key]) {
                $changed[] = $key;
            }
        }
        // A date snapshot is always a pair, including an unchanged local date
        // when WordPress computed GMT while publishing a draft.
        if (in_array('date_gmt', $changed, true)) {
            $changed[] = 'date';
        }
        if (array_key_exists('date', $values) || array_key_exists('status', $values)) {
            $changed = array_merge($changed, ['status', 'date', 'date_gmt']);
        }
        return self::result((int) $post->ID, $current, $after, array_unique($changed));
    }

    private static function date($value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (! is_string($value)) {
            self::fail('תאריך חייב להיות בפורמט Y-m-d H:i:s.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d H:i:s') !== $value) {
            self::fail('תאריך אינו תקין באזור הזמן של האתר.');
        }
        return $date;
    }

    private static function savePost(array $values): void
    {
        $result = wp_update_post(wp_slash($values), true);
        if (is_wp_error($result)) {
            throw new Multioto_Agent_Rpc_Error(-32000, $result->get_error_message());
        }
    }

    private static function result(?int $id, array $before, array $after, array $keys): array
    {
        $filter = array_fill_keys($keys, true);
        $result = ['before' => array_intersect_key($before, $filter), 'after' => array_intersect_key($after, $filter)];
        $result['changed'] = $result['before'] !== $result['after'];
        return $id === null ? $result : ['id' => $id] + $result;
    }

    private static function provider(array $args): string
    {
        $active = [];
        if (defined('WPSEO_VERSION')) {
            $active[] = 'yoast';
        }
        if (defined('RANK_MATH_VERSION')) {
            $active[] = 'rank_math';
        }
        $provider = $args['provider'] ?? (count($active) === 1 ? $active[0] : '');
        if (! is_string($provider) || ! in_array($provider, $active, true)) {
            self::fail('נדרש Yoast או Rank Math פעיל; אם שניהם פעילים יש לבחור provider.');
        }
        return $provider;
    }

    private static function seoKeys(string $provider): array
    {
        return $provider === 'yoast' ? ['title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc'] : ['title' => 'rank_math_title', 'description' => 'rank_math_description'];
    }

    private static function seoValues(int $id, string $provider): array
    {
        $values = [];
        foreach (self::seoKeys($provider) as $name => $key) {
            $values[$name] = metadata_exists('post', $id, $key) ? (string) get_post_meta($id, $key, true) : null;
        }
        return $values;
    }

    private static function seoGet(array $args): array
    {
        $post = self::post($args, true);
        $provider = self::provider($args);
        return ['id' => (int) $post->ID, 'label' => (string) $post->post_title, 'provider' => $provider, 'values' => self::seoValues((int) $post->ID, $provider)];
    }

    private static function seoUpdate(array $args): array
    {
        $read = self::seoGet($args);
        if (! isset($args['provider'])) {
            self::fail('provider נדרש לשינוי SEO.');
        }
        $values = self::changes($args, $read['values'], ['title', 'description']);
        foreach ($values as $value) {
            if ($value !== null && (! is_string($value) || strlen($value) > 2000 || sanitize_text_field($value) !== $value)) {
                self::fail('שדות SEO מקבלים טקסט פשוט עד 2000 בתים או null.');
            }
        }
        $keys = self::seoKeys($read['provider']);
        if (self::sameValues($read['values'], $values)) {
            return ['provider' => $read['provider']] + self::result($read['id'], $read['values'], $read['values'], array_keys($values));
        }
        $attempted = [];
        try {
            foreach ($values as $name => $value) {
                $attempted[] = $name;
                self::setMeta($read['id'], $keys[$name], $value);
            }
            // Yoast builds its indexable on WordPress's wp_insert_post hook.
            // Save once AFTER all metadata so supported plugin hooks see it.
            self::savePost(['ID' => $read['id']]);
            $after = self::seoValues($read['id'], $read['provider']);
            if (! self::sameValues($after, $values)) {
                throw new Multioto_Agent_Rpc_Error(-32000, 'שדות SEO השתנו במהלך השמירה.');
            }
        } catch (Throwable $e) {
            foreach ($attempted as $name) {
                try {
                    $now = self::seoValues($read['id'], $read['provider']);
                    // Compensate only a value still equal to our attempted
                    // write; never replace a later independent modification.
                    if ($now[$name] === $values[$name] && $now[$name] !== $read['values'][$name]) {
                        self::setMeta($read['id'], $keys[$name], $read['values'][$name]);
                    }
                } catch (Throwable $restoreError) {
                    // Try the remaining independent fields as well.
                }
            }
            try {
                self::savePost(['ID' => $read['id']]);
            } catch (Throwable $refreshError) {
                // Metadata restoration can succeed even if a plugin's save
                // hook keeps failing. Do not report transactional success.
            }
            throw new Multioto_Agent_Rpc_Error(-32000, 'שמירת SEO נכשלה. בוצע ניסיון לשחזר שדות שנשמרו; יש לקרוא את המצב מחדש.');
        }
        return ['provider' => $read['provider']] + self::result($read['id'], $read['values'], $after, array_keys($values));
    }

    private static function setMeta(int $id, string $key, $value): void
    {
        if ($value === null) {
            delete_post_meta($id, $key);
        } else {
            update_post_meta($id, $key, wp_slash($value));
        }
        $actual = metadata_exists('post', $id, $key) ? (string) get_post_meta($id, $key, true) : null;
        if ($actual !== $value) {
            throw new Multioto_Agent_Rpc_Error(-32000, 'שמירת שדה SEO נכשלה.');
        }
    }

    private static function contentPost(array $args)
    {
        $post = self::post($args, true);
        if (get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder' || get_post_meta($post->ID, '_elementor_data', true)) {
            self::fail('תוכן Elementor מחייב את כלי Elementor הייעודיים.');
        }
        if (strlen($post->post_content) > 500000) {
            self::fail('התוכן גדול מדי לעריכת קישורים בטוחה.');
        }
        return $post;
    }

    private static function document(string $content): array
    {
        if (! class_exists('DOMDocument')) {
            self::fail('נדרשת הרחבת DOM בשרת לעריכת קישורים.');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="multioto-link-root">'.$content.'</div></body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        return [$document, new DOMXPath($document)];
    }

    private static function sameSite(string $url): bool
    {
        $target = wp_parse_url($url);
        $site = wp_parse_url(home_url('/'));
        if (! is_array($target) || ! is_array($site) || isset($target['user']) || isset($target['pass'])) {
            return false;
        }
        if (! isset($target['host'])) {
            return ! isset($target['scheme']) && substr($url, 0, 1) === '/' && substr($url, 0, 2) !== '//';
        }
        return in_array(strtolower($target['scheme'] ?? ''), ['http', 'https'], true)
            && strtolower($target['host']) === strtolower($site['host'] ?? '')
            && ($target['port'] ?? null) === ($site['port'] ?? null);
    }

    private static function linksGet(array $args): array
    {
        $post = self::contentPost($args);
        list($document, $xpath) = self::document($post->post_content);
        $links = [];
        foreach ($xpath->query('//a[@href]') as $anchor) {
            $href = $anchor->getAttribute('href');
            if (self::sameSite($href)) {
                $links[] = ['text' => $anchor->textContent, 'url' => $href];
            }
        }
        return ['id' => (int) $post->ID, 'label' => (string) $post->post_title, 'values' => ['content' => (string) $post->post_content], 'links' => $links];
    }

    private static function linkUpdate(array $args): array
    {
        $post = self::contentPost($args);
        $current = ['content' => (string) $post->post_content];
        $values = $args['values'] ?? null;
        if (! is_array($values) || ! is_array($args['expected'] ?? null)) {
            self::fail('נדרשים values ו-expected.content.');
        }
        self::expect('content', $args['expected'], $current);
        if (array_keys($values) === ['content']) {
            // Reserved for the approval service's saved undo snapshot.
            if (! is_string($values['content']) || strlen($values['content']) > 500000) {
                self::fail('תוכן לשחזור אינו תקין.');
            }
            $content = $values['content'];
        } else {
            if (count($values) !== 2 || ! isset($values['text'], $values['target_id']) || ! is_string($values['text']) || trim($values['text']) === '' || strlen($values['text']) > 500 || strpos($values['text'], '<') !== false) {
                self::fail('נדרשים text לא ריק ו-target_id בלבד.');
            }
            $target = self::post(['id' => $values['target_id']], true);
            $url = (string) get_permalink($target->ID);
            if ($target->post_status !== 'publish' || $target->post_password !== '' || ! self::sameSite($url)) {
                self::fail('יעד הקישור חייב להיות תוכן ציבורי מפורסם באותו אתר.');
            }
            $content = self::insertLink($post->post_content, $values['text'], $url);
        }
        if ($content === $current['content']) {
            return self::result((int) $post->ID, $current, $current, ['content']);
        }
        self::savePost(['ID' => (int) $post->ID, 'post_content' => $content]);
        return self::result((int) $post->ID, $current, ['content' => (string) get_post($post->ID)->post_content], ['content']);
    }

    /** Preserve every original byte outside the chosen text occurrence. */
    private static function insertLink(string $content, string $text, string $url): string
    {
        list($document, $xpath) = self::document($content);
        $blocked = ['a', 'script', 'style', 'textarea', 'title', 'code', 'pre', 'noscript', 'template', 'svg', 'math'];
        $matches = 0;
        foreach ($xpath->query('//body//text()') as $node) {
            $safe = true;
            for ($parent = $node->parentNode; $parent; $parent = $parent->parentNode) {
                if (in_array(strtolower($parent->nodeName), $blocked, true)) {
                    $safe = false;
                    break;
                }
            }
            if ($safe) {
                $matches += substr_count($node->nodeValue, $text);
            }
        }
        if ($matches !== 1) {
            self::fail('הטקסט צריך להופיע פעם אחת בטקסט רגיל שאינו מקושר. יש לבחור טקסט מדויק יותר.');
        }
        $parts = wp_html_split($content);
        $stack = [];
        $candidates = [];
        foreach ($parts as $index => $part) {
            if (substr($part, 0, 1) === '<') {
                if (preg_match('~^</\s*([a-zA-Z][\w:-]*)\s*>$~', $part, $m)) {
                    $name = strtolower($m[1]);
                    if (in_array($name, $blocked, true) && end($stack) === $name) {
                        array_pop($stack);
                    }
                } elseif (preg_match('~^<\s*([a-zA-Z][\w:-]*)\b~', $part, $m) && in_array(strtolower($m[1]), $blocked, true)) {
                    $stack[] = strtolower($m[1]);
                }
                continue;
            }
            if (! $stack && substr_count($part, $text) > 0) {
                // Decode entities for matching, but require an exact literal
                // occurrence so no unrelated entity spelling is rewritten.
                if (strpos(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $text) !== false) {
                    for ($n = 0; $n < substr_count($part, $text); $n++) {
                        $candidates[] = $index;
                    }
                }
            }
        }
        if (count($candidates) !== 1) {
            self::fail('הטקסט מפוצל בתגיות או בישויות HTML; יש לבחור קטע טקסט פשוט ומדויק.');
        }
        $anchor = $document->createElement('a');
        $anchor->setAttribute('href', $url);
        $anchor->appendChild($document->createTextNode($text));
        $index = $candidates[0];
        $offset = strpos($parts[$index], $text);
        $before = substr($parts[$index], 0, $offset);
        $after = substr($parts[$index], $offset + strlen($text));
        $decoded = html_entity_decode($parts[$index], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (html_entity_decode($before, ENT_QUOTES | ENT_HTML5, 'UTF-8').$text.html_entity_decode($after, ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $decoded
            || preg_match('/\[[^\]]*$/', $before) || strpos($text, '[') !== false || strpos($text, ']') !== false) {
            self::fail('לא ניתן לקשר חלק מישות HTML או מהגדרת shortcode.');
        }
        $parts[$index] = substr_replace($parts[$index], $document->saveHTML($anchor), $offset, strlen($text));
        return implode('', $parts);
    }

    private static function settings(): array
    {
        $values = [];
        foreach (self::SETTINGS as $name => $default) {
            $value = get_option($name, $default);
            $values[$name] = is_int($default) ? (int) $value : (is_float($default) ? (float) $value : (string) $value);
        }
        return $values;
    }

    private static function settingsUpdate(array $args): array
    {
        $current = self::settings();
        $values = self::changes($args, $current, array_keys(self::SETTINGS));
        if (array_key_exists('timezone_string', $values) && array_key_exists('gmt_offset', $values)) {
            // WordPress dynamically computes gmt_offset for named zones. Two
            // separate changes preserve an exact, independently reversible
            // snapshot when switching to a manually chosen UTC offset.
            self::fail('יש לשנות timezone_string ו-gmt_offset בפעולות נפרדות.');
        }
        $frontKeys = ['show_on_front', 'page_on_front', 'page_for_posts'];
        $changingFront = (bool) array_intersect($frontKeys, array_keys($values));
        if ($changingFront) {
            foreach ($frontKeys as $name) {
                self::expect($name, $args['expected'], $current);
            }
        }
        foreach ($values as $name => $value) {
            switch ($name) {
                case 'blogname': case 'blogdescription': case 'date_format': case 'time_format':
                    if (! is_string($value) || strlen($value) > 500 || sanitize_text_field($value) !== $value || ($value === '' && in_array($name, ['date_format', 'time_format'], true))) {
                        self::fail('הגדרת טקסט אינה תקינה: '.$name);
                    }
                    break;
                case 'timezone_string':
                    if (! is_string($value) || ($value !== '' && ! in_array($value, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true))) {
                        self::fail('נדרש אזור זמן IANA תקין או מחרוזת ריקה לשימוש בהיסט UTC.');
                    }
                    break;
                case 'gmt_offset':
                    if ((! is_int($value) && ! is_float($value)) || $value < -12 || $value > 14 || fmod((float) $value * 4, 1) !== 0.0) {
                        self::fail('היסט UTC אינו תקין.');
                    }
                    $values[$name] = (float) $value;
                    break;
                case 'start_of_week': self::integer($value, 0, 6); break;
                case 'posts_per_page': self::integer($value, 1, 100); break;
                case 'page_on_front': case 'page_for_posts':
                    self::integer($value);
                    if ($value > 0) {
                        $page = self::post(['id' => $value]);
                        if ($page->post_type !== 'page' || $page->post_status !== 'publish' || $page->post_password !== '') {
                            self::fail('עמוד הבית או הבלוג חייב להיות עמוד ציבורי מפורסם.');
                        }
                    }
                    break;
                case 'show_on_front':
                    if (! in_array($value, ['posts', 'page'], true)) {
                        self::fail('show_on_front חייב להיות posts או page.');
                    }
                    break;
            }
        }
        $all = array_replace($current, $values);
        if ($changingFront && $all['show_on_front'] === 'page') {
            if (! $all['page_on_front'] || $all['page_on_front'] === $all['page_for_posts']) {
                self::fail('נדרש עמוד בית שאינו עמוד הבלוג.');
            }
            foreach (['page_on_front', 'page_for_posts'] as $key) {
                if ($all[$key]) {
                    $page = self::post(['id' => $all[$key]]);
                    if ($page->post_type !== 'page' || $page->post_status !== 'publish' || $page->post_password !== '') {
                        self::fail('עמוד הבית או הבלוג חייב להיות עמוד ציבורי מפורסם.');
                    }
                }
            }
        }
        if (array_key_exists('gmt_offset', $values) && $all['timezone_string'] !== '') {
            self::fail('היסט UTC זמין רק כאשר timezone_string ריק.');
        }
        $written = [];
        try {
            foreach ($values as $name => $value) {
                if ($current[$name] === $value) {
                    continue;
                }
                $written[] = $name;
                update_option($name, $value);
                if (self::settings()[$name] !== $value) {
                    throw new Multioto_Agent_Rpc_Error(-32000, 'שמירת הגדרת האתר נכשלה.');
                }
            }
            $after = self::settings();
            if (! self::sameValues($after, $values)) {
                throw new Multioto_Agent_Rpc_Error(-32000, 'הגדרות האתר השתנו במהלך השמירה.');
            }
        } catch (Throwable $e) {
            foreach ($written as $name) {
                try {
                    $now = self::settings();
                    if ($now[$name] === $values[$name] && $now[$name] !== $current[$name]) {
                        update_option($name, $current[$name]);
                    }
                } catch (Throwable $restoreError) {
                    // Continue compensation even if another plugin vetoes
                    // restoration of one option.
                }
            }
            throw new Multioto_Agent_Rpc_Error(-32000, 'שמירת הגדרות האתר נכשלה. בוצע ניסיון לשחזר שדות שנשמרו; יש לקרוא את המצב מחדש.');
        }
        $keys = $changingFront ? array_unique(array_merge(array_keys($values), $frontKeys)) : array_keys($values);
        return self::result(null, $current, $after, $keys);
    }
}
