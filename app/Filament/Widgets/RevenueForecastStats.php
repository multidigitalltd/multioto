<?php

namespace App\Filament\Widgets;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Charge;
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
        $renewals90 = 0;

        foreach (self::BUCKETS as [$label, $maxDays, $color]) {
            $slice = $rows->filter(
                fn (Subscription $s): bool => $s->next_charge_at->lessThanOrEqualTo(now()->addDays($maxDays)),
            );

            $sum = (int) $slice->sum(fn (Subscription $s): int => $s->totalChargeAgorot());
            $renewals90 = $sum; // the last (widest, 90-day) bucket is the 90-day total

            $stats[] = Stat::make("צפוי ב-{$label}", Money::ils($sum))
                ->description($slice->count().' חידושים')
                ->color($slice->isEmpty() ? 'gray' : $color);
        }

        // Open payment demands (חשבוניות עסקה that were sent and not yet paid) are
        // real expected inflow too — without them the forecast understates the
        // scale. Surfaced as their own square plus a combined grand total.
        $demands = Charge::query()
            ->where('status', ChargeStatus::Pending)
            ->whereNotNull('demand_sent_at')
            ->get(['total_agorot', 'due_at']);

        $demandTotal = (int) $demands->sum('total_agorot');
        // "Pay by" includes the due day itself — only a date strictly before today is overdue.
        $overdue = $demands->filter(fn (Charge $c): bool => $c->due_at !== null && $c->due_at->lt(now()->startOfDay()))->count();

        $stats[] = Stat::make('דרישות תשלום פתוחות', Money::ils($demandTotal))
            ->description($demands->count().' דרישות'.($overdue > 0 ? " · {$overdue} באיחור" : ''))
            ->color($demands->isEmpty() ? 'gray' : ($overdue > 0 ? 'danger' : 'warning'));

        $stats[] = Stat::make('סה״כ צפוי (90 יום + דרישות)', Money::ils($renewals90 + $demandTotal))
            ->description('חידושים ל-90 יום ודרישות תשלום פתוחות')
            ->color('primary');

        return $stats;
    }
}
