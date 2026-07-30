<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RespectsModuleAccess;
use App\Filament\Widgets\CashFlowStats;
use App\Filament\Widgets\OpenDemandsTable;
use App\Filament\Widgets\UpcomingRenewalsTable;
use Filament\Pages\Page;

/**
 * תזרים וגבייה — כל הכסף הצפוי במסך אחד, מחולק לפי איך הוא באמת מגיע:
 *
 *   גבייה אוטומטית — חידוש עם כרטיס שמור. נגבה מעצמו ביום החיוב.
 *   גבייה ידנית    — חידוש בלי כרטיס. ביום החיוב לא יקרה כלום.
 *   חשבוניות עסקה  — דרישות שנשלחו וטרם שולמו. ממתינות לאדם.
 *
 * קודם זה היו שני מסכים — "חיזוי תזרים" שהסתכל קדימה ו"חיזוי גבייה" שהסתכל
 * אחורה — ואף אחד מהם לא ענה על השאלה האמיתית: כמה מהכסף הזה יגיע לבד. שני
 * מספרים נפרדים גם קראו כאילו הכול אוטומטי, וזה בדיוק הכסף שלא מגיע בשקט.
 */
class CashFlow extends Page
{
    use RespectsModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'תזרים וגבייה';

    protected static ?string $title = 'תזרים וגבייה — מה ייגבה לבד ומה ממתין לנו';

    protected static ?int $navigationSort = 22;

    protected static string $view = 'filament.pages.cash-flow';

    protected function getHeaderWidgets(): array
    {
        return [CashFlowStats::class];
    }

    protected function getFooterWidgets(): array
    {
        return [UpcomingRenewalsTable::class, OpenDemandsTable::class];
    }

    /** Both widget rows are full width — the tables need the room. */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
