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
 * Everything stored is stamped, and the three ways a reading can be less than
 * current are each said out loud rather than left to look like fact:
 *
 *  · nothing has been read yet;
 *  · a reading exists but the last refresh FAILED, so it is older than it looks;
 *  · rules were just changed from the panel, so the reading predates the change.
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
     * The stored reading, or null when there is none (or no token at all).
     *
     * @return array{data: array<string, mixed>, at: Carbon, error: ?string, error_at: ?Carbon, stale: bool}|null
     */
    public static function read(): ?array
    {
        if (self::token() === '') {
            return null;
        }

        $record = Cache::get(self::key());

        if (! is_array($record) || ! is_array($record['data'] ?? null)) {
            return null;
        }

        return [
            'data' => $record['data'],
            'at' => Carbon::createFromTimestamp((int) $record['at']),
            'error' => $record['error'] ?? null,
            'error_at' => isset($record['error_at']) ? Carbon::createFromTimestamp((int) $record['error_at']) : null,
            'stale' => (bool) ($record['stale'] ?? false),
        ];
    }

    /** A successful reading replaces everything, including any earlier failure. */
    public static function store(array $data): void
    {
        Cache::put(self::key(), [
            'data' => $data,
            'at' => now()->timestamp,
            'error' => null,
            'error_at' => null,
            'stale' => false,
        ], now()->addDays(self::DAYS));
    }

    /**
     * A failed refresh keeps the previous reading — it is still the best account
     * of the rules we have — but records that it is now unverified, and why.
     */
    public static function storeFailure(string $message): void
    {
        $record = Cache::get(self::key());

        Cache::put(self::key(), [
            'data' => is_array($record['data'] ?? null) ? $record['data'] : null,
            'at' => $record['at'] ?? now()->timestamp,
            'error' => $message,
            'error_at' => now()->timestamp,
            'stale' => (bool) ($record['stale'] ?? false),
        ], now()->addDays(self::DAYS));
    }

    /**
     * Rules were just changed from the panel: what is stored describes the state
     * BEFORE that change. Marked rather than deleted, so the operator keeps the
     * list in front of them while the fresh reading is fetched.
     */
    public static function markStale(): void
    {
        $record = Cache::get(self::key());

        if (! is_array($record)) {
            return;
        }

        $record['stale'] = true;

        Cache::put(self::key(), $record, now()->addDays(self::DAYS));
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
