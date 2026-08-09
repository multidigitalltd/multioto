<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RespectsModuleAccess;
use App\Models\Customer;
use App\Services\Support\MarketingEngagement;
use App\Support\WebhookRejections;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * מדדי הדיוור: כמה נפתחו, מתי פותחים, ומי כבר לא פותח בכלל.
 *
 * המסך הזה קיים כדי לענות על שאלה תפעולית אחת — מתי לשלוח — ועל שאלה שנייה
 * שאיש לא שאל עד שהיא עלתה כסף: למי כבר לא שווה לשלוח. שיעור פתיחה נמוך אינו
 * רק בזבוז, הוא מה שספקי הדואר מדרגים לפיו את המסירה של כל השאר.
 *
 * כל מספר כאן מגיע מדיווח של ספק המייל. כשאין דיווחים המסך אומר זאת ואינו
 * מציג אפסים: "0% פתיחה" ו"אין מדידה" נראים אותו דבר על המסך ומובילים לשתי
 * החלטות הפוכות.
 */
class BroadcastStats extends Page
{
    // ההרשאה נגזרת מקבוצת הניווט ("תמיכה") — אותו מודול של הדיוורים עצמם.
    use RespectsModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'תמיכה';

    protected static ?string $navigationLabel = 'מדדי דיוור';

    protected static ?string $title = 'מדדי דיוור — מי פותח, ומתי';

    // מיד אחרי "דיוורים" (3).
    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.broadcast-stats';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $engagement = app(MarketingEngagement::class);

        $byHour = $engagement->opensByHour();
        $byWeekday = $engagement->opensByWeekday();

        return [
            'days' => $engagement->windowDays(),
            'hasData' => $engagement->hasOpenData(),
            'rejectedAt' => WebhookRejections::lastAt('email.delivery'),
            'totals' => $engagement->totals(),
            'byHour' => $byHour,
            'peakHour' => max(1, max($byHour)),
            'byWeekday' => $byWeekday,
            'peakWeekday' => max(1, max($byWeekday)),
            'best' => $engagement->bestWindow(),
            'skipping' => $engagement->skipsNonOpeners(),
            'nonOpeners' => $this->nonOpeners($engagement),
        ];
    }

    /**
     * הלקוחות שאינם פותחים, עם שם וכתובת — רשימה שאפשר לפעול לפיה.
     *
     * מספר לבדו ("14 לקוחות לא פותחים") אינו ניתן לבדיקה: אי אפשר לדעת אם
     * נפלה שם טעות עד שרואים מי הם.
     *
     * @return Collection<int, Customer>
     */
    private function nonOpeners(MarketingEngagement $engagement): Collection
    {
        $ids = $engagement->nonOpenerIds();

        if ($ids === []) {
            return collect();
        }

        return Customer::query()
            ->whereKey($ids)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
