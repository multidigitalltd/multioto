<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;
use App\Services\Audit\SiteProbe;
use App\Services\Hosting\SiteDiagnostics;

/** HTTPS: is there one, is it valid, and does plain http lead to it. */
class Transport implements Check
{
    public function __construct(private SiteDiagnostics $diagnostics) {}

    public function area(): string
    {
        return 'אבטחת התחברות';
    }

    public function run(AuditContext $site): array
    {
        if (! $site->servesHttps()) {
            return [Finding::critical(
                $this->area(),
                'האתר אינו מוגש בחיבור מאובטח (HTTPS)',
                'הדפדפנים מסמנים אתר כזה כ"לא מאובטח", וכל מה שנשלח בטופס — טלפון, מייל, פרטי תשלום — עובר גלוי. גם הדירוג בגוגל נפגע.',
                'להתקין תעודת SSL (ב-Let\'s Encrypt היא חינם אצל כל מארח סביר) ולהפנות את כל התעבורה ל-https.',
            )];
        }

        return array_merge($this->certificate($site), $this->plainHttp($site));
    }

    /** @return list<Finding> */
    private function certificate(AuditContext $site): array
    {
        $days = $this->diagnostics->sslDaysLeft($site->host);

        if ($days === null) {
            return [Finding::notice(
                $this->area(),
                'לא ניתן היה לקרוא את תעודת האבטחה',
                'הבדיקה לא הצליחה לקרוא את תוקף התעודה. ייתכן חסימה של הבדיקה, ולא בהכרח תקלה.',
                'לוודא ידנית בדפדפן שהתעודה בתוקף.',
            )];
        }

        if ($days < 0) {
            return [Finding::critical(
                $this->area(),
                'תעודת האבטחה פגה',
                'הדפדפן מציג אזהרה אדומה במקום האתר. רוב המבקרים עוזבים בשלב הזה.',
                'לחדש את התעודה מיד ולהפעיל חידוש אוטומטי.',
                "פג לפני {$this->plural(abs($days))}",
            )];
        }

        if ($days <= 21) {
            return [Finding::warning(
                $this->area(),
                'תעודת האבטחה עומדת לפוג',
                'כשהיא תפוג האתר יציג אזהרת אבטחה לכל מבקר, בדרך כלל בלי התראה מוקדמת.',
                'לחדש עכשיו ולהפעיל חידוש אוטומטי כדי שזה לא יחזור.',
                "נותרו {$this->plural($days)}",
            )];
        }

        return [Finding::ok($this->area(), 'תעודת האבטחה בתוקף', "נותרו {$this->plural($days)}.")];
    }

    /**
     * A visitor who types the address without "https" must be carried across.
     *
     * @return list<Finding>
     */
    private function plainHttp(AuditContext $site): array
    {
        $probe = SiteProbe::fetch('http://'.$site->host, follow: false);
        $location = $probe->header('location');

        if ($probe->status !== null && $probe->status >= 300 && $probe->status < 400
            && $location !== null && str_starts_with(mb_strtolower($location), 'https://')) {
            return [Finding::ok($this->area(), 'פנייה לא מאובטחת מופנית לחיבור מאובטח')];
        }

        if ($probe->error !== null) {
            return [];
        }

        return [Finding::warning(
            $this->area(),
            'כתובת ללא https אינה מופנית לחיבור המאובטח',
            'מי שמקליד את הכתובת בלי https, או מגיע מלינק ישן, ממשיך לגלוש בחיבור לא מאובטח.',
            'להוסיף הפניה קבועה (301) מכל כתובת http לאותה כתובת ב-https.',
            $location !== null ? 'הפניה אל '.mb_substr($location, 0, 120) : 'HTTP '.$probe->status,
        )];
    }

    private function plural(int $days): string
    {
        return $days === 1 ? 'יום אחד' : "{$days} ימים";
    }
}
