<?php

namespace App\Services\Audit;

/**
 * מה שהאתר טוען כדי למדוד, לפרסם ולעקוב — ומה שהוא טוען כדי לבקש רשות לכך.
 *
 * שתי השאלות יושבות יחד כי הן שאלה אחת: פיקסל של פייסבוק בלי באנר הסכמה אינו
 * "חסר באנר", הוא מעקב שנעשה בלי רשות. להפריד ביניהן זה לתת לדוח לומר "יש לך
 * מדידה מצוינת" ובנשימה הבאה, שלושה סעיפים מתחת, "חסר לך באנר" — במקום לומר
 * את הדבר האחד שנכון.
 */
class Trackers
{
    /** שם הכלי => חתימות שמזהות אותו במקור הדף. */
    private const MEASUREMENT = [
        'Google Analytics' => ['gtag/js', 'googletagmanager.com/gtag', 'google-analytics.com', "gtag('config'", 'ga(\'create\''],
        'Google Tag Manager' => ['googletagmanager.com/gtm', 'gtm.js', 'dataLayer.push'],
        'Meta Pixel' => ['connect.facebook.net', 'fbq(', 'facebook-jssdk'],
        'TikTok' => ['analytics.tiktok.com', 'ttq.load'],
        'LinkedIn' => ['snap.licdn.com', '_linkedin_partner_id'],
        'Microsoft Clarity' => ['clarity.ms'],
        'Hotjar' => ['static.hotjar.com', 'hj('],
        'Plausible' => ['plausible.io/js'],
        'Matomo' => ['matomo.js', 'piwik.js'],
    ];

    /** באנרי הסכמה הנפוצים, ולצידם ניסוח כללי לזיהוי פתרונות ביתיים. */
    private const CONSENT = [
        'cookiebot', 'onetrust', 'cookieyes', 'complianz', 'borlabs', 'iubenda',
        'termly', 'osano', 'didomi', 'cookie-consent', 'cookieconsent',
        'cookie-notice', 'gdpr-cookie', 'הסכמה לעוגיות', 'שימוש בעוגיות', 'אנו משתמשים בעוגיות',
    ];

    /**
     * כלי המדידה והפרסום שנמצאו בדף, לפי שמם.
     *
     * @return list<string>
     */
    public static function measuring(string $markup): array
    {
        $markup = mb_strtolower($markup);
        $found = [];

        foreach (self::MEASUREMENT as $name => $signs) {
            foreach ($signs as $sign) {
                if (str_contains($markup, mb_strtolower($sign))) {
                    $found[] = $name;

                    continue 2;
                }
            }
        }

        return $found;
    }

    /** האם הדף מציג בקשת הסכמה לעוגיות. */
    public static function asksConsent(string $markup): bool
    {
        $markup = mb_strtolower($markup);

        foreach (self::CONSENT as $sign) {
            if (str_contains($markup, $sign)) {
                return true;
            }
        }

        return false;
    }
}
