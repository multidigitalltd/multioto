<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;

/** What a search engine, and a link shared in WhatsApp, make of the page. */
class Discoverability implements Check, ReadsPage
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
        if (self::meta($site->markup(), 'name', 'description', 10) !== null) {
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
        $markup = $site->markup();

        if (self::meta($markup, 'property', 'og:title') !== null && self::meta($markup, 'property', 'og:image') !== null) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'לינק לאתר משותף בלי תמונה וכותרת',
            'כשמדביקים את הכתובת בוואטסאפ או בפייסבוק לא מופיע כרטיס עם תמונה — רק כתובת יבשה, שנלחצת הרבה פחות.',
            'להוסיף תגיות Open Graph: og:title, og:description ו-og:image.',
        );
    }

    /**
     * The content of a meta tag identified by one of its attributes.
     *
     * Attributes in HTML have no order — `<meta content="…" name="description">`
     * is the same tag as the other way round — and a pattern that insists on one
     * order tells a site with a perfectly good description that it has none. In
     * a document handed to a prospect, that is the finding that gets checked
     * first and discredits everything under it.
     */
    private static function meta(string $markup, string $attribute, string $value, int $minimum = 1): ?string
    {
        preg_match_all('#<meta\b[^>]*>#i', $markup, $tags);

        foreach ($tags[0] as $tag) {
            // (?<![-\w]) and not \b: \b would also match the tail of data-name=,
            // and a tag matched by the wrong attribute is the same false finding
            // by another route.
            $named = preg_match('#(?<![-\w])'.$attribute.'\s*=\s*(["\']?)'.preg_quote($value, '#').'\1[\s/>]#i', $tag.' ') === 1;

            if (! $named || preg_match('#\bcontent\s*=\s*(["\'])(.*?)\1#is', $tag, $found) !== 1) {
                continue;
            }

            $content = trim($found[2]);

            if (mb_strlen($content) >= $minimum) {
                return $content;
            }
        }

        return null;
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
     *
     * Specificity decides which group applies, exactly as Google does it: a
     * group naming Googlebot is the one it obeys, and the catch-all is read only
     * when no such group exists. The common shape — everybody turned away, then
     * "User-agent: Googlebot / Allow: /" — is a site deliberately admitting only
     * Google, and calling that "blocked from Google" gets the file backwards.
     *
     * The name must be the crawler itself. Googlebot-News and Googlebot-Image
     * are separate crawlers with their own groups, and letting one of them stand
     * in for the main one turns "everybody out, news in" into a clean bill of
     * health for a site that really is invisible in search.
     */
    private static function blocksGoogle(string $robots): bool
    {
        $groups = self::groups($robots);

        $applicable = array_values(array_filter(
            $groups,
            static fn (array $group): bool => array_filter(
                $group['agents'],
                static fn (string $agent): bool => $agent === 'googlebot',
            ) !== [],
        ));

        if ($applicable === []) {
            $applicable = array_values(array_filter(
                $groups,
                static fn (array $group): bool => in_array('*', $group['agents'], true),
            ));
        }

        if ($applicable === []) {
            return false;
        }

        // Groups naming the same crawler are read together, and an explicit
        // allow anywhere among them means the block is not total.
        $blocks = array_filter($applicable, static fn (array $group): bool => $group['blocks']) !== [];
        $allows = array_filter($applicable, static fn (array $group): bool => $group['allows']) !== [];

        return $blocks && ! $allows;
    }

    /**
     * The file split into its user-agent groups, in order.
     *
     * Consecutive user-agent lines belong to one group; the first rule closes
     * the list of names, and the next user-agent line after a rule opens the
     * next group.
     *
     * @return list<array{agents: list<string>, blocks: bool, allows: bool}>
     */
    private static function groups(string $robots): array
    {
        $groups = [];
        $index = -1;
        $naming = false;

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if (preg_match('/^user-agent\s*:\s*(.+)$/i', $line, $agent) === 1) {
                if (! $naming) {
                    $groups[] = ['agents' => [], 'blocks' => false, 'allows' => false];
                    $index++;
                    $naming = true;
                }

                $groups[$index]['agents'][] = mb_strtolower(trim($agent[1]));

                continue;
            }

            if ($line === '' || $index < 0) {
                continue;
            }

            $naming = false;

            if (preg_match('/^disallow\s*:\s*\/\s*$/i', $line) === 1) {
                $groups[$index]['blocks'] = true;
            }

            if (preg_match('/^allow\s*:\s*\//i', $line) === 1) {
                $groups[$index]['allows'] = true;
            }
        }

        return $groups;
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
