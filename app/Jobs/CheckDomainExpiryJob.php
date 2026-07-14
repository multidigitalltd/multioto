<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Monitoring\DomainExpiry;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily domain-registration expiry check for one monitored site. Caches the
 * expiry date on the site and alerts the team once when the domain is within
 * the warning window — re-arming only after it's renewed (expiry moves back
 * beyond the threshold), so there's no daily nagging. Mirrors the SSL check.
 */
class CheckDomainExpiryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $siteId) {}

    public function handle(DomainExpiry $domains, TeamNotifier $team): void
    {
        $site = Site::find($this->siteId);

        if (! $site || ! $site->monitor_enabled) {
            return;
        }

        $expiresAt = $domains->expiresAt($site->domain);

        if ($expiresAt === null) {
            return; // TLD has no RDAP / lookup failed — leave the cached value alone.
        }

        $warnDays = (int) config('billing.monitoring.domain_warn_days', 30);
        $daysLeft = (int) floor(now()->startOfDay()->diffInDays($expiresAt, false));

        $prevDaysLeft = $site->domain_expiry_at !== null
            ? (int) floor(now()->startOfDay()->diffInDays($site->domain_expiry_at, false))
            : null;
        $wasAboveThreshold = $prevDaysLeft === null || $prevDaysLeft > $warnDays;

        $site->update(['domain_expiry_at' => $expiresAt->toDateString()]);

        // Alert once on entering the warning window (re-arms after renewal).
        if ($daysLeft <= $warnDays && ($wasAboveThreshold || $site->domain_alerted_at === null)) {
            $team->alert(
                '🌐 דומיין עומד לפוג',
                $daysLeft > 0
                    ? "רישום הדומיין {$site->domain} ({$site->customer?->name}) יפוג בעוד {$daysLeft} ימים. מומלץ לחדש כדי שהאתר לא ירד."
                    : "רישום הדומיין {$site->domain} ({$site->customer?->name}) פג — יש לחדש בדחיפות.",
            );
            $site->update(['domain_alerted_at' => now()]);
        } elseif ($daysLeft > $warnDays) {
            // Renewed — clear the alert flag so a future expiry alerts again.
            $site->update(['domain_alerted_at' => null]);
        }
    }
}
