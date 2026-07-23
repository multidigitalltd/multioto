<?php

namespace App\Filament\Widgets;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Projected income from UPCOMING subscription renewals, bucketed by how soon
 * they fall due. Shown at the top of the חיזוי תזרים page only — kept off the
 * navigation badge and the main dashboard (see $isDiscovered).
 */
class RevenueForecastStats extends BaseWidget
{
    // Page-only: never auto-discovered onto the main dashboard.
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = '60s';

    /** Horizon buckets in days ahead: [label, max-days, color]. */
    private const BUCKETS = [
        ['7 ימים', 7, 'success'],
        ['30 ימים', 30, 'info'],
        ['60 ימים', 60, 'gray'],
        ['90 ימים', 90, 'gray'],
    ];

    protected function getStats(): array
    {
        // Subscriptions that actually renew: not canceled, with a future charge date.
        $rows = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue])
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '>=', now()->startOfDay())
            ->with(['plan', 'customer'])
            ->get();

        $stats = [];

        foreach (self::BUCKETS as [$label, $maxDays, $color]) {
            $slice = $rows->filter(
                fn (Subscription $s): bool => $s->next_charge_at->lessThanOrEqualTo(now()->addDays($maxDays)),
            );

            $stats[] = Stat::make("צפוי ב-{$label}", Money::ils((int) $slice->sum(fn (Subscription $s): int => $s->totalChargeAgorot())))
                ->description($slice->count().' חידושים')
                ->color($slice->isEmpty() ? 'gray' : $color);
        }

        return $stats;
    }
}
