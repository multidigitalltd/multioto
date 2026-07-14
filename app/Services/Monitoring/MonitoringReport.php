<?php

namespace App\Services\Monitoring;

use App\Models\Customer;
use Illuminate\Support\Carbon;

/**
 * Builds a customer's monthly monitoring summary — per site: uptime %, average
 * response time, incident count + total downtime, and the SSL / domain expiry
 * we already track. All aggregation is done in the database (no row hydration).
 * Used by the monthly report email and its approval gate.
 */
class MonitoringReport
{
    /**
     * @return array{window_days: int, min_uptime: ?float, sites: array<int, array{
     *   domain: string, uptime: ?float, avg_ms: ?int, incidents: int,
     *   down_minutes: int, ssl_days_left: ?int, domain_expiry_at: ?string}>}
     */
    public function for(Customer $customer): array
    {
        $windowDays = (int) config('billing.monitoring.monthly_report.window_days', 30);
        $since = Carbon::now()->subDays($windowDays);

        $sites = $customer->sites()->where('monitor_enabled', true)->orderBy('domain')->get();
        $rows = [];

        foreach ($sites as $site) {
            $checks = $site->monitorChecks()
                ->where('checked_at', '>=', $since)
                ->selectRaw('count(*) as total')
                ->selectRaw('sum(case when is_up then 1 else 0 end) as up')
                ->selectRaw('avg(case when is_up then response_ms end) as avg_ms')
                ->first();

            $total = (int) ($checks->total ?? 0);
            $up = (int) ($checks->up ?? 0);

            $incidents = $site->incidents()->where('started_at', '>=', $since)->get();
            $downMinutes = (int) $incidents->sum(
                fn ($incident) => $incident->started_at->diffInMinutes($incident->resolved_at ?? Carbon::now())
            );

            $rows[] = [
                'domain' => $site->domain,
                'uptime' => $total > 0 ? round($up / $total * 100, 2) : null,
                'avg_ms' => $checks->avg_ms !== null ? (int) round($checks->avg_ms) : null,
                'incidents' => $incidents->count(),
                'down_minutes' => $downMinutes,
                'ssl_days_left' => $site->ssl_days_left,
                'domain_expiry_at' => $site->domain_expiry_at?->toDateString(),
            ];
        }

        $uptimes = array_values(array_filter(array_column($rows, 'uptime'), fn ($u) => $u !== null));

        return [
            'window_days' => $windowDays,
            'min_uptime' => $uptimes !== [] ? min($uptimes) : null,
            'sites' => $rows,
        ];
    }
}
