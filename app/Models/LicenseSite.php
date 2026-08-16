<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shop running one licence.
 *
 * The stored URL is normalised, and that normalisation is the whole point: a
 * shop that moves from http to https, or gains a "www.", is the same shop. Left
 * literal, each of those would take a second seat off the customer's quota and
 * arrive here as a support ticket instead of a renewal. What the shop actually
 * reported is kept beside it, because "I changed domain" is answered by seeing
 * what it used to say.
 */
class LicenseSite extends Model
{
    protected $fillable = [
        'license_id', 'site_url', 'reported_url', 'version', 'activated_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * The identity of a shop: no scheme, no "www.", no trailing slash, lower
     * case. Anything that is not a URL comes back as the trimmed original, so a
     * malformed value fails to match rather than matching everything.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $url = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $url);
        $url = (string) preg_replace('#^www\.#i', '', $url);
        $url = rtrim($url, '/');

        return mb_strtolower($url);
    }
}
