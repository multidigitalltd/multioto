<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Hosting\SiteDiagnostics;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily TLS-certificate expiry check for one monitored site. Caches days-left
 * on the site and alerts the team once when the certificate is within the
 * warning window — re-arming only after the cert is renewed (days-left climbs
 * back above the threshold), so there's no daily nagging.
 */
class CheckSslExpiryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $siteId) {}

    public function handle(SiteDiagnostics $diagnostics, TeamNotifier $team): void
    {
        $site = Site::find($this->siteId);

        if (! $site || ! $site->monitor_enabled) {
            return;
        }

        $daysLeft = $diagnostics->sslDaysLeft($site->domain);

        if ($daysLeft === null) {
            return; // Couldn't read the certificate — leave the cached value alone.
        }

        $warnDays = (int) config('billing.monitoring.ssl_warn_days', 14);
        $wasAboveThreshold = $site->ssl_days_left === null || $site->ssl_days_left > $warnDays;

        $site->update(['ssl_days_left' => $daysLeft]);

        // Alert once on entering the warning window (re-arms after renewal).
        if ($daysLeft <= $warnDays && ($wasAboveThreshold || $site->ssl_alerted_at === null)) {
            $team->alert(
                '🔒 תעודת SSL עומדת לפוג',
                $daysLeft > 0
                    ? "לאתר {$site->domain} ({$site->customer?->name}) נותרו {$daysLeft} ימים לתעודת ה-SSL. מומלץ לחדש."
                    : "תעודת ה-SSL של {$site->domain} ({$site->customer?->name}) פגה — יש לחדש בדחיפות.",
            );
            $site->update(['ssl_alerted_at' => now()]);
        } elseif ($daysLeft > $warnDays) {
            // Renewed — clear the alert flag so a future expiry alerts again.
            $site->update(['ssl_alerted_at' => null]);
        }
    }
}
