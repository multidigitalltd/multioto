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
    ];

    /**
     * Widgets whose text lives in repeater rows rather than in plain settings.
     *
     * An accordion does not have a `title` — it has a `tabs` array, one entry
     * per section, each with its own `tab_title` and `tab_content`. Listing it
     * among the plain widgets would advertise a widget type whose text is never
     * returned and can never be addressed, which is worse than not supporting
     * it: the tool would appear to work and find nothing.
     *
     * Rows are addressed as `tabs.0.tab_title`, so one widget id plus one
     * setting path still identifies exactly one string.
     *
     * @var array<string, array{repeater: string, keys: list<string>}>
     */
    private const REPEATER_KEYS = [
        'accordion' => ['repeater' => 'tabs', 'keys' => ['tab_title', 'tab_content']],
        'toggle' => ['repeater' => 'tabs', 'keys' => ['tab_title', 'tab_content']],
        'tabs' => ['repeater' => 'tabs', 'keys' => ['tab_title', 'tab_content']],
        'price-list' => ['repeater' => 'price_list', 'keys' => ['title', 'item_description']],
        'icon-list' => ['repeater' => 'icon_list', 'keys' => ['text']],
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

            foreach (self::textPaths($type, $settings) as $path) {
                $found[] = [
                    'widget_id' => (string) ($element['id'] ?? ''),
                    'widget' => $type,
                    'setting' => $path,
                    'text' => self::read($settings, $path),
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
            $settings = (array) ($element['settings'] ?? []);
            $paths = self::textPaths($type, $settings);

            if ($paths === []) {
                throw new Multioto_Agent_Rpc_Error(-32602,
                    "הרכיב {$widgetId} מסוג {$type} אינו רכיב טקסט שניתן לערוך דרך הסוכן.");
            }

            // With several text slots on one widget — an icon box has a title
            // and a description, an accordion has one pair per section — the
            // caller says which; with one, there is nothing to disambiguate.
            $path = $setting !== null && $setting !== '' ? $setting : $paths[0];

            if (! in_array($path, $paths, true)) {
                throw new Multioto_Agent_Rpc_Error(-32602,
                    "השדה {$path} אינו שדה טקסט של רכיב {$type}. השדות האפשריים: ".implode(', ', $paths).'.');
            }

            $previous = self::read($settings, $path);
            $widgetType = $type;
            $usedSetting = $path;

            // Same sanitiser the block editor uses: allowed markup survives,
            // scripts do not.
            $element['settings'] = self::write($settings, $path, wp_kses_post($text));

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

    /**
     * Every addressable text slot on one widget, as setting paths.
     *
     * A plain widget yields its own keys; a repeater yields one path per row
     * that actually holds text, so an accordion with four sections yields up to
     * eight. Empty slots are skipped — an empty description is not something
     * anybody asked to change, and listing it only makes the real texts harder
     * to find.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private static function textPaths(string $type, array $settings): array
    {
        $paths = [];

        foreach (self::TEXT_KEYS[$type] ?? [] as $key) {
            if (isset($settings[$key]) && is_string($settings[$key]) && trim($settings[$key]) !== '') {
                $paths[] = $key;
            }
        }

        $repeater = self::REPEATER_KEYS[$type] ?? null;

        if ($repeater !== null && ! empty($settings[$repeater['repeater']]) && is_array($settings[$repeater['repeater']])) {
            // The row's own key, not its position: read() and write() address
            // rows by key, and the two must not be able to disagree.
            foreach ($settings[$repeater['repeater']] as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach ($repeater['keys'] as $key) {
                    if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
                        $paths[] = $repeater['repeater'].'.'.$index.'.'.$key;
                    }
                }
            }
        }

        return $paths;
    }

    /** Read a value by dotted path ("tabs.0.tab_title"). */
    private static function read(array $settings, string $path): string
    {
        $value = $settings;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return '';
            }

            $value = $value[$segment];
        }

        return is_string($value) ? $value : '';
    }

    /**
     * Write a value by dotted path, leaving everything else untouched.
     *
     * Rebuilt rather than assigned by reference: a repeater row carries styling
     * and an item id alongside its text, and replacing the row wholesale would
     * drop them.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function write(array $settings, string $path, string $value): array
    {
        $segments = explode('.', $path);
        $target = &$settings;

        foreach ($segments as $depth => $segment) {
            if ($depth === count($segments) - 1) {
                $target[$segment] = $value;

                break;
            }

            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                throw new Multioto_Agent_Rpc_Error(-32602, "הנתיב {$path} אינו קיים ברכיב.");
            }

            $target = &$target[$segment];
        }

        unset($target);

        return $settings;
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
