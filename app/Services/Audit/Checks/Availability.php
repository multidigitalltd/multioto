<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;
use App\Services\Audit\SiteProbe;

/** Does the site answer at all, and does it answer to both of its names. */
class Availability implements Check
{
    /**
     * סיומות שנייה שמאחוריהן מסתתר הדומיין עצמו — example.co.il הוא דומיין,
     * לא תת-דומיין של example.
     */
    private const SECOND_LEVEL = [
        'co', 'com', 'net', 'org', 'ac', 'gov', 'muni', 'idf', 'k12', 'sch',
        'edu', 'ne', 'or', 'gr', 'biz', 'info', 'nic',
    ];

    public function area(): string
    {
        return 'זמינות';
    }

    public function run(AuditContext $site): array
    {
        $home = $site->home;

        if ($home->error !== null) {
            return [Finding::critical(
                $this->area(),
                'האתר לא נענה',
                'הכתובת לא החזירה תשובה בזמן סביר. מבקר שמגיע עכשיו רואה שגיאה במקום את האתר.',
                'לבדוק מול חברת האחסון אם השרת פעיל, ואם הדומיין מפנה לשרת הנכון.',
                $home->error,
            )];
        }

        $findings = [];

        if ($home->status !== null && $home->status >= 400) {
            $findings[] = Finding::critical(
                $this->area(),
                "האתר מחזיר שגיאה {$home->status}",
                'הדף הראשי אינו נטען. זו שגיאה שכל מבקר רואה, וגם מנועי החיפוש.',
                'לבדוק את יומן השגיאות בשרת ואת תקינות ההתקנה.',
                'HTTP '.$home->status,
            );
        } else {
            $findings[] = Finding::ok($this->area(), 'האתר נטען', 'הדף הראשי החזיר תשובה תקינה.');
        }

        return array_merge($findings, $this->bothNames($site));
    }

    /**
     * www ו-non-www חייבים להוביל לאותו מקום.
     *
     * שתי כתובות שמגישות את אותו תוכן בלי הפניה הן שני אתרים לגוגל — הדירוג
     * מתחלק ביניהן — ולעיתים קרובות אחת מהן פשוט לא עובדת, מה שבעליו של האתר
     * לא רואה כי הוא תמיד מקליד את אותה אחת.
     *
     * @return list<Finding>
     */
    private function bothNames(AuditContext $site): array
    {
        $host = mb_strtolower($site->host);
        $other = self::counterpart($host);

        if ($other === null) {
            return [];
        }

        $probe = SiteProbe::fetch(($site->servesHttps() ? 'https://' : 'http://').$other);

        if ($probe->error !== null || $probe->status === null || $probe->status >= 400) {
            return [Finding::warning(
                $this->area(),
                "הכתובת {$other} אינה עובדת",
                'מבקר שמקליד את הכתובת בצורה הזו, או לינק ישן שמפנה אליה, מגיע לשגיאה.',
                'להגדיר הפניה קבועה (301) מהכתובת הזו לכתובת הראשית.',
                $probe->error ?? 'HTTP '.$probe->status,
            )];
        }

        return [Finding::ok($this->area(), 'שתי צורות הכתובת עובדות', "גם {$host} וגם {$other} נענים.")];
    }

    /**
     * הצורה השנייה של הכתובת — כשבאמת יש כזו.
     *
     * www שייך לדומיין עצמו. shop.example.com הוא שם שמישהו בחר, ו-
     * www.shop.example.com הוא שם שהכלי היה ממציא — ולדווח שההמצאה לא נענית זו
     * תקלה שלאתר אין, במסמך שנשלח ללקוח.
     */
    private static function counterpart(string $host): ?string
    {
        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        $labels = explode('.', $host);

        return match (true) {
            count($labels) === 2 => 'www.'.$host,
            count($labels) === 3 && in_array($labels[1], self::SECOND_LEVEL, true) => 'www.'.$host,
            default => null,
        };
    }
}
