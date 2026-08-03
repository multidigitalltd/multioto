<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;
use App\Services\Audit\Markup;
use App\Services\Audit\SiteProbe;

/**
 * מה שקורה כשמגיעים לאתר מהטלפון — כלומר, בדרך כלל, מה שקורה.
 *
 * רוב המבקרים באתר עסקי בישראל מגיעים מהטלפון, וגוגל מדרג לפי הגרסה הניידת.
 * אי אפשר לצייר את הדף מבחוץ ולכן אין כאן שיפוט של עיצוב — יש כאן את השאלות
 * שכן אפשר לענות עליהן בוודאות: האם האתר בכלל מצהיר שהוא מותאם, האם הוא עונה
 * לטלפון כמו למחשב, וכמה זמן זה לוקח.
 *
 * מה שאי אפשר לבדוק מבחוץ נאמר במפורש ולא נשתק, כדי שהיעדר ממצא לא ייקרא
 * כאישור שהאתר נראה טוב בנייד.
 */
class MobileReadiness implements Check, ReadsPage
{
    /** אייפון עדכני — הדפדפן הנפוץ ביותר בקהל של אתר עסקי ישראלי. */
    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 '
        .'(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 (+MultiotoSiteAudit)';

    /** מעל זה, מי שממתין ברשת סלולרית כבר חושב ללכת. */
    private const SLOW_MS = 3000;

    public function area(): string
    {
        return 'מובייל';
    }

    public function run(AuditContext $site): array
    {
        if (! $site->home->reachable()) {
            return [];
        }

        return array_filter(array_merge(
            [$this->viewport($site)],
            $this->phoneVisit($site),
        ));
    }

    /**
     * ההצהרה שבלעדיה הדפדפן מציג את אתר המחשב מוקטן.
     *
     * זה הסימן החד ביותר שאפשר לקרוא מבחוץ: אתר בלי viewport אינו "פחות יפה
     * בנייד", הוא אתר שהמבקר צריך לזום כדי לקרוא בו מילה.
     */
    private function viewport(AuditContext $site): ?Finding
    {
        if (Markup::meta($site->markup(), 'name', 'viewport') !== null) {
            return null;
        }

        return Finding::critical(
            $this->area(),
            'האתר אינו מותאם למסך של טלפון',
            'בדף אין הגדרת viewport, ולכן הדפדפן בטלפון מציג את גרסת המחשב מוקטנת — טקסט שאי אפשר לקרוא בלי זום וכפתורים שקשה לפגוע בהם. רוב המבקרים באתר עסקי מגיעים מהטלפון, וגוגל מדרג לפי הגרסה הניידת.',
            'להוסיף לדף את הגדרת ה-viewport, ולוודא שהתבנית עצמה רספונסיבית.',
        );
    }

    /**
     * ביקור אמיתי כטלפון, ומה שהוא מגלה.
     *
     * @return list<Finding>
     */
    private function phoneVisit(AuditContext $site): array
    {
        $phone = SiteProbe::fetch($site->base(), agent: self::PHONE);

        if ($phone->blocked()) {
            return [];
        }

        if (! $phone->reachable()) {
            return [Finding::critical(
                $this->area(),
                'האתר אינו נענה לטלפון',
                'אותה כתובת שנענתה למחשב לא נענתה כשהבדיקה הגיעה כטלפון. המשמעות היא שחלק ניכר מהמבקרים — לרוב הרוב — אינם רואים את האתר כלל.',
                'לבדוק אצל חברת האחסון או בתוסף הקאשינג אם יש טיפול נפרד בגרסה הניידת.',
                $phone->error ?? 'HTTP '.$phone->status,
            )];
        }

        return array_filter([$this->separateSite($site, $phone), $this->speed($phone)]);
    }

    /**
     * אתר נייד נפרד — פתרון של פעם שהיום עולה כסף.
     *
     * שתי כתובות עם אותו תוכן מחלקות את הדירוג בגוגל, והגרסה הניידת כמעט תמיד
     * מתעדכנת פחות — כך שהמבקר מהטלפון רואה מחירים ישנים.
     */
    private function separateSite(AuditContext $site, SiteProbe $phone): ?Finding
    {
        $landed = mb_strtolower((string) parse_url($phone->finalUrl, PHP_URL_HOST));
        $expected = mb_strtolower((string) parse_url($site->base(), PHP_URL_HOST));

        if ($landed === '' || $landed === $expected) {
            return null;
        }

        return Finding::warning(
            $this->area(),
            'לטלפון מוגשת גרסה נפרדת של האתר',
            "פנייה מהטלפון מופנית אל {$landed}. אתר נייד נפרד הוא פתרון מלפני עשור: הדירוג בגוגל מתחלק בין שתי הכתובות, והגרסה הניידת כמעט תמיד מתעדכנת פחות — כלומר מי שמגיע מהטלפון רואה תוכן ישן יותר.",
            'לעבור לתבנית רספונסיבית אחת שמשרתת את שני המכשירים מאותה כתובת.',
        );
    }

    private function speed(SiteProbe $phone): ?Finding
    {
        if ($phone->ms < self::SLOW_MS) {
            return null;
        }

        $seconds = round($phone->ms / 1000, 1);

        return Finding::warning(
            $this->area(),
            'האתר איטי בטעינה מהטלפון',
            "התשובה לפנייה מהטלפון הגיעה אחרי {$seconds} שניות, וזה לפני שהדפדפן התחיל להוריד תמונות וסקריפטים. ברשת סלולרית זה ארוך עוד יותר, ובשלב הזה חלק מהמבקרים כבר סוגרים.",
            'לבדוק קאשינג, גודל תמונות ותוספים כבדים; לרוב מדובר בשלושת אלה יחד.',
            "{$seconds} שניות",
        );
    }
}
