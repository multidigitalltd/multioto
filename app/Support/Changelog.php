<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The "מה חדש" feed behind the מערכת ועדכונים screen. Releases live in
 * config/changelog.php (newest first); the top entry is the current feature
 * version, with the highlights the team sees after an update.
 */
class Changelog
{
    /**
     * All releases, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function releases(): Collection
    {
        return collect((array) config('changelog.releases'))
            ->filter(fn ($r): bool => is_array($r) && filled($r['version'] ?? null))
            ->values();
    }

    /** The current feature version — the top release, or null if none listed. */
    public static function currentVersion(): ?string
    {
        return self::releases()->first()['version'] ?? null;
    }
}
