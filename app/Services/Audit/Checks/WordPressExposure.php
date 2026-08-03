<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;

/**
 * The doors a WordPress install leaves open by default.
 *
 * Every one of these is standard behaviour nobody chose — which is why they are
 * worth reporting: the owner did not decide to publish the list of usernames,
 * it simply is published, and it has been since the day the site went up.
 */
class WordPressExposure implements Check, ReadsPage
{
    public function area(): string
    {
        return 'וורדפרס';
    }

    public function run(AuditContext $site): array
    {
        if (! $site->home->reachable() || ! $this->isWordPress($site)) {
            return [];
        }

        return array_filter(array_merge(
            [$this->version($site), $this->users($site), $this->xmlrpc($site), $this->readme($site)],
            $this->uploads($site),
        ));
    }

    private function isWordPress(AuditContext $site): bool
    {
        return $site->occurrences('#/wp-(content|includes)/#i') > 0
            || $site->match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\'](WordPress[^"\']*)#i') !== null;
    }

    private function version(AuditContext $site): ?Finding
    {
        $generator = $site->match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\']WordPress\s+([0-9.]+)#i');

        if ($generator === null) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'האתר מפרסם את גרסת הוורדפרס שלו',
            "הגרסה {$generator} מופיעה בקוד המקור של כל דף. תוקף שמחפש אתרים בגרסה פגיעה מסננת לפי זה.",
            'להסיר את תגית ה-generator (שורה אחת בתבנית או תוסף אבטחה).',
            'WordPress '.$generator,
        );
    }

    /**
     * The REST endpoint that lists who writes on the site.
     *
     * Half of a break-in is the username, and this hands it over. It is on by
     * default and almost nobody knows it exists.
     */
    private function users(AuditContext $site): ?Finding
    {
        $probe = $site->path('/wp-json/wp/v2/users');

        if (! $probe->reachable() || ! str_contains($probe->body, '"slug"')) {
            return null;
        }

        $names = preg_match_all('#"slug"\s*:\s*"([^"]+)"#', $probe->body, $found);

        return Finding::warning(
            $this->area(),
            'שמות המשתמשים של האתר גלויים לכל',
            'ניתן לקבל את רשימת המשתמשים בכתובת אחת, בלי סיסמה. זה חצי מהעבודה של מי שמנסה לפרוץ.',
            'לחסום את הנתיב הזה (תוסף אבטחה או כלל בשרת), ולוודא סיסמאות חזקות ואימות דו-שלבי למנהלים.',
            $names > 0 ? 'נמצאו '.$names.' משתמשים, למשל: '.$found[1][0] : null,
        );
    }

    private function xmlrpc(AuditContext $site): ?Finding
    {
        $probe = $site->path('/xmlrpc.php');

        // 405 is what a live xmlrpc.php answers to a GET — present and enabled.
        if ($probe->status !== 405 && ! ($probe->status === 200 && str_contains($probe->body, 'XML-RPC'))) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'ממשק XML-RPC פתוח',
            'ממשק ישן שכמעט אינו בשימוש היום, ומשמש בעיקר לניסיונות פריצה מרובים ולעומס על השרת.',
            'לכבות אותו אם אין אפליקציה שמשתמשת בו.',
        );
    }

    private function readme(AuditContext $site): ?Finding
    {
        $probe = $site->path('/readme.html');

        if (! $probe->reachable() || ! str_contains($probe->body, 'WordPress')) {
            return null;
        }

        return Finding::notice(
            $this->area(),
            'קובץ ה-readme של וורדפרס נגיש',
            'הקובץ מגלה את גרסת הוורדפרס גם אחרי שהסתירו אותה במקומות אחרים.',
            'למחוק את הקובץ — הוא אינו נחוץ לתפעול האתר.',
        );
    }

    /**
     * A folder that lists its own contents.
     *
     * @return list<Finding>
     */
    private function uploads(AuditContext $site): array
    {
        $probe = $site->path('/wp-content/uploads/');

        if (! $probe->reachable() || ! str_contains($probe->body, 'Index of')) {
            return [];
        }

        return [Finding::warning(
            $this->area(),
            'תיקיית הקבצים של האתר מציגה את רשימת הקבצים',
            'כל אחד יכול לעיין בקבצים שהועלו לאתר, כולל מסמכים שהועלו ולא קושרו לאף דף.',
            'לכבות הצגת תוכן תיקיות בהגדרות השרת.',
        )];
    }
}
