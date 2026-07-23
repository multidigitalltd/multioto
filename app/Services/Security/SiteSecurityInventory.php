<?php

namespace App\Services\Security;

/**
 * Turns the companion plugin's wp_plugin_list / wp_theme_list / wp_health JSON
 * into a flat list of installed components with versions — the input the
 * vulnerability matcher needs. Tolerant of missing fields and non-JSON output.
 */
class SiteSecurityInventory
{
    /**
     * @return list<array{type: string, slug: string, name: string, version: string}>
     */
    public static function plugins(string $json): array
    {
        return self::rows($json, function (array $row): ?array {
            $file = (string) ($row['plugin'] ?? '');
            $version = (string) ($row['version'] ?? '');

            if ($file === '' || $version === '') {
                return null;
            }

            // The wp.org slug is the plugin folder ("akismet/akismet.php" → "akismet");
            // a single-file plugin ("hello.php") uses its basename.
            $slug = str_contains($file, '/') ? explode('/', $file)[0] : preg_replace('/\.php$/i', '', $file);

            return ['type' => 'plugin', 'slug' => strtolower((string) $slug), 'name' => (string) ($row['name'] ?? $slug), 'version' => $version];
        });
    }

    /**
     * @return list<array{type: string, slug: string, name: string, version: string}>
     */
    public static function themes(string $json): array
    {
        return self::rows($json, function (array $row): ?array {
            $slug = (string) ($row['stylesheet'] ?? '');
            $version = (string) ($row['version'] ?? '');

            if ($slug === '' || $version === '') {
                return null;
            }

            return ['type' => 'theme', 'slug' => strtolower($slug), 'name' => (string) ($row['name'] ?? $slug), 'version' => $version];
        });
    }

    /**
     * WordPress core as a single component, or null when the version is unknown.
     *
     * @return array{type: string, slug: string, name: string, version: string}|null
     */
    public static function core(string $json): ?array
    {
        $data = json_decode(trim($json), true);
        $version = is_array($data) ? (string) ($data['wp_version'] ?? '') : '';

        return $version !== ''
            ? ['type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'version' => $version]
            : null;
    }

    /**
     * @param  callable(array<string, mixed>): (array{type: string, slug: string, name: string, version: string}|null)  $map
     * @return list<array{type: string, slug: string, name: string, version: string}>
     */
    private static function rows(string $json, callable $map): array
    {
        $decoded = json_decode(trim($json), true);

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $row) {
            if (is_array($row) && ($component = $map($row)) !== null) {
                $out[] = $component;
            }
        }

        return $out;
    }
}
