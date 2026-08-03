<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;

/**
 * The things a screen reader needs, and the document Israeli law asks for.
 *
 * Read from the markup only. That catches the faults that are ALWAYS faults —
 * an image with no description is one whatever the design — and cannot judge
 * contrast or keyboard order, which the report says outright rather than
 * implying a clean bill of health.
 */
class Accessibility implements Check, ReadsPage
{
    public function area(): string
    {
        return 'נגישות';
    }

    public function run(AuditContext $site): array
    {
        if (! $site->home->reachable()) {
            return [];
        }

        return array_filter([
            $this->language($site),
            $this->direction($site),
            $this->imageText($site),
            $this->statement($site),
            $this->linkText($site),
            $this->formLabels($site),
            $this->zoom($site),
            $this->frameTitles($site),
            $this->namelessLinks($site),
            $this->landmark($site),
            $this->headingOrder($site),
        ]);
    }

    private function language(AuditContext $site): ?Finding
    {
        if ($site->match('#<html[^>]+\blang=["\']([a-z-]+)#i') !== null) {
            return null;
        }

        return Finding::warning(
            $this->area(),
            'לא מוגדרת שפת האתר',
            'קורא מסך אינו יודע באיזו שפה לקרוא את הדף, ומקריא עברית במבטא אנגלי — או לא מקריא כלל.',
            'להוסיף lang="he" ו-dir="rtl" לתגית ה-html.',
        );
    }

    private function imageText(AuditContext $site): ?Finding
    {
        $images = $site->occurrences('#<img\b#i');

        if ($images === 0) {
            return null;
        }

        $described = $site->occurrences('#<img\b[^>]*\balt=#i');
        $missing = max(0, $images - $described);

        if ($missing === 0) {
            return Finding::ok($this->area(), 'לכל התמונות יש טקסט חלופי');
        }

        return Finding::warning(
            $this->area(),
            'לתמונות אין תיאור טקסטואלי',
            "עבור עיוור שגולש עם קורא מסך, {$missing} מתוך {$images} התמונות בדף הן שקט. זו גם דרישה בתקן הישראלי.",
            'להוסיף טקסט חלופי (alt) המתאר את תוכן התמונה. לתמונות עיצוביות בלבד — alt ריק.',
        );
    }

    /** The declaration Israeli sites are required to publish. */
    private function statement(AuditContext $site): ?Finding
    {
        if ($site->occurrences('#(הצהרת נגישות|accessibility[- ]statement)#iu') > 0) {
            return null;
        }

        return Finding::warning(
            $this->area(),
            'לא נמצאה הצהרת נגישות',
            'אתר עסקי בישראל נדרש לפרסם הצהרת נגישות נגישה מכל דף. היעדרה היא חשיפה לתביעה, והיא נפוצה מאוד.',
            'לפרסם הצהרת נגישות ולקשר אליה מהתחתית של כל דף.',
        );
    }

    /**
     * עברית שמוגשת משמאל לימין.
     *
     * בלי dir="rtl" הפיסוק קופץ לצד הלא נכון, מספרים וכתובות מייל נשברים באמצע
     * משפט, וקורא מסך מכריז על כיוון הפוך. זה נראה כמו פרט קטן עד שרואים עמוד
     * שלם של טקסט עברי מיושר לשמאל.
     */
    private function direction(AuditContext $site): ?Finding
    {
        $language = $site->match('#<html[^>]+\blang=["\']?([a-z-]+)#i');

        if ($language === null || ! preg_match('/^(he|ar|fa|ur)/i', $language)) {
            return null;
        }

        if ($site->match('#<html[^>]+\bdir=["\']?rtl#i') !== null) {
            return null;
        }

        return Finding::warning(
            $this->area(),
            'האתר בעברית אך אינו מוגדר ככיוון ימין-לשמאל',
            'בלי dir="rtl" סימני פיסוק, מספרים וכתובות מייל מופיעים במקום הלא נכון במשפט, וקורא מסך מכריז על כיוון הפוך.',
            'להוסיף dir="rtl" לתגית ה-html לצד lang="he".',
        );
    }

    /**
     * שדה טופס בלי תווית הוא שדה שקורא מסך אינו יודע לקרוא בשמו.
     *
     * ה-placeholder אינו תווית: הוא נעלם ברגע שמתחילים להקליד, ורוב קוראי המסך
     * אינם מכריזים עליו. שדה "טלפון" שנשמע כ"תיבת טקסט" הוא טופס יצירת קשר
     * שלא נשלח — כלומר פנייה שלא הגיעה.
     */
    private function formLabels(AuditContext $site): ?Finding
    {
        $fields = self::fields($site->markup());

        if ($fields === []) {
            return null;
        }

        $unlabelled = array_values(array_filter($fields, static function (string $field) use ($site): bool {
            if (preg_match('#\b(aria-label|aria-labelledby|title)\s*=\s*["\'][^"\']+#i', $field) === 1) {
                return false;
            }

            // A field with an id is labelled if some <label for="…"> names it.
            return preg_match('#\bid\s*=\s*["\']([^"\']+)#i', $field, $id) !== 1
                || $site->occurrences('#<label[^>]+\bfor\s*=\s*["\']'.preg_quote($id[1], '#').'["\']#i') === 0;
        }));

        if ($unlabelled === []) {
            return Finding::ok($this->area(), 'לכל שדות הטופס יש תווית');
        }

        $count = count($unlabelled);

        return Finding::warning(
            $this->area(),
            'לשדות בטופס אין תווית',
            "נמצאו {$count} שדות שקורא מסך אינו יודע לקרוא בשמם. טקסט רקע (placeholder) אינו תווית — הוא נעלם כשמתחילים להקליד.",
            'להצמיד לכל שדה תגית label עם for, או לפחות aria-label.',
        );
    }

    /**
     * אתר שאוסר להגדיל אותו.
     *
     * user-scalable=no הוא פתרון עיצובי לבעיה שאין לו, והוא נועל מחוץ לאתר כל
     * מי שצריך להגדיל טקסט כדי לקרוא אותו — כלומר חלק ניכר מהלקוחות מעל גיל 50.
     * זו הפרה מפורשת של WCAG 1.4.4.
     */
    private function zoom(AuditContext $site): ?Finding
    {
        $viewport = $site->match('#<meta[^>]+name=["\']?viewport["\']?[^>]*content=["\']([^"\']+)#i')
            ?? $site->match('#<meta[^>]+content=["\']([^"\']*(?:user-scalable|maximum-scale)[^"\']*)["\'][^>]*name=["\']?viewport#i');

        if ($viewport === null) {
            return null;
        }

        $blocks = preg_match('/user-scalable\s*=\s*(no|0)/i', $viewport) === 1
            || (preg_match('/maximum-scale\s*=\s*([\d.]+)/i', $viewport, $scale) === 1 && (float) $scale[1] < 2);

        if (! $blocks) {
            return null;
        }

        return Finding::warning(
            $this->area(),
            'האתר מונע הגדלה במכשירים ניידים',
            'הגדרת התצוגה חוסמת זום. מי שצריך להגדיל טקסט כדי לקרוא אותו — וזה חלק ניכר מהלקוחות מעל גיל 50 — פשוט אינו יכול.',
            'להסיר user-scalable=no ו-maximum-scale מהגדרת ה-viewport.',
            mb_substr($viewport, 0, 100),
        );
    }

    /** תוכן מוטמע שקורא מסך מכריז עליו כ"מסגרת" ותו לא. */
    private function frameTitles(AuditContext $site): ?Finding
    {
        $frames = $site->occurrences('#<iframe\b#i');

        if ($frames === 0) {
            return null;
        }

        $titled = $site->occurrences('#<iframe\b[^>]*\btitle\s*=\s*["\'][^"\']+#i');
        $missing = max(0, $frames - $titled);

        if ($missing === 0) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'לתוכן מוטמע בדף אין כותרת',
            "נמצאו {$missing} מסגרות (וידאו, מפה, טופס חיצוני) שקורא מסך מכריז עליהן כ\"מסגרת\" בלי לומר מה יש בהן.",
            'להוסיף title לכל תגית iframe — "מפת הגעה", "סרטון הדגמה" וכדומה.',
        );
    }

    /**
     * קישור שאין בו טקסט — בדרך כלל אייקון.
     *
     * ברשימת הקישורים שקורא מסך מקריא, קישור כזה נשמע כמו כתובת ה-URL שלו, או
     * כמו כלום.
     */
    private function namelessLinks(AuditContext $site): ?Finding
    {
        $nameless = $site->occurrences('#<a\b[^>]*>\s*(?:<(?:i|span|svg)\b[^>]*>\s*</(?:i|span|svg)>\s*)*</a>#i');

        if ($nameless < 2) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'קישורים ללא טקסט כלל',
            "נמצאו {$nameless} קישורים שכל תוכנם אייקון. קורא מסך מקריא במקומם את הכתובת עצמה, או שותק.",
            'להוסיף aria-label לכל קישור אייקון — "פייסבוק", "חיפוש", "תפריט".',
        );
    }

    /** דילוג לתוכן — הדרך של גולש מקלדת לעקוף תפריט של ארבעים פריטים. */
    private function landmark(AuditContext $site): ?Finding
    {
        if ($site->occurrences('#<main\b|\brole\s*=\s*["\']main["\']#i') > 0) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'אין אזור תוכן ראשי מוגדר בדף',
            'בלי תגית main, גולש מקלדת וקורא מסך נאלצים לעבור את כל התפריט בכל דף מחדש כדי להגיע לתוכן.',
            'לעטוף את תוכן הדף בתגית main, ולהוסיף קישור "דילוג לתוכן" בראש הדף.',
        );
    }

    /**
     * סדר כותרות שמדלג.
     *
     * קורא מסך מנווט לפי כותרות כמו לפי תוכן עניינים; דילוג מ-H1 ל-H3 הוא פרק
     * שנעלם מהתוכן.
     */
    private function headingOrder(AuditContext $site): ?Finding
    {
        if ($site->occurrences('#<h1\b#i') === 0 || $site->occurrences('#<h2\b#i') > 0) {
            return null;
        }

        if ($site->occurrences('#<h3\b#i') === 0) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'סדר הכותרות בדף מדלג',
            'הדף עובר מכותרת ראשית ישירות לכותרת מדרג שלישי, בלי מדרג שני. קורא מסך מנווט לפי הכותרות, ודילוג נשמע כמו פרק חסר.',
            'לשמור על סדר יורד: H1, אחריה H2, ורק אחר כך H3.',
        );
    }

    /**
     * שדות הקלט שדורשים תווית.
     *
     * כפתורים, שדות מוסתרים ושדות submit אינם ברשימה: לכפתור יש שם משלו, ושדה
     * שאיש אינו רואה אינו שדה שקורא מסך צריך לקרוא בשמו.
     *
     * @return list<string>
     */
    private static function fields(string $markup): array
    {
        preg_match_all('#<(?:input|select|textarea)\b[^>]*>#i', $markup, $tags);

        return array_values(array_filter(
            $tags[0],
            static fn (string $tag): bool => preg_match('#\btype\s*=\s*["\']?(hidden|submit|button|image|reset)#i', $tag) !== 1,
        ));
    }

    private function linkText(AuditContext $site): ?Finding
    {
        $vague = $site->occurrences('#>\s*(לחץ כאן|לחצו כאן|כאן|קרא עוד|click here|read more)\s*<#iu');

        if ($vague < 3) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'קישורים עם טקסט שאינו מסביר לאן הם מובילים',
            'קורא מסך מקריא רשימת קישורים בלי ההקשר סביבם, ורשימה של "לחץ כאן" חסרת משמעות.',
            'לנסח את הקישור כך שיתאר את היעד: "לצפייה במחירון" במקום "לחץ כאן".',
            "נמצאו {$vague} קישורים כאלה",
        );
    }
}
