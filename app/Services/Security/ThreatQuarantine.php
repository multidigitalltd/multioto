<?php

namespace App\Services\Security;

use App\Services\Agent\SitePluginInventory;

/**
 * The panel's own reading of "is one of the quarantined things on this site".
 *
 * This exists for the sites the guard cannot cover: one running a companion
 * plugin older than 1.5.0, where nothing on the site is watching. There the
 * panel reads the ordinary inventory tools and decides for itself.
 *
 * Matching is deliberately exact, never a pattern. `sys_maint2` is not
 * `sys_maint`, and deleting it because it looked close enough would be this
 * system removing an account a customer created — the failure that would end
 * any trust in an automatic removal. A near-miss is reported, not acted on.
 */
class ThreatQuarantine
{
    /** @return list<string> */
    public static function users(): array
    {
        return array_values(array_filter(array_map(
            static fn ($login): string => mb_strtolower(trim((string) $login)),
            (array) config('security.quarantine.users', []),
        )));
    }

    /** @return list<string> */
    public static function plugins(): array
    {
        return array_values(array_filter(array_map(
            static fn ($slug): string => mb_strtolower(trim((string) $slug)),
            (array) config('security.quarantine.plugins', []),
        )));
    }

    public static function enabled(): bool
    {
        return (bool) config('security.quarantine.enabled', true);
    }

    /**
     * Quarantined logins present in a `wp_admin_list` / `wp_user_list` response.
     *
     * @return list<string>
     */
    public static function usersIn(string $adminListJson): array
    {
        $present = SitePluginInventory::adminIdentities($adminListJson);

        return array_values(array_intersect(self::users(), $present));
    }

    /**
     * Quarantined plugin slugs present in a `wp_plugin_list` response.
     *
     * Read from the plugin FILE ("wp-file-manager/file_folder_manager.php"),
     * never the display name: a display name is whatever the plugin author — or
     * whoever edited the header after uploading it — decided to write there.
     *
     * @return list<string>
     */
    public static function pluginsIn(string $pluginListJson): array
    {
        $decoded = json_decode(trim($pluginListJson), true);

        if (! is_array($decoded)) {
            return [];
        }

        $slugs = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $file = trim((string) ($row['plugin'] ?? $row['file'] ?? $row['slug'] ?? ''));

            if ($file === '') {
                continue;
            }

            $slugs[] = str_contains($file, '/')
                ? mb_strtolower(explode('/', $file)[0])
                : mb_strtolower((string) preg_replace('/\.php$/i', '', $file));
        }

        return array_values(array_intersect(self::plugins(), array_unique($slugs)));
    }

    /**
     * The plugin file to deactivate for a slug, from the same response — the
     * containment step for a site whose plugin is too old to delete it itself.
     */
    public static function pluginFileFor(string $pluginListJson, string $slug): ?string
    {
        $decoded = json_decode(trim($pluginListJson), true);

        if (! is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $row) {
            $file = is_array($row) ? trim((string) ($row['plugin'] ?? $row['file'] ?? '')) : '';

            if ($file !== '' && str_contains($file, '/') && mb_strtolower(explode('/', $file)[0]) === $slug) {
                return $file;
            }
        }

        return null;
    }
}
