<?php

namespace App\Jobs;

use App\Enums\IncidentStatus;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Automation\ApprovalGate;
use App\Services\Hosting\SiteDiagnostics;
use App\Services\Notifications\TeamNotifier;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
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

    public function handle(TeamNotifier $team): void
    {
        $site = Site::with('openIncident')->find($this->siteId);

        if (! $site || ! $site->monitor_enabled) {
            return;
        }

        $url = $site->monitorUrl();

        // Nothing to probe (no domain and no monitor URL) — skip rather than
        // firing a request at an empty/garbage host.
        if ($url === '') {
            return;
        }

        $startedAt = microtime(true);
        // A content check needs the body → GET; otherwise a cheap HEAD.
        $needsBody = filled($site->expected_keyword);

        try {
            $request = Http::timeout((int) config('billing.monitoring.timeout_seconds'));
            // A content check must land on the FINAL page: follow redirects
            // (bare→www, http→https, localized landing) so the keyword search
            // runs against real content, not a 3xx redirect body. A plain uptime
            // probe uses HEAD without following redirects — a 3xx still counts as
            // up (status < 500) and we skip a body we don't need.
            $response = $needsBody
                ? $request->get($url)
                : $request->withoutRedirecting()->head($url);

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

        $responseMs = (int) ((microtime(true) - $startedAt) * 1000);

        $site->monitorChecks()->create([
            'checked_at' => now(),
            'is_up' => $isUp,
            'status_code' => $statusCode,
            'response_ms' => $responseMs,
            'error' => $error,
        ]);

        if ($isUp) {
            $this->handleUp($site, $team);
            $this->evaluateResponseTime($site, $team);
        } else {
            $this->handleDown($site, $team, $error);
        }
    }

    protected function handleDown(Site $site, TeamNotifier $team, ?string $error): void
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

        // Down means down — clear any lingering "slow" alert flag so recovery
        // re-arms it cleanly.
        if ($site->slow_alerted_at !== null) {
            $site->update(['slow_alerted_at' => null]);
        }

        // Alert the team immediately (WhatsApp + email), independent of the
        // ticket — a site being down is the most urgent operational event.
        $team->alert(
            '🔴 אתר לא זמין',
            sprintf('האתר %s (%s) אינו מגיב.%s', $site->domain, $site->customer?->name ?? 'לקוח',
                filled($error) ? "\nשגיאה: {$error}" : ''),
            $this->siteUrl($site),
        );

        // Also raise the in-panel bell for the managers — always visible in the
        // panel even when WhatsApp/email aren't set up.
        $this->notifyAdminsInPanel(
            "🔴 האתר {$site->domain} אינו זמין",
            sprintf('לקוח: %s%s', $site->customer?->name ?? '—', filled($error) ? " · שגיאה: {$error}" : ''),
            'danger',
            $this->siteUrl($site),
        );

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

        // Connected sites also get a full AI investigation over MCP: the operator
        // reads the site's real state (plugins, error log, health) and files any
        // fix as a manager-approval proposal — nothing runs without approval and
        // the kill-switch. Heavy (several model + MCP calls), so it goes on the
        // queue and never blocks incident tracking.
        if ($site->mcp_enabled && config('agent.auto_investigate')) {
            InvestigateSiteJob::dispatch(
                $site->id,
                sprintf(
                    'האתר %s אינו זמין (incident #%d)%s. חקור את הסיבה בעזרת כלי הקריאה והצע תיקון בטוח אם נדרש.',
                    $site->domain,
                    $incident->id,
                    filled($error) ? " — שגיאה: {$error}" : '',
                ),
            );
        }
    }

    /** The real FlyWP driver needs a linked site; 'log' always records intent. */
    protected function fixActionable(Site $site): bool
    {
        return config('billing.hosting.driver') !== 'flywp' || filled($site->hosting_ref);
    }

    protected function handleUp(Site $site, TeamNotifier $team): void
    {
        $incident = $site->openIncident;

        if ($incident === null) {
            return; // Was already up — nothing to resolve or announce.
        }

        $incident->update([
            'status' => IncidentStatus::Resolved,
            'resolved_at' => now(),
        ]);

        // Recovery is as newsworthy as the outage — tell the team, with how
        // long it was down.
        $downFor = $incident->started_at?->diffForHumans(now(), short: true, syntax: true);
        $team->alert(
            '🟢 אתר חזר לפעול',
            sprintf('האתר %s (%s) חזר לפעול תקין.%s', $site->domain, $site->customer?->name ?? 'לקוח',
                $downFor ? "\nזמן ההשבתה: {$downFor}" : ''),
            $this->siteUrl($site),
        );

        $this->notifyAdminsInPanel(
            "🟢 האתר {$site->domain} חזר לפעול",
            sprintf('לקוח: %s%s', $site->customer?->name ?? '—', $downFor ? " · זמן השבתה: {$downFor}" : ''),
            'success',
            $this->siteUrl($site),
        );

        // End-to-end auto-heal: when an APPROVED automation fix executed during
        // this incident, proactively tell the customer we detected and fixed the
        // problem. The job itself verifies a fix actually ran (and the config /
        // template gates), so a site that recovered on its own stays quiet.
        // Dispatched exactly once — handleUp only runs on the open→resolved
        // transition. Best-effort: a queue hiccup must never break recovery.
        try {
            NotifyIncidentAutoResolvedJob::dispatch($site->id, $incident->id);
        } catch (\Throwable $e) {
            Log::warning('Incident auto-resolved dispatch failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }

    /** Raise the in-panel notification bell for every manager (admin). */
    protected function notifyAdminsInPanel(string $title, string $body, string $color, string $url): void
    {
        $admins = User::where('role', UserRole::Admin)->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->icon('heroicon-o-globe-alt')
            ->color($color)
            ->actions([Action::make('view')->label('פתח אתר')->url($url)])
            ->sendToDatabase($admins);
    }

    /**
     * A site can be up yet degraded. When the recent average response time
     * crosses the slow threshold, alert the team once and re-arm only after it
     * speeds back up — mirroring the SSL-expiry alert's no-nagging behaviour.
     */
    protected function evaluateResponseTime(Site $site, TeamNotifier $team): void
    {
        $slowMs = (int) config('billing.monitoring.slow_response_ms', 4000);

        // Average of the last few successful probes — one slow blip shouldn't
        // fire an alert, sustained slowness should.
        $avg = (int) round((float) $site->monitorChecks()
            ->where('is_up', true)
            ->latest('checked_at')
            ->limit(5)
            ->avg('response_ms'));

        if ($avg >= $slowMs && $site->slow_alerted_at === null) {
            $team->alert(
                '🐌 אתר איטי',
                sprintf('האתר %s (%s) זמין אך איטי — זמן תגובה ממוצע %s ms (סף %s ms).',
                    $site->domain, $site->customer?->name ?? 'לקוח', number_format($avg), number_format($slowMs)),
                $this->siteUrl($site),
            );
            $site->update(['slow_alerted_at' => now()]);
        } elseif ($avg < $slowMs && $site->slow_alerted_at !== null) {
            $site->update(['slow_alerted_at' => null]); // Recovered speed — re-arm.
        }
    }

    /** Absolute URL to the site's monitoring page in the admin panel. */
    protected function siteUrl(Site $site): string
    {
        return rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}";
    }
}
