<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RespectsModuleAccess;
use App\Services\Licensing\LicenseMetrics;
use Filament\Pages\Page;

/**
 * מכירת התוספים במספרים: מה חי, מה נכנס, ומה לא חודש.
 *
 * המסך נבנה סביב שאלה אחת שאי אפשר לענות עליה מטבלת הרישיונות — האם זה עובד.
 * רישיון פעיל אינו הכנסה, ואתר רשום אינו אתר שמריץ את התוסף; לכן כל מספר כאן
 * מוצג לצד המספר שהופך אותו למשמעותי (חי מול רשום, נגבה מול נוסה).
 *
 * שיעור החידוש נמדד על ניסיונות גבייה ולא על תאריכי תפוגה, מפני שתאריך תפוגה
 * זז קדימה בכל חידוש — מדידה לפיו הייתה מחזירה 100% לנצח. כשלא נוסה דבר,
 * המסך אומר "לא היו חידושים לגבות" ולא "0%".
 */
class PluginSales extends Page
{
    // אותה קבוצת ניווט של התוספים והרישיונות ("כלים") — אותו מודול הרשאות.
    use RespectsModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'כלים';

    protected static ?string $navigationLabel = 'מדדי מכירת תוספים';

    protected static ?string $title = 'מכירת תוספים — מה חי, מה נכנס, ומה לא חודש';

    // מיד אחרי "רישיונות" (21).
    protected static ?int $navigationSort = 22;

    protected static string $view = 'filament.pages.plugin-sales';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = app(LicenseMetrics::class);
        $overview = $metrics->overview();

        return [
            'overview' => $overview,
            // כשאין רישיונות בכלל, קיר של אפסים אינו מידע. המסך אומר זאת
            // במפורש ומפנה למקום שבו מוכרים — ולא מדווח על עסק שמתפקד גרוע.
            'started' => $overview['total'] > 0,
            'revenue' => $metrics->revenue(),
            'renewals' => $metrics->renewals(),
            'lapsed' => $metrics->lapsed(),
            'expiringSoon' => $metrics->expiringSoon(),
            'unfulfilled' => $metrics->paidButUnfulfilled(),
            'byProduct' => $metrics->byProduct(),
            'windowDays' => LicenseMetrics::WINDOW_DAYS,
            'staleDays' => LicenseMetrics::SITE_STALE_DAYS,
            'soonDays' => LicenseMetrics::SOON_DAYS,
        ];
    }
}
