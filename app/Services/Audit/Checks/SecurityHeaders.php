<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;

/**
 * The instructions a site gives the browser about its own safety.
 *
 * Cheap to add and invisible when present, which is exactly why they are so
 * often absent — nobody notices their absence either, until the day somebody
 * frames the site or a script runs where it should not have.
 */
class SecurityHeaders implements Check
{
    /** header => [what it stops, what to do] */
    private const EXPECTED = [
        'strict-transport-security' => [
            'הדפדפן אינו מונחה לזכור שהאתר מאובטח',
            'מבקר שכבר ביקר באתר עדיין יכול להישלח לגרסה לא מאובטחת שלו ברשת ציבורית.',
            'להוסיף כותרת Strict-Transport-Security — שורה אחת בהגדרות השרת.',
        ],
        'x-content-type-options' => [
            'הדפדפן רשאי לנחש סוגי קבצים',
            'קובץ שהועלה לאתר יכול להתפרש כקוד במקום כתמונה.',
            'להוסיף כותרת X-Content-Type-Options: nosniff.',
        ],
        'x-frame-options' => [
            'ניתן להטמיע את האתר בתוך אתר אחר',
            'טכניקת הונאה נפוצה: האתר שלכם מוצג בתוך מסגרת באתר זר, והלחיצות נגנבות.',
            'להוסיף כותרת X-Frame-Options: SAMEORIGIN, או Content-Security-Policy עם frame-ancestors.',
        ],
    ];

    public function area(): string
    {
        return 'הגנות דפדפן';
    }

    public function run(AuditContext $site): array
    {
        if (! $site->home->reachable()) {
            return [];
        }

        $missing = [];

        foreach (self::EXPECTED as $header => [$title, $detail, $fix]) {
            if (! $site->home->hasHeader($header) && ! $this->coveredOtherwise($header, $site)) {
                $missing[] = Finding::notice($this->area(), $title, $detail, $fix);
            }
        }

        if ($missing === []) {
            $missing[] = Finding::ok($this->area(), 'הגנות הדפדפן מוגדרות');
        }

        return array_merge($missing, $this->versionLeak($site));
    }

    /**
     * Whether a newer header already does the older one's job.
     *
     * A site that dropped X-Frame-Options in favour of a Content-Security-Policy
     * with frame-ancestors did the modern, better thing — and telling it that it
     * can be framed is both wrong and insulting to whoever did the work. The fix
     * text already names CSP as the alternative; the check has to know it too.
     */
    private function coveredOtherwise(string $header, AuditContext $site): bool
    {
        return $header === 'x-frame-options'
            && str_contains(mb_strtolower((string) $site->home->header('content-security-policy')), 'frame-ancestors');
    }

    /**
     * A server that announces its exact version.
     *
     * @return list<Finding>
     */
    private function versionLeak(AuditContext $site): array
    {
        foreach (['server', 'x-powered-by'] as $header) {
            $value = (string) $site->home->header($header);

            if (preg_match('/\d+\.\d+/', $value) === 1) {
                return [Finding::notice(
                    $this->area(),
                    'השרת מפרסם את מספר הגרסה שלו',
                    'תוקף שמחפש אתרים פגיעים לגרסה מסוימת מוצא אותם בדיוק כך, בלי לנסות דבר.',
                    'לכבות את פרסום הגרסה בהגדרות השרת.',
                    mb_substr($value, 0, 100),
                )];
            }
        }

        return [];
    }
}
