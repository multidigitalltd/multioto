<?php

namespace App\Jobs;

use App\Jobs\Concerns\PausesForShabbat;
use App\Models\Site;
use App\Models\SystemLog;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\DomainReputationClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Check one site's domain against public spam/malware blocklists (URLhaus +
 * Spamhaus via URLhaus, and Google Safe Browsing when a key is set), store the
 * result on the site, and alert the team about newly-appeared listings.
 *
 * External, read-only, best-effort. If no source could run, the previous result
 * is left untouched (an outage never reads as "clean"). Honours Shabbat quiet.
 */
class CheckSiteReputationJob implements ShouldQueue
{
    use PausesForShabbat;
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public function __construct(public int $siteId) {}

    /** @return array<int, mixed> */
    protected function shabbatDispatchArgs(): array
    {
        return [$this->siteId];
    }

    public function handle(DomainReputationClient $reputation, TeamNotifier $team): void
    {
        if ($this->rescheduledForShabbat() || ! config('security.reputation.enabled', true)) {
            return;
        }

        $site = Site::with('customer')->find($this->siteId);

        if (! $site || blank($site->domain)) {
            return;
        }

        $result = $reputation->check($site->domain);

        // Providers that actually ran this cycle (a definite answer). If none ran,
        // don't overwrite the last known state with an empty "clean" result — but
        // DO leave a visible trace: an operator who clicked "בדיקת מוניטין" and
        // sees nothing at all can't tell a queued job from a dead end.
        $ranProviders = array_keys(array_filter($result['sources']));

        if ($ranProviders === []) {
            $site->update(['reputation_scan' => array_merge((array) $site->reputation_scan, [
                'last_run_at' => now()->toIso8601String(),
                'last_run_status' => 'no_source',
            ])]);

            SystemLog::record('warning', 'monitoring',
                "בדיקת מוניטין לדומיין {$site->domain} רצה אך אף מקור חיצוני לא היה זמין (URLhaus / Spamhaus). ייתכן שהשרת חוסם את הבקשות או שהמקורות לא זמינים כרגע.",
                ['site_id' => $site->id]);

            return;
        }

        $previous = (array) data_get($site->reputation_scan, 'listings', []);
        $previousKeys = collect($previous)->map(fn (array $l): string => self::key($l))->all();

        // Keep previous findings from providers that did NOT run this cycle (e.g.
        // Safe Browsing timed out while URLhaus succeeded) — otherwise a partial
        // scan would drop them and re-alert them as new when the provider recovers.
        $preserved = array_values(array_filter(
            $previous,
            fn (array $l): bool => ! in_array($l['provider'] ?? '', $ranProviders, true),
        ));

        $listings = array_merge($preserved, $result['listings']);

        $site->update(['reputation_scan' => [
            'checked_at' => now()->toIso8601String(),
            'sources' => $result['sources'],
            'listings' => $listings,
            'last_run_at' => now()->toIso8601String(),
            'last_run_status' => 'ok',
        ]]);

        $fresh = array_values(array_filter(
            $result['listings'],
            fn (array $l): bool => ! in_array(self::key($l), $previousKeys, true),
        ));

        if ($fresh !== []) {
            $this->alert($team, $site, $fresh);
        }
    }

    /** A stable identity for one listing so the same flag isn't re-alerted. */
    private static function key(array $listing): string
    {
        return implode('|', [$listing['source'] ?? '', $listing['type'] ?? '', $listing['detail'] ?? '']);
    }

    /**
     * @param  list<array<string, mixed>>  $fresh
     */
    private function alert(TeamNotifier $team, Site $site, array $fresh): void
    {
        $lines = collect($fresh)->map(function (array $l): string {
            $icon = ($l['type'] ?? '') === 'spam' ? '📧' : '🦠';

            return "{$icon} {$l['source']}: {$l['detail']}";
        })->implode("\n");

        $owner = $site->customer ? " ({$site->customer->name})" : '';

        $team->alert(
            "🚫 האתר {$site->domain} מופיע ברשימת חסימה",
            "בבדיקת מוניטין נמצא שהדומיין {$site->domain}{$owner} מופיע במאגר ספאם/נוזקות:\n{$lines}\n\n".
                'רישום כזה פוגע בדליברביליות של מיילים ובדירוג בגוגל — מומלץ לבדוק ולבקש הסרה.',
            rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}",
        );
    }
}
