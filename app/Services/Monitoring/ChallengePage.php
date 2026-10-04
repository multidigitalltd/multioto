<?php

namespace App\Services\Monitoring;

/**
 * "אמת שאתה אנושי" — זיהוי דף אימות/אתגר שמוצג במקום האתר.
 *
 * זו נפילה שבדיקת הזמינות הרגילה עיוורת לה לגמרי: שכבת ההגנה (Cloudflare,
 * Sucuri, Imperva, Imunify360…) מחזירה דף אימות, לרוב **בקוד 200** ולפעמים
 * ב-403, והאתר נראה למערכת ירוק ותקין. הגולש, לעומת זאת, לא רואה את האתר.
 *
 * כל ההחלטה כאן היא על **דיוק**, לא על כיסוי: אזהרה שקופצת על עמוד "צור קשר"
 * שיש בו reCAPTCHA נקראת פעם אחת ואז מתעלמים ממנה, וזה גרוע מלא לזהות כלל.
 * לכן שלוש דרגות, ורק הראשונה מכריעה לבדה:
 *
 * 1. **חתימת ספק** — נתיב או מזהה שקיים רק בדף אתגר (`/cdn-cgi/challenge-platform/`,
 *    `_Incapsula_Resource`, `sucuri_cloudproxy_js`). אלה אינם מופיעים בעמוד תוכן.
 * 2. **סקריפט captcha או SDK של הגנה** — reCAPTCHA/hCaptcha/Turnstile, וגם ה-SDK
 *    של AWS WAF שאמזון מנחה להטמיע בעמודי תוכן רגילים. כולם מופיעים גם בעמודים
 *    לגיטימיים, ולכן נדרש בנוסף שהעמוד יהיה **ביניים**: כמעט בלי טקסט וכמעט בלי
 *    קישורים.
 * 3. **ניסוח** — "verify you are human", "רק רגע", "בודק את הדפדפן". מילים שעמוד
 *    אמיתי יכול להכיל, ולכן גם כאן נדרש מבנה של עמוד ביניים.
 *
 * עיבוד טקסט טהור. ההבאה, ההחלטה וההתראה הן באחריות MonitorSiteJob.
 */
class ChallengePage
{
    /**
     * חתימות שקיימות רק בדף אתגר של הספק. אותיות קטנות; מושווה לגוף ה-HTML.
     *
     * @var array<string, list<string>>
     */
    private const VENDOR_SIGNATURES = [
        'cloudflare' => [
            '/cdn-cgi/challenge-platform/',
            'cf_chl_opt',
            'cf-browser-verification',
            'cf_challenge_response',
        ],
        'sucuri' => [
            'sucuri_cloudproxy_js',
            'sucuri website firewall',
            'sucuri cloudproxy',
        ],
        'imperva' => [
            '_incapsula_resource',
            'incapsula incident id',
        ],
        'imunify360' => [
            // רק המזהה של דף ה-captcha. "imunify360" לבדו מופיע גם בעמודי תוכן
            // של ספקי אחסון, וחתימת ספק היא הדרגה שמכריעה לבדה.
            'im360_captcha',
            'imunify360-webshield',
        ],
        'ddos-guard' => [
            'ddos-guard.net',
        ],
    ];

    /**
     * סקריפטים של captcha ו-SDK של הגנה, לפי הספק שהם מסגירים. לבדם אינם ראיה
     * ולכן נדרשת גם צורת עמוד ביניים.
     *
     * AWS יושבת כאן ולא בחתימות הספק במכוון: אמזון מנחה אפליקציות להטמיע את
     * `challenge.js` ואת `AwsWafIntegration` **בעמודי התוכן הרגילים**, כדי
     * להשיג אסימון לפני שליחת בקשות — כלומר נוכחותם היא עדות לשילוב, לא לאתגר.
     *
     * @var array<string, string>
     */
    private const SHAPE_GATED_SIGNATURES = [
        'challenges.cloudflare.com/turnstile' => 'captcha',
        'cf-turnstile' => 'captcha',
        'google.com/recaptcha/api.js' => 'captcha',
        'recaptcha/api2/anchor' => 'captcha',
        'hcaptcha.com/1/api.js' => 'captcha',
        'h-captcha' => 'captcha',
        'friendlycaptcha' => 'captcha',
        'awswaf.com' => 'aws',
        'aws-waf-token' => 'aws',
        'awswafintegration' => 'aws',
    ];

    /**
     * ניסוחים של דף אימות, בעברית ובאנגלית. גם אלה מותנים בצורת עמוד ביניים.
     *
     * @var list<string>
     */
    private const PHRASES = [
        'verify you are human',
        'verifying you are human',
        'verify that you are human',
        'are you a human',
        'i am not a robot',
        "i'm not a robot",
        'checking your browser',
        'checking if the site connection is secure',
        'just a moment',
        'enable javascript and cookies to continue',
        'please wait while we verify',
        'please wait while your request is being verified',
        'additional security check is required',
        'אמת שאתה אנושי',
        'אימות אנושי',
        'אני לא רובוט',
        'בודק את הדפדפן',
        'מאמת את הדפדפן',
        'אנא המתן בעת אימות',
        'נדרש אימות נוסף',
    ];

    /** שמות להצגה — מה ייכתב בהתראה ובמסך. */
    public const LABELS = [
        'cloudflare' => 'Cloudflare',
        'sucuri' => 'Sucuri',
        'imperva' => 'Imperva / Incapsula',
        'aws' => 'AWS WAF',
        'imunify360' => 'Imunify360',
        'ddos-guard' => 'DDoS-Guard',
        'captcha' => 'captcha',
        'generic' => 'מערכת הגנה',
    ];

    /**
     * האם התגובה הזאת היא דף אימות במקום האתר.
     *
     * @param  array<string, array<int, string>|string>  $headers  כפי ש-Http מחזיר
     * @return array{vendor: string, marker: string}|null
     */
    public function detect(array $headers, string $html): ?array
    {
        // cf-mitigated: challenge — Cloudflare אומרת זאת במפורש בכותרת, וזו
        // התשובה הזולה והוודאית ביותר. אינה תלויה בגוף כלל.
        if (str_contains(mb_strtolower($this->header($headers, 'cf-mitigated')), 'challenge')) {
            return ['vendor' => 'cloudflare', 'marker' => 'cf-mitigated: challenge'];
        }

        if (trim($html) === '') {
            return null;
        }

        $haystack = mb_strtolower($html);

        foreach (self::VENDOR_SIGNATURES as $vendor => $signatures) {
            foreach ($signatures as $signature) {
                if (str_contains($haystack, $signature)) {
                    return ['vendor' => $vendor, 'marker' => $signature];
                }
            }
        }

        // משלב זה והלאה נדרשת צורת עמוד ביניים. חישוב אחד, כדי לא לסרוק פעמיים.
        if (! $this->looksLikeInterstitial($html)) {
            return null;
        }

        foreach (self::SHAPE_GATED_SIGNATURES as $signature => $vendor) {
            if (str_contains($haystack, $signature)) {
                return ['vendor' => $vendor, 'marker' => $signature];
            }
        }

        foreach (self::PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return ['vendor' => 'generic', 'marker' => $phrase];
            }
        }

        return null;
    }

    /** שם הספק לתצוגה. */
    public function label(?string $vendor): string
    {
        return self::LABELS[$vendor] ?? self::LABELS['generic'];
    }

    /**
     * עמוד ביניים: כמעט בלי טקסט נראה וכמעט בלי קישורים.
     *
     * שני התנאים ביחד, ובכוונה. עמוד תוכן אמיתי שבו מופיעות המילים "אני לא
     * רובוט" — טופס יצירת קשר, עמוד הרשמה, פוסט על captcha — נושא תפריט, כותרות
     * ועשרות קישורים, ולכן לא ייחשב אתגר. דף אתגר הוא כמעט ריק: משפט, לוגו,
     * ולפעמים כפתור אחד.
     */
    private function looksLikeInterstitial(string $html): bool
    {
        $maxChars = (int) config('billing.monitoring.challenge.interstitial_chars', 600);
        $maxLinks = (int) config('billing.monitoring.challenge.interstitial_links', 5);

        $text = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text) > max(100, $maxChars)) {
            return false;
        }

        return substr_count(mb_strtolower($html), '<a ') <= max(0, $maxLinks);
    }

    /** ערך כותרת בודד, ללא תלות ברישיות או במספר הערכים. */
    private function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }

            return is_array($values) ? implode(' ', $values) : (string) $values;
        }

        return '';
    }
}
