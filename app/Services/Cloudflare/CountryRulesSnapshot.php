<?php

namespace App\Services\Cloudflare;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The last reading of the account's country rules, kept so the panel can show
 * them without going to Cloudflare while somebody waits.
 *
 * Reading the real state costs two requests per zone. Done inside the request
 * that opens the modal, a few dozen zones outlive whatever the web server allows
 * a request to take — the window spins and then dies, and because nothing ever
 * finishes there is nothing to cache either, so the next attempt starts from
 * zero and dies the same way. The reading now happens in a queued job and lands
 * here; the modal only reads what is here.
 *
 * Everything stored is stamped, and every way a reading can fall short of the
 * truth is kept as a fact of its own rather than left to look like the truth:
 *
 *  · nothing has ever been read;
 *  · a refresh FAILED — including the very first one, where there is no reading
 *    behind it at all and the failure is the only thing there is to say;
 *  · rules were changed from the panel after the reading was taken.
 *
 * That last one is a comparison, not a flag: every change bumps a revision, and
 * a reading carries the revision it was taken under. A reading is out of date
 * exactly when its revision is behind the current one — which also settles the
 * race where a refresh already in flight when the change was made finishes
 * afterwards. It cannot present its pre-change bytes as current, and it cannot
 * overwrite a reading taken after it.
 *
 * Bound to a hash of the token: replacing the saved token — possibly for a
 * different Cloudflare account — must never show the previous account's zones.
 */
class CountryRulesSnapshot
{
    /** Kept until replaced. Age is displayed, so an old reading is never mistaken for a fresh one. */
    private const DAYS = 30;

    /** A refresh that died without clearing its flag must not mark the picture "refreshing" forever. */
    private const REFRESH_MINUTES = 15;

    public static function token(): string
    {
        return trim((string) config('billing.cloudflare.api_token'));
    }

    private static function key(string $suffix = ''): string
    {
        return 'cloudflare.country_snapshot.'.sha1(self::token()).$suffix;
    }

    /**
     * The stored reading, or null when nothing has ever been attempted (or there
     * is no token). A record whose only content is a failure IS returned: an
     * invalid token fails before there is anything to show, and "we could not
     * read, here is why" must not be presented as "nobody has looked yet".
     *
     * @return array{data: ?array<string, mixed>, at: ?Carbon, error: ?string, error_at: ?Carbon, stale: bool}|null
     */
    public static function read(): ?array
    {
        if (self::token() === '') {
            return null;
        }

        $record = Cache::get(self::key());

        if (! is_array($record)) {
            return null;
        }

        $data = is_array($record['data'] ?? null) ? $record['data'] : null;
        $error = $record['error'] ?? null;

        if ($data === null && $error === null) {
            return null;
        }

        return [
            'data' => $data,
            'at' => isset($record['at']) ? Carbon::createFromTimestamp((int) $record['at']) : null,
            'error' => $error,
            'error_at' => isset($record['error_at']) ? Carbon::createFromTimestamp((int) $record['error_at']) : null,
            'stale' => (int) ($record['revision'] ?? 0) < self::revision(),
        ];
    }

    /**
     * A successful reading replaces everything, including any earlier failure.
     *
     * $revision is the one that was current when the reading STARTED. A reading
     * that began before a change is stored under that earlier revision, so it
     * shows as out of date rather than as the new state — and it never displaces
     * a reading taken after it, however the two happen to finish.
     */
    public static function store(array $data, ?int $revision = null): void
    {
        $revision ??= self::revision();
        $record = Cache::get(self::key());

        // A newer reading already landed. This one is history.
        if (is_array($record) && (int) ($record['revision'] ?? 0) > $revision) {
            return;
        }

        Cache::put(self::key(), [
            'data' => $data,
            'at' => now()->timestamp,
            'error' => null,
            'error_at' => null,
            'revision' => $revision,
        ], now()->addDays(self::DAYS));
    }

    /**
     * A failed refresh keeps whatever reading was there — it is still the best
     * account of the rules we have — and records that it is now unverified, and
     * why. With no reading behind it, the failure stands on its own.
     */
    public static function storeFailure(string $message): void
    {
        $record = Cache::get(self::key());
        $record = is_array($record) ? $record : [];

        Cache::put(self::key(), [
            'data' => is_array($record['data'] ?? null) ? $record['data'] : null,
            'at' => $record['at'] ?? null,
            'error' => $message,
            'error_at' => now()->timestamp,
            'revision' => (int) ($record['revision'] ?? self::revision()),
        ], now()->addDays(self::DAYS));
    }

    /**
     * Rules were just changed from the panel: everything read until now describes
     * the state before that change.
     */
    public static function markStale(): void
    {
        Cache::put(self::key('.revision'), self::revision() + 1, now()->addDays(self::DAYS));
    }

    /** How many times the rules have been changed from the panel. */
    public static function revision(): int
    {
        return (int) Cache::get(self::key('.revision'), 0);
    }

    public static function markRefreshing(): void
    {
        Cache::put(self::key('.refreshing'), true, now()->addMinutes(self::REFRESH_MINUTES));
    }

    public static function finishedRefreshing(): void
    {
        Cache::forget(self::key('.refreshing'));
    }

    public static function isRefreshing(): bool
    {
        return self::token() !== '' && (bool) Cache::get(self::key('.refreshing'));
    }

    public static function forget(): void
    {
        Cache::forget(self::key());
        Cache::forget(self::key('.refreshing'));
    }
}
