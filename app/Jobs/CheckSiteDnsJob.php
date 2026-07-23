<?php

namespace App\Jobs;

use App\Jobs\Concerns\PausesForShabbat;
use App\Models\Site;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\DnsLookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily DNS-change watch for one site: snapshot the domain's A/MX/NS records
 * and alert the team when they differ from the previous snapshot — the site
 * suddenly pointing at another server, mail rerouted, or the nameservers
 * replaced are classic hijack / silent-migration signals.
 *
 * First run stores a baseline silently. A resolver outage (lookup failure) for
 * a record type keeps that type's last known state — an outage never reads as
 * "all records were removed". Honours Shabbat quiet.
 */
class CheckSiteDnsJob implements ShouldQueue
{
    use PausesForShabbat;
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    /** Hebrew names for the watched record types, for the team alert. */
    private const TYPE_LABELS = ['a' => 'A (כתובת האתר)', 'mx' => 'MX (דואר)', 'ns' => 'NS (שרתי שמות)'];

    public function __construct(public int $siteId) {}

    /** @return array<int, mixed> */
    protected function shabbatDispatchArgs(): array
    {
        return [$this->siteId];
    }

    public function handle(DnsLookup $dns, TeamNotifier $team): void
    {
        if ($this->rescheduledForShabbat() || ! config('security.dns_watch.enabled', true)) {
            return;
        }

        $site = Site::with('customer')->find($this->siteId);

        if (! $site || blank($site->domain)) {
            return;
        }

        $current = $dns->records($site->domain);

        // Total resolver failure — nothing definite this cycle; keep the last
        // known snapshot untouched rather than "detecting" a mass removal.
        if ($current['a'] === null && $current['mx'] === null && $current['ns'] === null) {
            return;
        }

        $previous = (array) data_get($site->dns_snapshot, 'records', []);

        // Effective new snapshot: a type whose lookup failed keeps its previous
        // value (unknown ≠ empty), so the stored state only ever holds facts.
        $records = [];
        foreach (['a', 'mx', 'ns'] as $type) {
            $records[$type] = $current[$type] ?? ($previous[$type] ?? null);
        }

        $isFirstRun = $site->dns_snapshot === null;

        $changes = $isFirstRun ? [] : $this->diff($previous, $current);

        $site->update(['dns_snapshot' => [
            'checked_at' => now()->toIso8601String(),
            'records' => $records,
            'changed_at' => $changes !== []
                ? now()->toIso8601String()
                : data_get($site->dns_snapshot, 'changed_at'),
        ]]);

        if ($changes !== []) {
            $this->alert($team, $site, $changes);
        }
    }

    /**
     * Per-type added/removed values. Only types with a definite answer BOTH
     * times participate — an unknown side can't prove a change.
     *
     * @param  array<string, ?list<string>>  $previous
     * @param  array<string, ?list<string>>  $current
     * @return array<string, array{added: list<string>, removed: list<string>}>
     */
    private function diff(array $previous, array $current): array
    {
        $changes = [];

        foreach (['a', 'mx', 'ns'] as $type) {
            $before = $previous[$type] ?? null;
            $after = $current[$type] ?? null;

            if ($before === null || $after === null) {
                continue;
            }

            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));

            if ($added !== [] || $removed !== []) {
                $changes[$type] = ['added' => $added, 'removed' => $removed];
            }
        }

        return $changes;
    }

    /** @param array<string, array{added: list<string>, removed: list<string>}> $changes */
    private function alert(TeamNotifier $team, Site $site, array $changes): void
    {
        $lines = collect($changes)->map(function (array $change, string $type): string {
            $parts = [];
            if ($change['added'] !== []) {
                $parts[] = 'נוסף: '.implode(', ', $change['added']);
            }
            if ($change['removed'] !== []) {
                $parts[] = 'הוסר: '.implode(', ', $change['removed']);
            }

            return (self::TYPE_LABELS[$type] ?? $type).' — '.implode(' · ', $parts);
        })->implode("\n");

        $owner = $site->customer ? " ({$site->customer->name})" : '';

        $team->alert(
            "⚠️ שינוי DNS בדומיין {$site->domain}",
            "זוהה שינוי ברשומות ה-DNS של {$site->domain}{$owner}:\n{$lines}\n\n".
                'אם השינוי לא בוצע על ידכם או על ידי הלקוח — ייתכן שמדובר בהעברת דומיין, שגיאת תצורה או השתלטות. מומלץ לבדוק מיד.',
            rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}",
        );
    }
}
