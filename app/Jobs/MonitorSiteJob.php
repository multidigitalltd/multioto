<?php

namespace App\Jobs;

use App\Enums\IncidentStatus;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Site;
use App\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Single uptime probe for one site. Opens an incident (plus an internal
 * ticket) after N consecutive failures, and resolves it on recovery.
 */
class MonitorSiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $siteId) {}

    public function handle(): void
    {
        $site = Site::with('openIncident')->find($this->siteId);

        if (! $site || ! $site->monitor_enabled) {
            return;
        }

        $url = $site->monitor_url ?: 'https://'.$site->domain;
        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('billing.monitoring.timeout_seconds'))
                ->withoutRedirecting()
                ->head($url);

            $isUp = $response->status() < 500;
            $statusCode = $response->status();
            $error = $isUp ? null : 'HTTP '.$response->status();
        } catch (\Throwable $e) {
            $isUp = false;
            $statusCode = null;
            $error = mb_substr($e->getMessage(), 0, 250);
        }

        $site->monitorChecks()->create([
            'checked_at' => now(),
            'is_up' => $isUp,
            'status_code' => $statusCode,
            'response_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'error' => $error,
        ]);

        $isUp ? $this->handleUp($site) : $this->handleDown($site);
    }

    protected function handleDown(Site $site): void
    {
        if ($site->openIncident) {
            return; // Already tracked.
        }

        $threshold = (int) config('billing.monitoring.failures_to_incident');

        $recentFailures = $site->monitorChecks()
            ->latest('checked_at')
            ->limit($threshold)
            ->pluck('is_up')
            ->filter(fn (bool $up) => ! $up)
            ->count();

        if ($recentFailures < $threshold) {
            return; // Wait for consecutive confirmation — avoids flapping alerts.
        }

        $incident = $site->incidents()->create([
            'started_at' => now(),
            'status' => IncidentStatus::Open,
        ]);

        Ticket::create([
            'customer_id' => $site->customer_id,
            'channel' => TicketChannel::Manual,
            'subject' => "האתר {$site->domain} לא זמין (incident #{$incident->id})",
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Urgent,
        ]);
    }

    protected function handleUp(Site $site): void
    {
        $site->openIncident?->update([
            'status' => IncidentStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }
}
