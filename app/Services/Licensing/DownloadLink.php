<?php

namespace App\Services\Licensing;

use App\Models\License;
use App\Models\LicenseSite;
use Illuminate\Support\Carbon;

/**
 * The signed link WordPress downloads a release from.
 *
 * WordPress fetches this URL itself, with no headers from the plugin — so
 * everything needed to authorise the download has to survive inside the address
 * alone. Three properties, each answering a way a link leaks:
 *
 *  · **The key is not in it.** Download URLs end up in proxy logs, in support
 *    tickets and in screenshots. What travels is the key's HASH, which is
 *    useless for activating anything.
 *
 *  · **It expires.** Signed over an expiry we choose, so a link found later is
 *    a link that no longer works.
 *
 *  · **It is bound to one shop.** The site is part of what is signed, so a link
 *    lifted from one customer will not serve another.
 *
 * And the signature is compared in constant time — a byte-by-byte comparison
 * that returns early is a comparison an attacker can measure.
 */
class DownloadLink
{
    /**
     * @return array{k: string, site: string, exp: int, sig: string}
     */
    public static function parameters(License $license, string $site): array
    {
        $expires = now()->addMinutes(max(1, (int) config('licensing.download_ttl_minutes', 60)))->timestamp;
        $url = LicenseSite::normalizeUrl($site);

        return [
            'k' => $license->key_hash,
            'site' => $url,
            'exp' => $expires,
            'sig' => self::sign($license->key_hash, $url, $expires),
        ];
    }

    /** The full URL handed to WordPress. */
    public static function url(License $license, string $site): string
    {
        return route('license.download', self::parameters($license, $site));
    }

    /**
     * The licence this link is good for, or null.
     *
     * Every reason to refuse returns the same null: an expired link, a forged
     * signature, a revoked licence and a shop that has since been released all
     * look identical from outside. Anything more specific would be a way to
     * learn which keys exist.
     */
    public static function verify(string $keyHash, string $site, int $expires, string $signature): ?License
    {
        if ($expires < now()->timestamp) {
            return null;
        }

        $url = LicenseSite::normalizeUrl($site);

        if (! hash_equals(self::sign($keyHash, $url, $expires), $signature)) {
            return null;
        }

        $license = License::query()->where('key_hash', $keyHash)->first();

        // Signed once, checked again now: a licence revoked or expired in the
        // hour since the link was made must not still be downloadable.
        if ($license === null || ! $license->isUsable()) {
            return null;
        }

        return $license->sites()->where('site_url', $url)->exists() ? $license : null;
    }

    private static function sign(string $keyHash, string $site, int $expires): string
    {
        $secret = (string) config('licensing.secret');

        if ($secret === '') {
            throw new \RuntimeException('חסר סוד לשרת הרישיונות (LICENSE_SERVER_SECRET).');
        }

        return hash_hmac('sha256', $keyHash.'|'.$site.'|'.$expires, $secret);
    }

    /** Human-readable expiry, for a log line or a support answer. */
    public static function expiresAt(int $expires): Carbon
    {
        return Carbon::createFromTimestamp($expires);
    }
}
