<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;

/**
 * קישורים מהדף הראשי שמובילים לשום מקום.
 *
 * מבקר שלוחץ על "אודות" ומקבל 404 מסיק דבר אחד על העסק, והוא לא חוזר לבדוק אם
 * שאר הקישורים עובדים. זו גם התקלה שבעל האתר הכי פחות סביר לגלות בעצמו: הוא
 * מגיע לעמודים דרך הניהול, לא דרך התפריט.
 *
 * נדגמים קישורים ספורים ולא כולם, משתי סיבות שאינן טכניות: בדיקה ששולחת מאה
 * בקשות לשרת של מישהו אחר היא היכרות גרועה, ועלולה להפעיל חומת אש ואז לדווח על
 * תקלה שהיא עצמה גרמה. הדוח אומר במפורש כמה נבדקו, כדי שדגימה לא תיקרא כסריקה.
 */
class Links implements Check, ReadsPage
{
    /** מספיק כדי לגלות הזנחה, מעט מספיק כדי לא להעיק על השרת. */
    private const SAMPLE = 8;

    public function area(): string
    {
        return 'קישורים';
    }

    public function run(AuditContext $site): array
    {
        if (! $site->home->reachable()) {
            return [];
        }

        $paths = self::internalPaths($site);

        if ($paths === []) {
            return [];
        }

        $broken = [];

        foreach ($paths as $path) {
            $probe = $site->path($path);

            // חסום אינו שבור. חומת אש שדוחה בדיקה אוטומטית תיראה בדיוק כמו עמוד
            // חסר, ולדווח עליה כקישור שבור זו האשמה בתקלה שאינה קיימת.
            if ($probe->reachable() || $probe->blocked()) {
                continue;
            }

            $broken[] = $path.' — '.($probe->status !== null ? 'HTTP '.$probe->status : 'לא נענה');
        }

        $checked = count($paths);

        if ($broken === []) {
            return [Finding::ok(
                $this->area(),
                'הקישורים שנבדקו עובדים',
                "נבדקו {$checked} קישורים מהדף הראשי, וכולם נענו.",
            )];
        }

        $count = count($broken);

        return [Finding::warning(
            $this->area(),
            'יש בדף הראשי קישורים שבורים',
            "מתוך {$checked} קישורים שנבדקו, {$count} מובילים לעמוד שאינו קיים. מבקר שלוחץ עליהם מקבל שגיאה — וגם גוגל, שסופר קישורים שבורים לרעת האתר.",
            'לתקן או להסיר את הקישורים. באתר ותיק זה בדרך כלל שריד לעמודים שנמחקו או שונו.',
            implode(' · ', array_slice($broken, 0, 5)),
        )];
    }

    /**
     * נתיבים ייחודיים באותו אתר, עד לגודל המדגם.
     *
     * קישורי עוגן, טלפון, מייל וקבצים אינם עמודים; קישורים לאתרים אחרים אינם
     * באחריות בעל האתר, ובדיקתם הייתה הופכת את הכלי לסורק של צד שלישי.
     *
     * @return list<string>
     */
    private static function internalPaths(AuditContext $site): array
    {
        preg_match_all('#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#is', $site->markup(), $found);

        $base = mb_strtolower($site->base());
        $paths = [];

        foreach ($found[2] ?? [] as $href) {
            $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));

            if ($href === '' || preg_match('#^(\#|mailto:|tel:|javascript:|data:|whatsapp:)#i', $href) === 1) {
                continue;
            }

            if (preg_match('#^https?://#i', $href) === 1) {
                if (! str_starts_with(mb_strtolower($href), $base)) {
                    continue;
                }

                $href = mb_substr($href, mb_strlen($base));
            }

            $path = '/'.ltrim(explode('#', $href, 2)[0], '/');

            // עמוד, לא נכס. תמונה או PDF שבורים הם ממצא אחר לגמרי.
            if ($path === '/' || preg_match('#\.(jpe?g|png|gif|webp|svg|css|js|pdf|zip|mp4|ico)$#i', $path) === 1) {
                continue;
            }

            $paths[$path] = true;

            if (count($paths) >= self::SAMPLE) {
                break;
            }
        }

        return array_keys($paths);
    }
}
