<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Elementor pages: read every text on them, change one text at a time.
 *
 * An Elementor page keeps nothing in `post_content` — the whole layout is a
 * nested JSON tree in the `_elementor_data` meta key. So on most of our
 * customers' sites, editing `post_content` changes a copy of the page that
 * nobody sees, reports success, and leaves the visible page exactly as it was.
 *
 * **The scope here is deliberately small: text, and only text.** Editing that
 * tree freely is the fastest way to break a page a customer paid a designer
 * for — one wrong key and a section disappears with no error anywhere. So this
 * class walks the tree, finds the widgets whose settings hold human-readable
 * copy, and can replace exactly one of those strings by widget id. Structure,
 * layout, styling and widget order are not reachable from here at all.
 *
 * "Change the headline on the homepage" therefore works. "Move the banner"
 * does not, and is answered by a human — which is the right trade for a page
 * somebody's business runs on.
 */
class Multioto_Agent_Elementor
{
    /**
     * Widget setting keys that hold visible copy, by widget type.
     *
     * An allow-list rather than "any string setting": a widget's settings also
     * hold CSS ids, link targets and template names, and rewriting one of those
     * as if it were a sentence breaks the page silently.
     */
    private const TEXT_KEYS = [
        'heading' => ['title'],
        'text-editor' => ['editor'],
        'button' => ['text'],
        'icon-box' => ['title_text', 'description_text'],
        'image-box' => ['title_text', 'description_text'],
        'call-to-action' => ['title', 'description'],
        'testimonial' => ['testimonial_content', 'testimonial_name', 'testimonial_job'],
        'counter' => ['title', 'prefix', 'suffix'],
        'alert' => ['alert_title', 'alert_description'],
        'toggle' => ['title', 'content'],
        'accordion' => ['title', 'content'],
    ];

    public static function active(): bool
    {
        return did_action('elementor/loaded') > 0;
    }

    /** Whether this specific page is built with Elementor. */
    public static function builtWithElementor(int $postId): bool
    {
        return get_post_meta($postId, '_elementor_edit_mode', true) === 'builder';
    }

    /**
     * Every editable text on the page, flattened, each with the widget id that
     * addresses it.
     *
     * Flat and not nested on purpose: the caller does not need the layout, it
     * needs to find the sentence somebody asked to change and the handle to
     * change it by.
     *
     * @return array<int, array<string, string>>
     */
    public static function texts(int $postId): array
    {
        $found = [];

        self::walk(self::data($postId), static function (array $element) use (&$found): void {
            $type = (string) ($element['widgetType'] ?? '');
            $settings = (array) ($element['settings'] ?? []);

            foreach (self::TEXT_KEYS[$type] ?? [] as $key) {
                if (! isset($settings[$key]) || ! is_string($settings[$key]) || trim($settings[$key]) === '') {
                    continue;
                }

                $found[] = [
                    'widget_id' => (string) ($element['id'] ?? ''),
                    'widget' => $type,
                    'setting' => $key,
                    'text' => $settings[$key],
                ];
            }
        });

        return $found;
    }

    /**
     * Replace one text and re-render the page's CSS.
     *
     * The CSS matters: Elementor serves each page from a generated stylesheet,
     * and a page whose content changed while its cached CSS did not can render
     * with the old layout until something else happens to clear it. Clearing it
     * here is the difference between "changed" and "changed and visible".
     *
     * @return array{previous: string, widget: string, setting: string}
     */
    public static function updateText(int $postId, string $widgetId, string $text, ?string $setting = null): array
    {
        $data = self::data($postId);
        $previous = null;
        $widgetType = '';
        $usedSetting = '';

        $updated = self::map($data, static function (array $element) use (
            $widgetId, $text, $setting, &$previous, &$widgetType, &$usedSetting
        ): array {
            if ((string) ($element['id'] ?? '') !== $widgetId || $previous !== null) {
                return $element;
            }

            $type = (string) ($element['widgetType'] ?? '');
            $keys = self::TEXT_KEYS[$type] ?? [];

            if ($keys === []) {
                throw new Multioto_Agent_Rpc_Error(-32602,
                    "הרכיב {$widgetId} מסוג {$type} אינו רכיב טקסט שניתן לערוך דרך הסוכן.");
            }

            // With several text slots on one widget (an icon box has a title and
            // a description) the caller says which; with one, there is nothing
            // to disambiguate.
            $key = $setting !== null && $setting !== '' ? $setting : $keys[0];

            if (! in_array($key, $keys, true)) {
                throw new Multioto_Agent_Rpc_Error(-32602,
                    "השדה {$key} אינו שדה טקסט של רכיב {$type}. השדות האפשריים: ".implode(', ', $keys).'.');
            }

            $settings = (array) ($element['settings'] ?? []);
            $previous = (string) ($settings[$key] ?? '');
            $widgetType = $type;
            $usedSetting = $key;

            // Same sanitiser the block editor uses: allowed markup survives,
            // scripts do not.
            $settings[$key] = wp_kses_post($text);
            $element['settings'] = $settings;

            return $element;
        });

        if ($previous === null) {
            throw new Multioto_Agent_Rpc_Error(-32602, "הרכיב {$widgetId} לא נמצא בעמוד {$postId}.");
        }

        // wp_slash because update_post_meta unslashes, and Elementor's JSON is
        // full of escaped quotes that would be eaten on the way in.
        update_post_meta($postId, '_elementor_data', wp_slash(wp_json_encode($updated)));

        self::clearCache($postId);

        return ['previous' => $previous, 'widget' => $widgetType, 'setting' => $usedSetting];
    }

    /** The page's Elementor tree, or a refusal that says what to use instead. */
    private static function data(int $postId): array
    {
        if (! self::builtWithElementor($postId)) {
            throw new Multioto_Agent_Rpc_Error(-32602,
                "העמוד {$postId} אינו בנוי באלמנטור. לעריכת תוכן רגיל השתמשו ב-wp_content_update.");
        }

        $raw = get_post_meta($postId, '_elementor_data', true);
        $data = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($data)) {
            throw new Multioto_Agent_Rpc_Error(-32000,
                "לא ניתן לקרוא את מבנה האלמנטור של העמוד {$postId}.");
        }

        return $data;
    }

    /** Depth-first over every element in the tree. */
    private static function walk(array $elements, callable $visit): void
    {
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $visit($element);

            if (! empty($element['elements']) && is_array($element['elements'])) {
                self::walk($element['elements'], $visit);
            }
        }
    }

    /** Depth-first rebuild, so a nested widget can be replaced in place. */
    private static function map(array $elements, callable $transform): array
    {
        foreach ($elements as $index => $element) {
            if (! is_array($element)) {
                continue;
            }

            if (! empty($element['elements']) && is_array($element['elements'])) {
                $element['elements'] = self::map($element['elements'], $transform);
            }

            $elements[$index] = $transform($element);
        }

        return $elements;
    }

    /** Regenerate this page's Elementor CSS so the change is actually visible. */
    private static function clearCache(int $postId): void
    {
        if (! class_exists('\Elementor\Plugin')) {
            return;
        }

        $plugin = \Elementor\Plugin::$instance;

        if (isset($plugin->files_manager)) {
            $plugin->files_manager->clear_cache();
        }

        if (class_exists('\Elementor\Core\Files\CSS\Post')) {
            (new \Elementor\Core\Files\CSS\Post($postId))->update();
        }
    }
}
