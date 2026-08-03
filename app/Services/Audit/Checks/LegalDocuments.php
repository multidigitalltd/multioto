<?php

namespace App\Services\Audit\Checks;

use App\Services\Audit\AuditContext;
use App\Services\Audit\Finding;
use App\Services\Audit\Trackers;

/**
 * המסמכים שאתר עסקי בישראל חייב לפרסם.
 *
 * זה התחום היחיד בדוח שבו הממצא אינו "האתר עובד פחות טוב" אלא "יש כאן חשיפה
 * משפטית", ולכן הוא עומד בפני עצמו ולא מפוזר בין נגישות ל-SEO. מי שקורא את
 * הדוח צריך לראות במקום אחד את כל מה שהחוק דורש ממנו לפרסם — וזה גם הסעיף
 * שהכי קל למכור, כי התיקון שלו הוא כתיבה ולא פיתוח.
 *
 * הבדיקה מחפשת קישור או אזכור בדף הראשי, כי שם המסמכים האלה אמורים להיות
 * נגישים מכל דף — בדרך כלל בתחתית. היעדרם מהדף הראשי אינו הוכחה שהם לא קיימים
 * איפשהו, וכך גם נוסח הממצא.
 */
class LegalDocuments implements Check, ReadsPage
{
    /**
     * מסמך => [ביטויים לזיהוי, כותרת, הסבר, תיקון, האם רק לחנות]
     *
     * @var array<string, array{0: list<string>, 1: string, 2: string, 3: string, 4: bool}>
     */
    private const DOCUMENTS = [
        'accessibility' => [
            ['הצהרת נגישות', 'accessibility-statement', 'accessibility statement'],
            'לא נמצאה הצהרת נגישות',
            'אתר עסקי בישראל נדרש לפרסם הצהרת נגישות נגישה מכל דף. היעדרה היא החשיפה הנפוצה ביותר לתביעה — ותביעות כאלה מוגשות בפועל, בסכומים שאינם דורשים הוכחת נזק.',
            'לפרסם הצהרת נגישות ולקשר אליה מהתחתית של כל דף.',
            false,
        ],
        'privacy' => [
            ['מדיניות פרטיות', 'הצהרת פרטיות', 'privacy-policy', 'privacy policy'],
            'לא נמצאה מדיניות פרטיות',
            'כל אתר שאוסף פרטים — טופס יצירת קשר, הרשמה לדיוור, עגלת קניות או אפילו Google Analytics — חייב להסביר איזה מידע נאסף ומה נעשה בו. זו דרישה של חוק הגנת הפרטיות, ותנאי של גוגל ופייסבוק להרצת פרסום.',
            'לפרסם עמוד מדיניות פרטיות ולקשר אליו מתחתית האתר ומכל טופס.',
            false,
        ],
        'terms' => [
            ['תקנון', 'תנאי שימוש', 'תנאים והגבלות', 'terms-of-use', 'terms-and-conditions', 'terms of service'],
            'לא נמצא תקנון או תנאי שימוש',
            'התקנון הוא מה שקובע מה מובטח ללקוח ומה לא — ובלעדיו, בכל מחלוקת, הפרשנות היא של הצד השני.',
            'לפרסם תקנון או תנאי שימוש ולקשר אליו מתחתית האתר.',
            false,
        ],
        'returns' => [
            ['מדיניות ביטול', 'ביטול עסקה', 'מדיניות החזר', 'החזרות', 'מדיניות משלוח', 'refund-policy', 'return-policy'],
            'לא נמצאה מדיניות ביטולים והחזרות',
            'חנות מקוונת חייבת להציג את תנאי הביטול לפי חוק הגנת הצרכן, לפני ביצוע ההזמנה. היעדרם אינו רק חשיפה — הוא גם הסיבה הנפוצה לנטישת עגלה אצל מי שמתלבט.',
            'לפרסם מדיניות ביטולים, החזרות ומשלוחים, ולקשר אליה מעמוד המוצר ומעמוד התשלום.',
            true,
        ],
    ];

    public function area(): string
    {
        return 'מסמכי חובה';
    }

    public function run(AuditContext $site): array
    {
        if (! $site->home->reachable()) {
            return [];
        }

        $store = self::looksLikeStore($site);
        $findings = [];
        $found = 0;
        $asked = 0;

        foreach (self::DOCUMENTS as [$phrases, $title, $detail, $fix, $storeOnly]) {
            if ($storeOnly && ! $store) {
                continue;
            }

            $asked++;

            if (self::mentions($site, $phrases)) {
                $found++;

                continue;
            }

            $findings[] = Finding::warning($this->area(), $title, $detail, $fix);
        }

        if ($found === $asked && $asked > 0) {
            $findings[] = Finding::ok($this->area(), 'כל מסמכי החובה מקושרים מהדף הראשי');
        }

        return array_merge($findings, array_filter([
            $this->businessDetails($site, $store),
            $this->cookieConsent($site),
        ]));
    }

    /**
     * באנר הסכמה — נשאל רק כשיש על מה להסכים.
     *
     * פיקסל של פייסבוק או אנליטיקס שרצים לפני שנשאלה שאלה הם מעקב בלי רשות, וזו
     * חשיפה אמיתית. אבל אתר סטטי בלי שום כלי מעקב אינו חייב באנר, ולדרוש ממנו
     * אחד זה גם להטריד וגם לחשוף שהבדיקה סופרת רכיבים במקום לשאול שאלה.
     */
    private function cookieConsent(AuditContext $site): ?Finding
    {
        $trackers = Trackers::measuring($site->markup());

        if ($trackers === [] || Trackers::asksConsent($site->markup())) {
            return null;
        }

        return Finding::warning(
            $this->area(),
            'האתר עוקב אחרי מבקרים בלי לבקש הסכמה',
            'נמצאו כלי מעקב ('.implode(', ', $trackers).') שנטענים לפני שהמבקר נשאל דבר. איסוף מידע דרך עוגיות מחייב הסכמה, וזו גם דרישה של גוגל ופייסבוק ממי שמריץ אצלם פרסום.',
            'להתקין באנר הסכמה לעוגיות שחוסם את כלי המעקב עד לאישור, ולקשר ממנו למדיניות הפרטיות.',
        );
    }

    /**
     * פרטי העוסק — מי עומד מאחורי האתר.
     *
     * חנות מקוונת חייבת להציג שם עוסק ומספר עוסק/ח.פ. מעבר לחוק, זה גם אחד
     * הדברים הראשונים שקונה מהסס מחפש: אתר בלי שם, מספר וטלפון נראה כמו אתר
     * שאי אפשר לחזור אליו אחרי התשלום.
     */
    private function businessDetails(AuditContext $site, bool $store): ?Finding
    {
        if (! $store) {
            return null;
        }

        if ($site->occurrences('#(ח\.?\s*פ\.?|ע\.?\s*מ\.?|עוסק\s*(מורשה|פטור)|ח״פ|ע״מ)[^\d]{0,12}\d{8,9}#u') > 0) {
            return null;
        }

        return Finding::warning(
            $this->area(),
            'לא נמצאו פרטי העוסק באתר',
            'חנות מקוונת נדרשת להציג את שם העוסק ומספר העוסק או ח.פ. זו גם השאלה הראשונה של קונה מהסס: מי עומד מאחורי האתר, ולמי חוזרים אם משהו משתבש.',
            'להוסיף בתחתית האתר את שם העסק, מספר העוסק/ח.פ, כתובת וטלפון.',
        );
    }

    /**
     * האם זה בכלל אתר שמוכר.
     *
     * חשוב לשאול, כי הדרישות של חוק הגנת הצרכן חלות על מכירה — ולדרוש מאתר
     * תדמית מדיניות החזרות זו התראת שווא, מהסוג שגורם לקורא להפסיק להאמין
     * לשאר הדוח.
     */
    private static function looksLikeStore(AuditContext $site): bool
    {
        return $site->occurrences('#(woocommerce|add-to-cart|add_to_cart|/cart|/checkout|הוספה לסל|הוסף לסל|לרכישה|עגלת קניות)#iu') > 0;
    }

    /**
     * האם הדף מזכיר או מקשר למסמך.
     *
     * @param  list<string>  $phrases
     */
    private static function mentions(AuditContext $site, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if ($site->occurrences('#'.preg_quote($phrase, '#').'#iu') > 0) {
                return true;
            }
        }

        return false;
    }
}
