<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * מתי בפעם האחרונה הגיעה מסירה נכנסת שאימתנו בהצלחה.
 *
 * זו עובדה אחרת מ"קיבלנו הודעה שטיפלנו בה", וההבדל ביניהן הוא כל ההבדל באבחון.
 *
 * מטא רושמת וובהוק **לפי חשבון הוואטסאפ, לא לפי מספר**. חשבון שמחזיק כמה מספרים
 * — קו תמיכה, קו מכירות, והמספר של המוצר הזה — מעביר את כולם לאותה כתובת, ואנחנו
 * מסננים את מה שאינו שלנו כדי שלקוח שכתב לקו התמיכה לא יקבל תשובה מבוט ניהול
 * האתר. הסינון נכון, אבל הוא גם מוחק את העדות: המסירה הגיעה, החתימה אומתה,
 * והערוץ הוכיח שהוא עובד — ואז לא נשאר ממנה זכר.
 *
 * בלי הרישום הזה, מערכת שמקבלת מאות הודעות לאחיות של המספר שלנו הייתה מדווחת
 * "מעולם לא התקבלה הודעה, הבעיה אצל מטא" — ושולחת את מי שמתקן לבדוק פרסום
 * אפליקציה ורישום שדות, בזמן שהערוץ תקין לגמרי והתקלה היא במספר עצמו.
 *
 * נשמר ב-cache ולא בבסיס הנתונים, כמו הדחיות: ערך יחיד שנדרס בכל מסירה, ופג
 * מעצמו. העובדה העמידה לטווח ארוך נשמרת ב-InboundChannelHealth.
 */
class WebhookDeliveries
{
    /** כמה זמן זכר המסירה שווה משהו לצורך אבחון. */
    private const REMEMBER_DAYS = 30;

    private static function key(string $channel): string
    {
        return "webhook.delivered.{$channel}";
    }

    /** רישום מסירה שאומתה. חייב להיות זול ולעולם לא להפיל את התשובה עצמה. */
    public static function record(string $channel): void
    {
        try {
            Cache::put(self::key($channel), now()->toIso8601String(), now()->addDays(self::REMEMBER_DAYS));
        } catch (\Throwable) {
            // Cache לא זמין — הטיפול בהודעה חשוב יותר מהתיעוד שלה.
        }
    }

    /** מתי אומתה מסירה אחרונה בערוץ הזה, או null אם לא היו. */
    public static function lastAt(string $channel): ?Carbon
    {
        try {
            $at = Cache::get(self::key($channel));

            return filled($at) ? Carbon::parse((string) $at) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
