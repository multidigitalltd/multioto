<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;

/** What a search engine, and a link shared in WhatsApp, make of the page. */
class Discoverability implements Check
{
    public function area(): string
    {
        return 'נראות בגוגל ובשיתופים';
    }

    public function run(AuditContext $site): array
    {
        if (! $site->home->reachable()) {
            return [];
        }

        return array_filter(array_merge(
            [$this->title($site), $this->description($site), $this->heading($site), $this->sharing($site)],
            [$this->robots($site), $this->sitemap($site)],
        ));
    }

    private function title(AuditContext $site): ?Finding
    {
        $title = $site->match('#<title[^>]*>(.*?)</title>#is');

        if ($title === null || $title === '') {
            return Finding::critical(
                $this->area(),
                'לדף הראשי אין כותרת',
                'הכותרת היא השורה הכחולה בתוצאות החיפוש ובלשונית הדפדפן. בלעדיה גוגל ממציא אחת.',
                'להגדיר כותרת ייחודית לכל דף, שמתארת את העסק ואת מה שהוא מציע.',
            );
        }

        $length = mb_strlen(strip_tags($title));

        if ($length > 65) {
            return Finding::notice(
                $this->area(),
                'הכותרת ארוכה ותיחתך בתוצאות החיפוש',
                'גוגל מציג בערך 60 תווים. מה שמעבר לזה נעלם, ולעיתים זה החלק החשוב.',
                'לקצר את הכותרת ולהעביר את המילים החשובות להתחלה.',
                "{$length} תווים",
            );
        }

        return Finding::ok($this->area(), 'לדף יש כותרת');
    }

    private function description(AuditContext $site): ?Finding
    {
        if ($site->match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']{10,})#i') !== null) {
            return null;
        }

        return Finding::warning(
            $this->area(),
            'אין תיאור לדף',
            'התיאור הוא שתי השורות שמתחת לכותרת בתוצאות החיפוש. בלעדיו גוגל שולף טקסט אקראי מהדף.',
            'לכתוב תיאור של כ-150 תווים לכל דף מרכזי.',
        );
    }

    private function heading(AuditContext $site): ?Finding
    {
        $headings = $site->occurrences('#<h1\b#i');

        if ($headings === 1) {
            return null;
        }

        return $headings === 0
            ? Finding::warning(
                $this->area(),
                'אין כותרת ראשית (H1) בדף',
                'הכותרת הראשית אומרת לגוגל — ולקורא מסך — במה הדף עוסק.',
                'להגדיר כותרת H1 אחת בכל דף.',
            )
            : Finding::notice(
                $this->area(),
                'יש בדף יותר מכותרת ראשית אחת',
                "נמצאו {$headings} כותרות H1. כשהכול ראשי, שום דבר אינו ראשי.",
                'להשאיר H1 אחת ולהפוך את השאר ל-H2.',
            );
    }

    /** The card that appears when the link is pasted into WhatsApp or Facebook. */
    private function sharing(AuditContext $site): ?Finding
    {
        if ($site->occurrences('#<meta[^>]+property=["\']og:(title|image)["\']#i') >= 2) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'לינק לאתר משותף בלי תמונה וכותרת',
            'כשמדביקים את הכתובת בוואטסאפ או בפייסבוק לא מופיע כרטיס עם תמונה — רק כתובת יבשה, שנלחצת הרבה פחות.',
            'להוסיף תגיות Open Graph: og:title, og:description ו-og:image.',
        );
    }

    private function robots(AuditContext $site): ?Finding
    {
        $probe = $site->path('/robots.txt');

        if (! $probe->reachable()) {
            return Finding::notice(
                $this->area(),
                'אין קובץ robots.txt',
                'הקובץ מנחה את מנועי החיפוש מה לסרוק. היעדרו אינו חוסם, אך זו הזדמנות שלא נוצלה.',
                'להוסיף robots.txt עם הפניה למפת האתר.',
            );
        }

        if (! self::blocksGoogle($probe->body)) {
            return null;
        }

        return Finding::critical(
            $this->area(),
            'הקובץ robots.txt חוסם את כל האתר מגוגל',
            'הקובץ אומר למנועי החיפוש לא לסרוק שום דף. זו הסיבה הנפוצה ביותר לאתר שפשוט לא מופיע בחיפוש.',
            'להסיר את החסימה הגורפת — לרוב היא נשארה מהתקופה שבה האתר היה בבנייה.',
            'Disallow: /',
        );
    }

    /**
     * Whether the file blocks GOOGLE from the whole site — not merely somebody.
     *
     * The directives belong to the user-agent group above them, so a site that
     * turned away an AI crawler ("User-agent: GPTBot" then "Disallow: /") is
     * doing something deliberate and correct. Reporting that as "you are
     * invisible on Google" would be a false alarm of the most alarming kind, in
     * a document handed to somebody who cannot check it.
     */
    private static function blocksGoogle(string $robots): bool
    {
        $applies = false;
        $blocked = false;

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if (preg_match('/^user-agent\s*:\s*(.+)$/i', $line, $agent) === 1) {
                // A new group starts wherever a user-agent line follows a rule.
                if ($blocked && $applies) {
                    return true;
                }

                $name = mb_strtolower(trim($agent[1]));
                $applies = $name === '*' || str_starts_with($name, 'googlebot');
                $blocked = false;

                continue;
            }

            if ($applies && preg_match('/^disallow\s*:\s*\/\s*$/i', $line) === 1) {
                $blocked = true;
            }

            // An explicit allow anywhere in the group means the block is not total.
            if ($applies && preg_match('/^allow\s*:\s*\//i', $line) === 1) {
                $blocked = false;
            }
        }

        return $blocked && $applies;
    }

    private function sitemap(AuditContext $site): ?Finding
    {
        foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'] as $path) {
            $probe = $site->path($path);

            // Reachable is not enough. A site whose 404 handler answers 200 with
            // its ordinary page would make every guess look like a sitemap, and
            // the check would quietly pass for a site that has none.
            if ($probe->reachable() && preg_match('/<(urlset|sitemapindex)\b/i', $probe->body) === 1) {
                return null;
            }
        }

        return Finding::notice(
            $this->area(),
            'לא נמצאה מפת אתר',
            'מפת אתר עוזרת לגוגל למצוא את כל הדפים, במיוחד באתר גדול או חדש.',
            'להפעיל מפת אתר (כל תוסף SEO מייצר אחת) ולהגיש אותה ב-Search Console.',
        );
    }
}
