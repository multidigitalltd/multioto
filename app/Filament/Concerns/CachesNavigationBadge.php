<?php

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * A sidebar badge that is counted once a minute instead of once a request.
 *
 * Filament renders the whole navigation on every response — not only a page
 * load, but every table sort, every filter, every pagination click and every
 * widget poll. Eleven screens carry a badge, so eleven aggregate counts ran
 * before anything the person asked for was rendered, several of them over the
 * largest tables in the system.
 *
 * None of those numbers needs to be accurate to the second. A debtor count that
 * is a minute old is the same information to the person reading it, and the
 * screen behind the badge is live anyway — so the badge is the one place in the
 * panel where staleness costs nothing and recomputing costs the most.
 *
 * The cache key carries the user id, because several of these counts are scoped
 * to who is looking ("my tasks", anything behind a module grant) and a shared
 * key would quietly show one person another's number.
 */
trait CachesNavigationBadge
{
    /**
     * How long a badge may be stale. Short enough that nobody notices.
     *
     * A method rather than a property so a screen can raise it: PHP refuses a
     * class that redeclares a trait's property with a different default, and the
     * one count here that reads a whole table deserves a longer window than the
     * ones that read an indexed slice.
     */
    protected static function badgeCacheSeconds(): int
    {
        return 60;
    }

    /**
     * Count through the cache, and return it the way Filament wants it: the
     * number as a string, or null for "no badge at all".
     *
     * Two different failures, and only one of them may hide the number:
     *
     *  - **The cache is unreachable.** Count anyway. These badges are how a
     *    person sees that jobs are failing or that errors are piling up, and the
     *    incident that takes Redis down is exactly the moment those numbers
     *    matter most — going quiet then would be the worst possible timing.
     *  - **The count itself throws** — a table that does not exist yet, one
     *    minute after a deploy. Then there is no number to show, and a badge is
     *    decoration on every screen in the panel: it must not take the whole
     *    navigation down with it.
     */
    protected static function cachedBadge(Closure $count, ?string $key = null): ?string
    {
        $cacheKey = sprintf('nav-badge:%s:%s:%s', static::class, $key ?? 'default', auth()->id() ?? 'guest');

        try {
            $value = Cache::remember(
                $cacheKey,
                now()->addSeconds(static::badgeCacheSeconds()),
                fn (): int => (int) $count(),
            );
        } catch (\Throwable) {
            // Could have been the cache or the count — ask the count directly to
            // find out, and give up only if that fails too.
            $value = rescue(fn (): int => (int) $count(), null, report: false);
        }

        return $value !== null && $value > 0 ? (string) $value : null;
    }
}
