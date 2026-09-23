<?php

namespace App\Services\Security;

use App\Models\SecurityRule;
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
        return self::watched('security.quarantine.users', SecurityRule::USER);
    }

    /** @return list<string> */
    public static function plugins(): array
    {
        return self::watched('security.quarantine.plugins', SecurityRule::PLUGIN);
    }

    /**
     * Only the two names that ship with the system, without the team's own.
     *
     * Kept apart because the difference matters on screen: these are the ones
     * the companion plugin removes by itself, and a rule the team adds is not.
     * Saying "removed automatically" over a list that mixes the two would tell
     * the team a name is being deleted from their customers' sites when nothing
     * is deleting it.
     *
     * @return list<string>
     */
    public static function builtInUsers(): array
    {
        return self::fromConfig('security.quarantine.users');
    }

    /** @return list<string> */
    public static function builtInPlugins(): array
    {
        return self::fromConfig('security.quarantine.plugins');
    }

    /**
     * The other half: only what the team added from the panel.
     *
     * Needed because the two halves are found in different ways. On a site
     * running the guard, the built-ins are reported by the site itself — it
     * answers from its own hard-coded list and knows nothing about these. So a
     * panel rule has to be looked for by the panel, against the ordinary
     * inventory, or it would be watched for on paper and on no site in practice.
     *
     * @return list<string>
     */
    public static function customUsers(): array
    {
        return array_values(array_diff(self::users(), self::builtInUsers()));
    }

    /** @return list<string> */
    public static function customPlugins(): array
    {
        return array_values(array_diff(self::plugins(), self::builtInPlugins()));
    }

    /**
     * What the panel watches for: the built-ins, plus whatever the team added.
     *
     * The config side is the authority for automatic removal and cannot be
     * edited from here (see SecurityRule). The stored side widens what the
     * panel LOOKS FOR and reports — and, on a site too old to guard itself,
     * what the panel will deactivate.
     *
     * @return list<string>
     */
    private static function watched(string $configKey, string $ruleType): array
    {
        return array_values(array_unique(array_merge(
            self::fromConfig($configKey),
            SecurityRule::valuesFor($ruleType),
        )));
    }

    /** @return list<string> */
    private static function fromConfig(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn ($value): string => mb_strtolower(trim((string) $value)),
            (array) config($key, []),
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
     * Every login in a `wp_user_list` response — all roles, not just admins.
     *
     * That tool answers with a paged envelope (`{total, users: [...]}`) rather
     * than a bare list, so the admin-list parser cannot read it: handed this
     * shape it would walk the envelope's own keys and find no logins at all,
     * which reads exactly like a clean site.
     *
     * @return list<string>
     */
    public static function loginsIn(string $userListJson): array
    {
        $decoded = json_decode(trim($userListJson), true);

        if (! is_array($decoded) || ! is_array($decoded['users'] ?? null)) {
            return [];
        }

        $logins = [];

        foreach ($decoded['users'] as $user) {
            $login = is_array($user) ? trim((string) ($user['login'] ?? '')) : '';

            if ($login !== '') {
                $logins[] = mb_strtolower($login);
            }
        }

        return array_values(array_unique($logins));
    }

    /**
     * Quarantined plugin slugs present in a `wp_plugin_list` response.
     *
     * @return list<string>
     */
    public static function pluginsIn(string $pluginListJson): array
    {
        return array_values(array_intersect(self::plugins(), self::slugsIn($pluginListJson)));
    }

    /**
     * Every plugin folder slug in a `wp_plugin_list` response, matched against
     * nothing. Callers that watch for a narrower list than the full quarantine
     * — the panel's own rules on a site that guards itself — intersect it
     * themselves rather than reading the inventory a second time.
     *
     * Read from the plugin FILE ("wp-file-manager/file_folder_manager.php"),
     * never the display name: a display name is whatever the plugin author — or
     * whoever edited the header after uploading it — decided to write there.
     *
     * @return list<string>
     */
    public static function slugsIn(string $pluginListJson): array
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

        return array_values(array_unique($slugs));
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
