<?php

namespace App\Filament\Widgets;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The aging breakdown of open collections as stat squares, shown at the top of
 * the חיזוי גבייה page: one square per age bucket plus a grand total. Kept off
 * the navigation badge on purpose — the numbers appear only inside the page.
 */
class CollectionForecastStats extends BaseWidget
{
    // NOT auto-discovered onto the main dashboard — it is registered explicitly
    // as a header widget of the חיזוי גבייה page only. This keeps the collection
    // amounts inside that page (the whole point: they must not leak elsewhere).
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = '30s';

    /** Age buckets in days: [label, min-inclusive, max-inclusive|null, color]. */
    private const BUCKETS = [
        ['0–30 ימים', 0, 30, 'gray'],
        ['31–60 ימים', 31, 60, 'warning'],
        ['61–90 ימים', 61, 90, 'danger'],
        ['מעל 90 ימים', 91, null, 'danger'],
    ];

    protected function getStats(): array
    {
        $rows = Charge::query()
            ->where('status', ChargeStatus::Pending)
            ->whereNotNull('demand_sent_at')
            ->get(['total_agorot', 'created_at']);

        $stats = [];

        foreach (self::BUCKETS as [$label, $min, $max, $color]) {
            $bucket = $rows->filter(function (Charge $c) use ($min, $max): bool {
                $age = $this->ageDays($c);

                return $age >= $min && ($max === null || $age <= $max);
            });

            $stats[] = Stat::make($label, Money::ils((int) $bucket->sum('total_agorot')))
                ->description($bucket->count().' דרישות')
                ->color($bucket->isEmpty() ? 'gray' : $color);
        }

        $stats[] = Stat::make('סה״כ פתוח', Money::ils((int) $rows->sum('total_agorot')))
            ->description($rows->count().' דרישות פתוחות')
            ->color('primary');

        return $stats;
    }

    /** Age of the debt in days, from the immutable creation date. */
    private function ageDays(Charge $charge): int
    {
        return $charge->created_at
            ? (int) $charge->created_at->startOfDay()->diffInDays(now()->startOfDay())
            : 0;
    }
}
