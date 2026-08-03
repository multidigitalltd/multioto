<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\CertificateInspector;
use App\Services\Audit\Finding;
use App\Services\Audit\SiteProbe;

/** HTTPS: is there one, would a browser accept it, and does plain http lead to it. */
class Transport implements Check
{
    public function __construct(private CertificateInspector $certificates) {}

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

    /**
     * What a browser would make of the certificate — not merely when it expires.
     *
     * A date that has not passed says nothing about a certificate issued for
     * another name, signed by nobody, or not yet in force. Every one of those
     * shows the visitor a full-page warning, and calling it "valid" in a report
     * a customer relies on is the worst thing this tool could get wrong.
     *
     * Every https address the visit passed through is examined, not only the one
     * that was typed: a site that redirects hands the visitor from certificate
     * to certificate, and the one they end up in front of is often not the one
     * the audit started at.
     *
     * @return list<Finding>
     */
    private function certificate(AuditContext $site): array
    {
        $origins = $site->httpsOrigins() ?: [['host' => $site->host, 'port' => 443]];
        $lifetimes = [];

        foreach ($origins as $origin) {
            $result = $this->certificates->inspect($origin['host'], $origin['port']);
            $where = $this->where($origin, count($origins));

            if (! $result['reachable']) {
                return [Finding::notice(
                    $this->area(),
                    'לא ניתן היה לבדוק את תעודת האבטחה',
                    'הבדיקה לא הצליחה ליצור חיבור מאובטח לשרת. ייתכן חסימה של הבדיקה, ולא בהכרח תקלה — אך זה גם לא אישור שהכול תקין.',
                    'לוודא ידנית בדפדפן שהתעודה בתוקף ומזוהה.',
                    $this->join($where, $result['error']),
                )];
            }

            if (! $result['trusted']) {
                return [Finding::critical(
                    $this->area(),
                    'הדפדפן אינו מקבל את תעודת האבטחה',
                    'התעודה אינה מזוהה כתקפה לאתר הזה — היא חתומה עצמית, הונפקה לשם אחר, אינה מגורם מוכר או שטרם נכנסה לתוקף. המבקר רואה מסך אזהרה במקום האתר.',
                    'להנפיק תעודה מגורם מוכר עבור השם המדויק של האתר, ולוודא שהותקנה כולל שרשרת האישורים.',
                    $this->join($where, $result['error']),
                )];
            }

            if ($result['days_left'] !== null) {
                $lifetimes[] = (int) $result['days_left'];
            }
        }

        // The soonest expiry across the chain: the first one to lapse breaks the
        // visit, wherever along the way it sits.
        return $this->expiry($lifetimes === [] ? 0 : min($lifetimes), $lifetimes === []);
    }

    /**
     * Which endpoint a certificate finding is about — named only when it could
     * be more than one, so an ordinary single-address site is not made to look
     * complicated.
     *
     * @param  array{host: string, port: int}  $origin
     */
    private function where(array $origin, int $total): ?string
    {
        if ($total < 2 && $origin['port'] === 443) {
            return null;
        }

        return $origin['host'].($origin['port'] === 443 ? '' : ':'.$origin['port']);
    }

    private function join(?string $where, ?string $error): ?string
    {
        return implode(' — ', array_filter([$where, $error])) ?: null;
    }

    /** @return list<Finding> */
    private function expiry(int $days, bool $unknown): array
    {
        if ($unknown) {
            return [Finding::ok($this->area(), 'תעודת האבטחה מזוהה כתקפה')];
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

        return [Finding::ok($this->area(), 'תעודת האבטחה תקפה ומזוהה', "נותרו {$this->plural($days)}.")];
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
        return match (true) {
            $days < 0 => abs($days).' ימים (פגה)',
            $days === 1 => 'יום אחד',
            default => "{$days} ימים",
        };
    }
}
