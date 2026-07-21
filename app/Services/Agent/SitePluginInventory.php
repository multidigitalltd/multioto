<?php

namespace App\Services\Agent;

use Illuminate\Support\Str;

/**
 * Turns the free-text output of the companion plugin's `wp_plugin_list` /
 * `wp_theme_list` tools into a stable set of plugin/theme identities that
 * survives version bumps — so the change watcher only alerts on a genuinely new
 * install, not on an update. Tolerant of both JSON and plain-text/table output.
 */
class SitePluginInventory
{
    /**
     * @return list<string> sorted, de-duplicated identities
     */
    public static function identities(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $decoded = json_decode($text, true);

        $rows = is_array($decoded)
            ? self::fromJson($decoded)
            : preg_split('/\r?\n/', $text);

        return collect($rows)
            ->map(fn ($row): string => self::normalize(is_array($row) ? self::pickName($row) : (string) $row))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int|string, mixed>  $decoded
     * @return list<mixed>
     */
    private static function fromJson(array $decoded): array
    {
        // A bare list of names, or a list of rows keyed by slug/name.
        return array_is_list($decoded) ? $decoded : array_values($decoded);
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * Prefer the STABLE identifier (a plugin file path / theme stylesheet /
     * slug) over the mutable display name, so a plugin that changes its display
     * name isn't reported as a new install, and two plugins that happen to share
     * a display name aren't collapsed into one identity.
     */
    private static function pickName(array $row): string
    {
        foreach (['plugin', 'slug', 'stylesheet', 'file', 'name', 'title', 'theme'] as $key) {
            if (filled($row[$key] ?? null)) {
                return (string) $row[$key];
            }
        }

        return '';
    }

    /**
     * A version- and status-independent identity: lowercase the value, strip
     * version numbers and common status words, and collapse separators — so
     * "Akismet | active | 5.3.2" and "akismet inactive 5.4" map to the same key.
     */
    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        // Drop version-like tokens (1.2, v3.4.5, 2.0-beta).
        $value = preg_replace('/\bv?\d+(?:\.\d+)+[\w.-]*/u', '', (string) $value);
        // Drop common status / update words (both languages).
        $value = preg_replace(
            '/\b(active|inactive|enabled|disabled|update available|available|auto-?update|must-use|dropin|none|yes|no|on|off|פעיל|לא פעיל|כבוי|מעודכן|עדכון|זמין)\b/u',
            '',
            (string) $value,
        );
        // Collapse table separators / whitespace.
        $value = trim((string) preg_replace('/[\s|,\t]+/u', ' ', (string) $value));

        return Str::limit($value, 80, '');
    }
}
