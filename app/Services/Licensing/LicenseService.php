<?php

namespace App\Services\Licensing;

use App\Models\License;
use App\Models\LicenseSite;
use Illuminate\Support\Facades\DB;

/**
 * The licence contract, exactly as docs/license-api.md defines it.
 *
 * Every answer here is a BUSINESS answer and travels with HTTP 200 — including
 * "expired" and "invalid". That is not politeness: the installed plugin treats
 * a 5xx or a dropped connection as a network fault and KEEPS its previous
 * state, so a shop does not lose its licence because our host had a bad minute.
 * Returning an error status for "not valid" would throw that distinction away
 * and make a real outage look like a mass revocation.
 *
 * The four statuses are the whole vocabulary — anything else the plugin treats
 * as `invalid` — so nothing here may invent a fifth.
 */
class LicenseService
{
    public const VALID = 'valid';

    public const EXPIRED = 'expired';

    public const LIMIT = 'limit';

    public const INVALID = 'invalid';

    /**
     * Bind a key to a shop. Called once, when the customer presses "activate".
     *
     * @return array<string, mixed>
     */
    public function activate(string $key, string $site, ?string $version = null): array
    {
        return $this->resolve($key, $site, register: true, version: $version);
    }

    /**
     * The daily check-in. Identical to activation except that it never takes a
     * seat: a shop that is not on the licence is told so rather than silently
     * being added to it.
     *
     * @return array<string, mixed>
     */
    public function check(string $key, string $site, ?string $version = null): array
    {
        return $this->resolve($key, $site, register: false, version: $version);
    }

    /**
     * Release a shop from its licence so the customer can move it elsewhere.
     *
     * Always succeeds, even for a key we have never seen. The plugin deletes its
     * local copy of the key either way, and failing the call would leave the
     * customer unable to move a licence whose key they mistyped.
     *
     * @return array<string, mixed>
     */
    public function deactivate(string $key, string $site): array
    {
        $license = License::findByKey($key);

        if ($license !== null) {
            $license->sites()->where('site_url', LicenseSite::normalizeUrl($site))->delete();
        }

        return ['status' => 'inactive', 'message' => ''];
    }

    /**
     * The shared answer for activate/check.
     *
     * The order of the questions is the contract's, and it matters: a shop
     * ALREADY on the licence is answered before the quota is consulted, so
     * re-activating after a server move or a restored backup never costs a
     * second seat. That case is not an edge case — it is most of the support
     * mail a licence server generates.
     *
     * @return array<string, mixed>
     */
    private function resolve(string $key, string $site, bool $register, ?string $version): array
    {
        $license = License::findByKey($key);
        $url = LicenseSite::normalizeUrl($site);

        if ($license === null || $license->isRevoked()) {
            return $this->answer(self::INVALID, null, 'מפתח הרישיון אינו מוכר או בוטל. בדקו שהעתקתם אותו במלואו.');
        }

        if ($url === '') {
            return $this->answer(self::INVALID, $license, 'כתובת האתר לא התקבלה, ולכן לא ניתן לשייך את הרישיון.');
        }

        if ($license->hasExpired()) {
            return $this->answer(self::EXPIRED, $license,
                'תוקף הרישיון פג ב-'.$license->expires_at->format('d/m/Y').'. התוסף ימשיך לעבוד, אך לא יקבל עדכונים עד לחידוש.');
        }

        $existing = $license->sites()->where('site_url', $url)->first();

        if ($existing !== null) {
            $this->stamp($license, $existing, $site, $version);

            return $this->answer(self::VALID, $license->fresh());
        }

        if (! $register) {
            return $this->answer(self::INVALID, $license,
                'האתר הזה אינו רשום על הרישיון. הפעילו את הרישיון מתוך האתר, או שחררו אתר אחר.');
        }

        // Counted and inserted in one transaction: two shops activating at the
        // same moment must not both read "one seat free" and both take it.
        return DB::transaction(function () use ($license, $url, $site, $version): array {
            $license = License::query()->whereKey($license->getKey())->lockForUpdate()->first();

            if (! $license->hasFreeSeat()) {
                return $this->answer(self::LIMIT, $license,
                    'הרישיון מכסה '.$license->sites_limit.' אתרים והם כבר בשימוש. שחררו אתר קיים, או הרחיבו את הרישיון.');
            }

            $seat = $license->sites()->create([
                'site_url' => $url,
                'reported_url' => $site,
                'version' => $version,
                'activated_at' => now(),
                'last_seen_at' => now(),
            ]);

            $this->stamp($license, $seat, $site, $version);

            return $this->answer(self::VALID, $license->fresh());
        });
    }

    /**
     * Record that this shop is alive and which build it runs.
     *
     * This is the only thing that distinguishes a live installation from a row
     * somebody created a year ago — without it, the licence list cannot tell a
     * customer using the plugin from one who uninstalled it silently.
     */
    private function stamp(License $license, LicenseSite $seat, string $reported, ?string $version): void
    {
        $seat->forceFill(array_filter([
            'last_seen_at' => now(),
            'reported_url' => $reported,
            'version' => $version,
        ]))->save();

        $license->forceFill(['last_checked_at' => now()])->save();
    }

    /**
     * The response shape every endpoint shares. `message` is Hebrew and is
     * displayed to the customer verbatim, so it says what to do — not what went
     * wrong internally.
     *
     * @return array<string, mixed>
     */
    private function answer(string $status, ?License $license, string $message = ''): array
    {
        return [
            'status' => $status,
            // A licence with no expiry reports an empty string, which the
            // contract defines as "never expires".
            'expires' => $license?->expires_at?->format('Y-m-d') ?? '',
            'sites_limit' => (int) ($license?->sites_limit ?? 0),
            'sites_used' => $license !== null ? $license->seatsUsed() : 0,
            'message' => $message,
        ];
    }
}
