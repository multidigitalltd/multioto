<?php

namespace App\Filament\Widgets;

use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Customer-satisfaction (CSAT) summary over rated tickets: average score, how
 * many customers rated, and the satisfied share (4–5). Shown on the tickets
 * list only (kept off the main dashboard via $isDiscovered).
 */
class CsatOverview extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $rated = Ticket::query()->whereNotNull('csat_rating');
        $count = (int) $rated->count();

        if ($count === 0) {
            return [
                Stat::make('שביעות רצון (CSAT)', '—')
                    ->description('עדיין אין דירוגים')
                    ->color('gray'),
            ];
        }

        $avg = round((float) $rated->clone()->avg('csat_rating'), 2);
        $satisfied = (int) $rated->clone()->where('csat_rating', '>=', 4)->count();
        $satisfiedPct = (int) round($satisfied / $count * 100);

        return [
            Stat::make('דירוג ממוצע', number_format($avg, 2).' / 5')
                ->description($count.' דירוגים')
                ->color($avg >= 4 ? 'success' : ($avg >= 3 ? 'warning' : 'danger')),
            Stat::make('מרוצים (4–5)', $satisfiedPct.'%')
                ->description($satisfied.' מתוך '.$count)
                ->color($satisfiedPct >= 70 ? 'success' : ($satisfiedPct >= 40 ? 'warning' : 'danger')),
        ];
    }
}
