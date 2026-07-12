<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Models\MonitorCheck;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-site monitoring history: current up/down state, uptime % and average
 * response time over the last week, TLS certificate days-left, and the most
 * recent probes. Read-only — all remediation goes through the approval gate.
 */
class ViewSite extends ViewRecord
{
    protected static string $resource = SiteResource::class;

    protected static string $view = 'filament.sites.monitor';

    /** Window (days) the uptime/response statistics are computed over. */
    protected const STATS_WINDOW_DAYS = 7;

    /** Recent probes shown in the history table. */
    protected const RECENT_LIMIT = 30;

    /**
     * Aggregate uptime %, average response time and probe count over the stats
     * window, computed in the database (no row hydration).
     *
     * @return array{total: int, up: int, uptime: ?float, avg_ms: ?int}
     */
    public function getStatsProperty(): array
    {
        $since = Carbon::now()->subDays(self::STATS_WINDOW_DAYS);

        $checks = $this->record->monitorChecks()
            ->where('checked_at', '>=', $since)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when is_up then 1 else 0 end) as up')
            ->selectRaw('avg(case when is_up then response_ms end) as avg_ms')
            ->first();

        $total = (int) ($checks->total ?? 0);
        $up = (int) ($checks->up ?? 0);

        return [
            'total' => $total,
            'up' => $up,
            'uptime' => $total > 0 ? round($up / $total * 100, 2) : null,
            'avg_ms' => $checks->avg_ms !== null ? (int) round($checks->avg_ms) : null,
        ];
    }

    /**
     * Most recent probes, newest first.
     *
     * @return Collection<int, MonitorCheck>
     */
    public function getRecentChecksProperty(): Collection
    {
        return $this->record->monitorChecks()
            ->latest('checked_at')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    public function getStatsWindowDays(): int
    {
        return self::STATS_WINDOW_DAYS;
    }
}
