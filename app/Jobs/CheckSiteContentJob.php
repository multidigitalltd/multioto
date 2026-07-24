<?php

namespace App\Jobs;

use App\Jobs\Concerns\PausesForShabbat;
use App\Models\Site;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\ContentFingerprint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Defacement watch for one site: fingerprint the homepage (title + normalized
 * visible text) and compare with the stored baseline. A drastic similarity
 * drop or a classic defacement marker alerts the team once; ordinary edits
 * (similarity above the threshold) roll the baseline forward. A fetch failure
 * changes nothing — downtime is the uptime monitor's job, not this one's.
 *
 * $rebaseline (the "אשר את התוכן הנוכחי" action) accepts the CURRENT content
 * as the new baseline and clears a standing alert — e.g. after a planned
 * redesign the team reviewed.
 */
class CheckSiteContentJob implements ShouldQueue
{
    use PausesForShabbat;
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public function __construct(public int $siteId, public bool $rebaseline = false) {}

    /** @return array<int, mixed> */
    protected function shabbatDispatchArgs(): array
    {
        return [$this->siteId, $this->rebaseline];
    }

    public function handle(ContentFingerprint $fingerprint, TeamNotifier $team): void
    {
        if ($this->rescheduledForShabbat() || ! config('security.defacement.enabled', true)) {
            return;
        }

        $site = Site::with('customer')->find($this->siteId);

        if (! $site || blank($site->domain) || ! $site->monitor_enabled) {
            return;
        }

        try {
            $response = Http::timeout((int) config('billing.monitoring.timeout_seconds', 10))
                ->get($site->monitorUrl());
        } catch (\Throwable) {
            return; // Unreachable — the uptime monitor owns that story.
        }

        if ($response->status() >= 500 || trim((string) $response->body()) === '') {
            return;
        }

        $current = $fingerprint->make((string) $response->body());
        $previous = $site->content_snapshot;

        // First sighting (or an explicit re-baseline): store silently.
        if ($previous === null || $this->rebaseline) {
            $site->update(['content_snapshot' => $this->snapshot($current, similarity: null)]);

            return;
        }

        $similarity = $fingerprint->similarity((string) data_get($previous, 'text', ''), $current['text']);
        $marker = $fingerprint->defacementMarker($current['text']);
        $minSimilarity = (int) config('security.defacement.min_similarity', 45);

        $suspected = $marker !== null || $similarity < $minSimilarity;

        if (! $suspected) {
            // Ordinary content drift — roll the baseline forward so gradual site
            // edits never accumulate into a false "defacement".
            $site->update(['content_snapshot' => $this->snapshot($current, $similarity)]);

            return;
        }

        // Suspected defacement: keep the BASELINE (so the team can compare and
        // a fixed site measures against the real original), record the state,
        // and alert exactly once per standing suspicion.
        $alreadyAlerted = data_get($previous, 'alerted_at') !== null;

        $site->update(['content_snapshot' => array_merge($previous, [
            'checked_at' => now()->toIso8601String(),
            'similarity' => $similarity,
            'suspected' => true,
            'suspected_title' => $current['title'],
            'marker' => $marker,
            'alerted_at' => $alreadyAlerted ? data_get($previous, 'alerted_at') : now()->toIso8601String(),
        ])]);

        if (! $alreadyAlerted) {
            $this->alert($team, $site, $similarity, $marker, $current['title']);
        }
    }

    /** A clean (non-suspected) stored snapshot for the given fingerprint. */
    private function snapshot(array $fingerprint, ?float $similarity): array
    {
        return [
            'checked_at' => now()->toIso8601String(),
            'title' => $fingerprint['title'],
            'text' => $fingerprint['text'],
            'hash' => $fingerprint['hash'],
            'length' => $fingerprint['length'],
            'similarity' => $similarity,
            'suspected' => false,
            'suspected_title' => null,
            'marker' => null,
            'alerted_at' => null,
        ];
    }

    private function alert(TeamNotifier $team, Site $site, float $similarity, ?string $marker, string $title): void
    {
        $owner = $site->customer ? " ({$site->customer->name})" : '';
        $reason = $marker !== null
            ? "זוהה סימן פריצה מובהק בתוכן: \"{$marker}\""
            : "התוכן דומה רק ב-{$similarity}% לתוכן המוכר";

        $team->alert(
            "🚨 חשד להשחתת האתר {$site->domain}",
            "דף הבית של {$site->domain}{$owner} השתנה באופן קיצוני.\n{$reason}.\n".
                'כותרת הדף כעת: '.($title !== '' ? "\"{$title}\"" : '(ללא כותרת)')."\n\n".
                'אם מדובר בעיצוב מחודש מכוון — אשרו את התוכן הנוכחי בעמוד האתר ("אשר את התוכן הנוכחי"). אחרת — בדקו פריצה מיד.',
            rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}",
        );
    }
}
