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
class Accessibility implements Check
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
            $this->imageText($site),
            $this->statement($site),
            $this->linkText($site),
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
