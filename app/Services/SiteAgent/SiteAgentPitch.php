<?php

namespace App\Services\SiteAgent;

use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

/**
 * What a number that is not registered hears: what the bot does, and where
 * to buy it.
 *
 * Somebody writing to the bot's number without a subscription found it
 * somewhere — an ad, a friend's phone, the store page — and is asking what
 * it is. "This number is not registered" answers nobody's question.
 *
 * The full pitch goes once a day per number; anything else they send that
 * day gets one line with the link. A stranger who keeps typing is not sent
 * the same long brochure again and again.
 *
 * Says nothing about whether any number is registered: every registered
 * number gets its own answer before this is reached, and this one is the
 * same for every stranger.
 */
class SiteAgentPitch
{
    public function message(string $phone): string
    {
        $link = route('store.agent');

        if (! Cache::add('site-agent:pitch:'.sha1($phone), true, now()->addDay())) {
            return "כדי לנהל את האתר שלכם מכאן, מצטרפים בקישור: {$link}";
        }

        return implode("\n", array_filter([
            'שלום! 👋 זה *בוט ניהול האתר* של Multi Digital.',
            'המספר הזה עדיין לא מחובר לאתר. כשהוא מחובר, מנהלים את אתר הוורדפרס שלכם מהוואטסאפ, בלי להיכנס ללוח הבקרה:',
            '',
            '• *מוצרים* — יצירת מוצרים חדשים, מחירים, מבצעים, מלאי וקטגוריות. שולחים תמונה והיא עולה למוצר',
            '• *הזמנות ומנויים* — חיפוש, עדכון סטטוס, הערות ללקוח',
            '• *תוכן* — פוסטים ועמודים, החלפת טקסטים, תפריטים ותגובות',
            '• *לידים ומשתמשים* — מי השאיר פרטים, הוספת משתמשים',
            '• *דוחות* — מכירות ולידים, עכשיו או כל בוקר, שבוע או חודש',
            '• *תחזוקה* — עדכון תוספים, ניקוי מטמון ובדיקת שגיאות באתר',
            '',
            '✅ כל שינוי מוצג לכם לאישור לפני שהוא מבוצע, ולרוב השינויים יש ביטול.',
            '✅ כותבים בעברית רגילה, כמו לעובד. הבוט מבין הקשר.',
            '✅ האתר מתעדכן תוך שניות, בלי לחכות למפתח.',
            $this->trialLine(),
            '',
            "להצטרפות: {$link}",
        ], fn (?string $line): bool => $line !== null));
    }

    /** The trial, when the plans on sale offer one. */
    private function trialLine(): ?string
    {
        $days = (int) Plan::query()->publiclySellable()->max('trial_days');

        return $days > 0 ? "🎁 {$days} ימי ניסיון חינם." : null;
    }
}
