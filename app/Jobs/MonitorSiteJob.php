<?php

namespace App\Jobs;

use App\Enums\IncidentStatus;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Site;
use App\Models\Ticket;
use App\Services\Automation\ApprovalGate;
use App\Services\Hosting\SiteDiagnostics;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        // A content check needs the body → GET; otherwise a cheap HEAD.
        $needsBody = filled($site->expected_keyword);

        try {
            $request = Http::timeout((int) config('billing.monitoring.timeout_seconds'))->withoutRedirecting();
            $response = $needsBody ? $request->get($url) : $request->head($url);

            $statusCode = $response->status();
            $isUp = $statusCode < 500;
            $error = $isUp ? null : 'HTTP '.$statusCode;

            // HTTP 200 but expected content missing → treat as down (WSOD/defacement).
            if ($isUp && $needsBody && ! str_contains((string) $response->body(), (string) $site->expected_keyword)) {
                $isUp = false;
                $error = 'התוכן הצפוי חסר בעמוד';
            }
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

        // Auto-heal loop (still human-approved): diagnose and, if a safe
        // reversible fix is indicated, propose it to the owner on WhatsApp —
        // "האתר נפל, להפעיל מחדש? אשר 45". Best-effort: diagnostics/proposal
        // failing must never break incident tracking.
        try {
            $diagnosis = app(SiteDiagnostics::class)->run($site);

            if (($fix = $diagnosis['suggested_fix']) !== null && $this->fixActionable($site)) {
                app(ApprovalGate::class)->propose(
                    type: 'site_fix',
                    summary: sprintf(
                        "האתר %s (%s) אינו זמין.\n%s\nתיקון מוצע: %s.",
                        $site->domain,
                        $site->customer?->name ?? 'לקוח',
                        $diagnosis['summary'],
                        SiteDiagnostics::FIX_LABELS[$fix] ?? $fix,
                    ),
                    payload: ['site_id' => $site->id, 'fix' => $fix],
                    customerId: $site->customer_id,
                    proposedBy: 'automation',
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Auto-diagnosis/propose failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }

    /** The real FlyWP driver needs a linked site; 'log' always records intent. */
    protected function fixActionable(Site $site): bool
    {
        return config('billing.hosting.driver') !== 'flywp' || filled($site->hosting_ref);
    }

    protected function handleUp(Site $site): void
    {
        $site->openIncident?->update([
            'status' => IncidentStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }
}
