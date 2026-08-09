<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * מתי בפעם האחרונה נדחתה פנייה נכנסת בגלל סוד שאינו תואם.
 *
 * דחייה היא התשובה הנכונה — אבל היא נשלחת למי שקרא, ולא למי שצריך לדעת עליה.
 * ספק שהוגדר עם כתובת בלי הסוד (או עם סוד ישן) ימשיך לדפוק בדלת שעה אחרי שעה,
 * ובפאנל זה נראה בדיוק כמו ספק שמעולם לא חובר: שום מספר, שום הודעה. ההבדל בין
 * "לא הוגדר" ל"הוגדר לא נכון" הוא ההבדל בין לחפש איפה מגדירים לבין לתקן שורה
 * אחת — ומי שאינו יודע להבחין ביניהם מחפש במקום הלא נכון.
 *
 * נשמר ב-cache ולא בבסיס הנתונים במכוון: זו נקודת קצה שכל אחד יכול לקרוא לה,
 * וכתיבה לבסיס הנתונים בכל קריאה דחויה היא בדיוק המנוף שלא כדאי לתת. ערך יחיד
 * שנדרס בכל פעם, ופג מעצמו.
 */
class WebhookRejections
{
    /** כמה זמן זכר הדחייה שווה משהו. אחריו — כנראה כבר תוקן. */
    private const REMEMBER_DAYS = 30;

    private static function key(string $channel): string
    {
        return "webhook.rejected.{$channel}";
    }

    /** רישום דחייה. חייב להיות זול ולעולם לא להפיל את התשובה עצמה. */
    public static function record(string $channel): void
    {
        try {
            Cache::put(self::key($channel), now()->toIso8601String(), now()->addDays(self::REMEMBER_DAYS));
        } catch (\Throwable) {
            // Cache לא זמין — הדחייה עצמה חשובה יותר מהתיעוד שלה.
        }
    }

    /** מתי נדחתה פנייה אחרונה בערוץ הזה, או null אם לא היו. */
    public static function lastAt(string $channel): ?Carbon
    {
        try {
            $at = Cache::get(self::key($channel));

            return filled($at) ? Carbon::parse((string) $at) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** ניקוי — אחרי שהסוד עודכן, אין טעם להמשיך להתריע על העבר. */
    public static function forget(string $channel): void
    {
        try {
            Cache::forget(self::key($channel));
        } catch (\Throwable) {
            // אין מה לעשות, וגם אין נזק: הערך פג מעצמו.
        }
    }
}
