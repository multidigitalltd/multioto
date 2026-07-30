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
 * The money picture in one row, split by HOW it actually arrives — which is the
 * only split that changes what anyone has to do today:
 *
 *   גבייה אוטומטית   — a renewal with a saved card. It charges itself.
 *   גבייה ידנית      — a renewal with no card. Nothing will happen on its own.
 *   חשבוניות עסקה    — demands already sent and still unpaid. Someone must chase.
 *
 * Lumping them into one "expected income" number reads as if it were all
 * automatic, which is exactly the money that quietly never arrives.
 */
class CashFlowStats extends BaseWidget
{
    /** Hidden for team members without this permission module. */
    public static function canView(): bool
    {
        return auth()->user()?->canAccessModule('finance') ?? false;
    }

    // Page-only: never auto-discovered onto the main dashboard, so the amounts
    // stay inside the page that is meant to hold them.
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = '60s';

    /** The horizon the headline numbers describe. */
    private const HORIZON_DAYS = 30;

    protected function getStats(): array
    {
        $renewals = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue])
            ->whereNotNull('next_charge_at')
            ->whereBetween('next_charge_at', [now()->startOfDay(), now()->addDays(self::HORIZON_DAYS)])
            ->with('plan')
            ->get();

        // A saved token is the whole difference: with one the scheduler charges
        // the card, without one the date passes and nothing happens.
        [$automatic, $manual] = $renewals->partition(fn (Subscription $s): bool => $s->token_id !== null);

        $automaticTotal = (int) $automatic->sum(fn (Subscription $s): int => $s->totalChargeAgorot());
        $manualTotal = (int) $manual->sum(fn (Subscription $s): int => $s->totalChargeAgorot());

        $demands = Charge::query()
            ->where('status', ChargeStatus::Pending)
            ->whereNotNull('demand_sent_at')
            ->get(['total_agorot', 'created_at', 'due_at']);

        $demandTotal = (int) $demands->sum('total_agorot');
        // "Pay by" includes the due day itself — only a date strictly before
        // today is late.
        $overdue = $demands->filter(fn (Charge $c): bool => $c->due_at !== null && $c->due_at->lt(now()->startOfDay()));
        $stale = $demands->filter(fn (Charge $c): bool => $this->ageDays($c) > 60);

        return [
            Stat::make('גבייה אוטומטית — '.self::HORIZON_DAYS.' יום', Money::ils($automaticTotal))
                ->description($automatic->count().' חידושים עם כרטיס שמור — ייגבו לבד')
                ->color($automatic->isEmpty() ? 'gray' : 'success'),

            Stat::make('גבייה ידנית — '.self::HORIZON_DAYS.' יום', Money::ils($manualTotal))
                ->description($manual->count().' חידושים בלי כרטיס — לא ייגבו לבד')
                ->color($manual->isEmpty() ? 'gray' : 'danger'),

            Stat::make('חשבוניות עסקה פתוחות', Money::ils($demandTotal))
                ->description($demands->count().' דרישות'.($overdue->isNotEmpty() ? ' · '.$overdue->count().' באיחור' : ''))
                ->color($demands->isEmpty() ? 'gray' : ($overdue->isNotEmpty() ? 'danger' : 'warning')),

            Stat::make('מתוכן ישנות מ-60 יום', Money::ils((int) $stale->sum('total_agorot')))
                ->description($stale->count().' דרישות שנתקעו')
                ->color($stale->isEmpty() ? 'gray' : 'danger'),

            Stat::make('סה״כ צפוי', Money::ils($automaticTotal + $manualTotal + $demandTotal))
                ->description('חידושים ל-'.self::HORIZON_DAYS.' יום וחשבוניות עסקה פתוחות')
                ->color('primary'),
        ];
    }

    /** Age of the debt in days, from the immutable creation date. */
    private function ageDays(Charge $charge): int
    {
        return $charge->created_at
            ? (int) $charge->created_at->startOfDay()->diffInDays(now()->startOfDay())
            : 0;
    }
}
