<?php

namespace App\Services\Support;

use App\Enums\NotificationType;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * מי קורא את הדיוורים שלנו, ומתי.
 *
 * הכל נגזר מאירועי המסירה והפתיחה שספק המייל מדווח (notification_logs). אין כאן
 * שום הערכה: "נפתח" פירושו שהספק אמר שנפתח.
 *
 * הכלל שחוזר בכל שיטה בקובץ הזה: **היעדר נתונים אינו נתון.** בהתקנה שבה מעקב
 * הפתיחות לא הוגדר, אף אחד לא "נפתח" — ומסקנה תמימה מכך היא שכל הלקוחות אינם
 * קוראים, ולכן אין לשלוח לאיש. לכן כל שיטה שמסיקה מסקנה בודקת קודם שיש בכלל
 * ממה להסיק, ומחזירה "אין תשובה" במקום תשובה שגויה.
 */
class MarketingEngagement
{
    /** חלון הלמידה בימים. */
    public function windowDays(): int
    {
        return max(1, (int) config('billing.broadcasts.engagement.window_days', 180));
    }

    /** האם ספק המייל דיווח אי פעם על פתיחה. בלי זה — אין על מה לדבר. */
    public function hasOpenData(): bool
    {
        return NotificationLog::query()->whereNotNull('opened_at')->exists();
    }

    /**
     * סיכום התקופה: כמה נשלחו, נמסרו, נפתחו וחזרו — ושיעור הפתיחה מתוך הנמסרים.
     *
     * שיעור הפתיחה מחושב מתוך מה שנמסר ולא מתוך מה שנשלח: הודעה שחזרה מעולם לא
     * הגיעה לאיש, וספירתה במכנה מציגה את התוכן ככישלון במקום את הכתובת.
     *
     * @return array{sent: int, delivered: int, opened: int, bounced: int, open_rate: ?float}
     */
    public function totals(?int $days = null): array
    {
        $row = $this->scope($days)
            ->selectRaw('COUNT(*) AS sent')
            ->selectRaw('COUNT(delivered_at) AS delivered')
            ->selectRaw('COUNT(opened_at) AS opened')
            ->selectRaw('COUNT(bounced_at) AS bounced')
            ->first();

        $delivered = (int) ($row?->delivered ?? 0);
        $opened = (int) ($row?->opened ?? 0);

        return [
            'sent' => (int) ($row?->sent ?? 0),
            'delivered' => $delivered,
            'opened' => $opened,
            'bounced' => (int) ($row?->bounced ?? 0),
            // null ולא 0: "אין נתון" ו"אף אחד לא פתח" הם שתי אמירות שונות מאוד.
            'open_rate' => $delivered > 0 ? round($opened / $delivered, 4) : null,
        ];
    }

    /**
     * פתיחות לפי שעה ביום (0..23), כל השעות תמיד נוכחות.
     *
     * שעה בלי פתיחות היא מידע — לא סיבה להשמיט אותה מהגרף ולהזיז את כל השאר.
     *
     * @return array<int, int>
     */
    public function opensByHour(?int $days = null): array
    {
        return $this->opensGroupedBy($this->hourExpression(), range(0, 23), $days);
    }

    /**
     * פתיחות לפי יום בשבוע (0 = ראשון .. 6 = שבת).
     *
     * @return array<int, int>
     */
    public function opensByWeekday(?int $days = null): array
    {
        return $this->opensGroupedBy($this->weekdayExpression(), range(0, 6), $days);
    }

    /**
     * חלון השעתיים שבו נפתחות הכי הרבה הודעות, או null כשאין מספיק נתונים.
     *
     * שעתיים ולא שעה אחת: שליחה מכוונת לרגע יחיד בנוי על דיוק שאין לנו, ושתי
     * שעות סמוכות סופגות את הרעש בלי לוותר על ההמלצה. מתחת לסף פתיחות אין
     * החזרה בכלל — חמש פתיחות מקריות אינן דפוס.
     *
     * @return array{from: int, to: int, opens: int, share: float}|null
     */
    public function bestWindow(?int $days = null): ?array
    {
        $byHour = $this->opensByHour($days);
        $total = array_sum($byHour);

        if ($total < (int) config('billing.broadcasts.engagement.min_opens_for_advice', 25)) {
            return null;
        }

        $best = null;

        foreach (range(0, 23) as $hour) {
            $opens = $byHour[$hour] + $byHour[($hour + 1) % 24];

            if ($best === null || $opens > $best['opens']) {
                $best = ['from' => $hour, 'to' => ($hour + 2) % 24, 'opens' => $opens];
            }
        }

        $best['share'] = round($best['opens'] / $total, 4);

        return $best;
    }

    /**
     * מזהי הלקוחות שקיבלו מספיק הודעות ולא פתחו אף אחת.
     *
     * "מספיק" הוא הסף שבהגדרות: מי שקיבל שתי הודעות ולא פתח אינו אומר דבר, ומי
     * שקיבל עשר ולא פתח אחת — אומר. נספרות רק הודעות שהספק אישר שנמסרו: הודעה
     * שלא הגיעה אינה ראיה לכך שהנמען אינו קורא.
     *
     * @return list<int>
     */
    public function nonOpenerIds(?int $days = null): array
    {
        if (! $this->hasOpenData()) {
            return []; // בלי מעקב פתיחות כולם ייראו כך, וזו בדיוק הטעות
        }

        $minSent = max(1, (int) config('billing.broadcasts.engagement.skip_non_openers.min_sent', 5));

        return $this->scope($days)
            ->whereNotNull('customer_id')
            ->whereNotNull('delivered_at')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= ?', [$minSent])
            ->havingRaw('COUNT(opened_at) = 0')
            ->pluck('customer_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** האם הדילוג על מי שאינו פותח מופעל ויש עליו נתונים. */
    public function skipsNonOpeners(): bool
    {
        return (bool) config('billing.broadcasts.engagement.skip_non_openers.enabled', true)
            && $this->hasOpenData();
    }

    /**
     * שורות הדיוור בחלון הנבחר: מיילים בלבד, מסוג דיוור בלבד.
     *
     * תשובה לפנייה או תזכורת חיוב אינן דיוור, ופתיחה שלהן אינה מעידה על עניין
     * בפרסום — ערבוב שלהן היה מייפה את המספרים ומזיז את שעת השיא.
     */
    private function scope(?int $days = null): Builder
    {
        return NotificationLog::query()
            ->where('channel', 'email')
            ->where('type', NotificationType::Broadcast)
            ->where('sent_at', '>=', now()->subDays($days ?? $this->windowDays()));
    }

    /**
     * @param  list<int>  $buckets  every bucket that must appear in the result
     * @return array<int, int>
     */
    private function opensGroupedBy(string $expression, array $buckets, ?int $days): array
    {
        $counts = array_fill_keys($buckets, 0);

        $rows = $this->scope($days)
            ->whereNotNull('opened_at')
            ->selectRaw("{$expression} AS bucket, COUNT(*) AS opens")
            ->groupByRaw($expression)
            ->get();

        foreach ($rows as $row) {
            $bucket = (int) $row->bucket;

            if (array_key_exists($bucket, $counts)) {
                $counts[$bucket] = (int) $row->opens;
            }
        }

        return $counts;
    }

    /**
     * שעת הפתיחה, לפי הדיאלקט של בסיס הנתונים.
     *
     * החותמות נשמרות באזור הזמן של האפליקציה (Asia/Jerusalem), ולכן השעה
     * שמתקבלת כאן היא כבר השעה שהלקוח חווה — בלי המרה.
     *
     * הביטויים כאן קבועים בקוד ואינם נוגעים בקלט משתמש.
     */
    private function hourExpression(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? 'EXTRACT(HOUR FROM opened_at)'
            : "CAST(strftime('%H', opened_at) AS INTEGER)";
    }

    /** יום בשבוע, 0 = ראשון בשני הדיאלקטים. */
    private function weekdayExpression(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? 'EXTRACT(DOW FROM opened_at)'
            : "CAST(strftime('%w', opened_at) AS INTEGER)";
    }
}
