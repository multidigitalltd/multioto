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

        if (preg_match('#^\s*Disallow:\s*/\s*$#mi', $probe->body) === 1) {
            return Finding::critical(
                $this->area(),
                'הקובץ robots.txt חוסם את כל האתר מגוגל',
                'הקובץ אומר למנועי החיפוש לא לסרוק שום דף. זו הסיבה הנפוצה ביותר לאתר שפשוט לא מופיע בחיפוש.',
                'להסיר את החסימה הגורפת — לרוב היא נשארה מהתקופה שבה האתר היה בבנייה.',
                'Disallow: /',
            );
        }

        return null;
    }

    private function sitemap(AuditContext $site): ?Finding
    {
        foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'] as $path) {
            if ($site->path($path)->reachable()) {
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
