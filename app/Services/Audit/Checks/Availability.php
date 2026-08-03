<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;
use App\Services\Audit\Hostname;
use App\Services\Audit\SiteProbe;

/** Does the site answer at all, and does it answer to both of its names. */
class Availability implements Check
{
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

        // Asked before the status is read as a fault, because it is not one.
        // The second name would be refused by the same firewall, so checking it
        // would only add a second wrong finding to the first.
        if ($home->blocked()) {
            return [$this->refused($site)];
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
     * האתר לא נשבר — הוא פשוט לא נתן לנו להיכנס.
     *
     * חומת אש (קלאודפלייר, סוקורי וכדומה) שחוסמת בקשה אוטומטית משרת היא הסיבה
     * הסבירה ביותר לכך שאתר בריא לגמרי עונה לנו בשגיאה. המבקר שנדחה כאן הוא
     * אנחנו, לא הלקוח — ולכתוב לבעל האתר "האתר שלך מחזיר 403" זו האשמה בתקלה
     * שאין לו, במסמך שכל השאר בו נשען על האמון שהשורה הזו הורסת.
     */
    private function refused(AuditContext $site): Finding
    {
        $guard = $site->home->guard();
        $evidence = 'HTTP '.$site->home->status.($guard !== null ? ' · '.$guard : '');

        if ($site->home->status === 401) {
            return Finding::notice(
                $this->area(),
                'האתר מוגן בסיסמה',
                'הכתובת מבקשת שם משתמש וסיסמה לפני שהיא מציגה דבר. זה תקין באתר בבנייה או בסביבת בדיקות, אך המשמעות היא שאיש — כולל גוגל — אינו רואה אותו.',
                'אם זו הגרסה החיה, להסיר את ההגנה; אם לא, לבדוק את הכתובת של האתר החי.',
                $evidence,
            );
        }

        return Finding::notice(
            $this->area(),
            'האתר חסם את הבדיקה',
            'חומת האש של האתר'.($guard !== null ? " ({$guard})" : '').' דחתה את הפנייה שלנו לפני שהגיעה לאתר. '
                .'ברוב המקרים זה לא אומר דבר על תקינות האתר — כך בדיוק אמורה חומת אש להתנהג מול פנייה אוטומטית משרת. '
                .'המשמעות היא שהבדיקות שקוראות את תוכן הדף לא יכלו להתבצע.',
            'לבדוק ידנית בדפדפן, או להתיר זמנית בחומת האש את הכתובת שממנה רצה הבדיקה ולהריץ שוב.',
            $evidence,
        );
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
        $other = Hostname::counterpart($host);

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

        // Answering is not the same as agreeing. Two names that each serve the
        // site at their own address, with no redirect and no canonical tag
        // between them, are two sites as far as a search engine is concerned —
        // which is the fault this check exists to find, and the one it would
        // otherwise mark green.
        if ($this->converges($probe, $site)) {
            return [Finding::ok($this->area(), 'שתי צורות הכתובת מובילות לאותו מקום', "גם {$host} וגם {$other} נענים, ואחת מפנה לשנייה.")];
        }

        return [Finding::warning(
            $this->area(),
            'שתי צורות הכתובת מגישות את האתר בנפרד',
            "גם {$host} וגם {$other} מחזירים את האתר בלי שאחת מפנה לשנייה. לגוגל אלה שני אתרים עם אותו תוכן, והדירוג מתחלק ביניהם.",
            'לבחור כתובת ראשית אחת ולהפנות אליה בהפניה קבועה (301) מהשנייה.',
        )];
    }

    /** Whether the other name ends up at the same place, by redirect or by canonical. */
    private function converges(SiteProbe $probe, AuditContext $site): bool
    {
        $canonical = self::origin($site->base());

        if (self::origin($probe->finalUrl) === $canonical) {
            return true;
        }

        // A rel=canonical pointing home is the site saying, in the only other
        // way available to it, which of the two addresses is the real one.
        return preg_match('#<link\b[^>]*\brel\s*=\s*["\']?canonical["\']?[^>]*>#i', $probe->body, $tag) === 1
            && preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#is', $tag[0], $href) === 1
            && self::origin($href[2]) === $canonical;
    }

    /** Scheme and host, which is all "the same place" means here. */
    private static function origin(string $url): string
    {
        $parts = parse_url(trim($url));

        return mb_strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? ''));
    }
}
