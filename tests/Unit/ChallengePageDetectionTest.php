<?php

namespace Tests\Unit;

use App\Services\Monitoring\ChallengePage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * הזיהוי עצמו, ובעיקר מה שהוא **לא** מזהה.
 *
 * אזהרה שגויה כאן אינה אי-נוחות: עמוד "צור קשר" עם reCAPTCHA שמייצר התראה
 * "האתר מציג דף אימות" מלמד את הצוות להתעלם מהסוג הזה של הודעות, ואז גם האמיתית
 * תיקרא ותימחק. לכן חתימת ספק מכריעה לבדה, וכל השאר דורש גם צורה של עמוד ביניים.
 */
class ChallengePageDetectionTest extends TestCase
{
    private ChallengePage $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new ChallengePage;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function challengePages(): array
    {
        return [
            'Cloudflare managed challenge' => [
                '<html><head><title>Just a moment...</title></head><body>'
                .'<script src="/cdn-cgi/challenge-platform/h/b/orchestrate/chl_page/v1"></script></body></html>',
                'cloudflare',
            ],
            'Cloudflare legacy browser check' => [
                '<html><body><div id="cf-browser-verification">Checking your browser before accessing</div></body></html>',
                'cloudflare',
            ],
            'Sucuri firewall block' => [
                '<html><body><h1>Sucuri WebSite Firewall - Access Denied</h1>'
                .'<script src="sucuri_cloudproxy_js"></script></body></html>',
                'sucuri',
            ],
            'Imperva / Incapsula' => [
                '<html><body>Request unsuccessful. Incapsula incident ID: 1234-5678</body></html>',
                'imperva',
            ],
            'AWS WAF' => [
                '<html><head><script src="https://de.awswaf.com/challenge.js"></script></head><body></body></html>',
                'aws',
            ],
            // ספק שאין לו חתימה מוכרת — נתפס לפי הניסוח, וזה כל הטעם בדרגה הזאת:
            // רשימת ספקים לעולם לא תהיה שלמה.
            'unknown vendor, by wording' => [
                '<html><head><title>Security check</title></head><body>'
                .'<p>Please verify you are human to continue.</p></body></html>',
                'generic',
            ],
            'Hebrew wording' => [
                '<html><body><h1>אימות אנושי</h1><p>אנא אשרו שאינכם רובוט.</p></body></html>',
                'generic',
            ],
            // captcha על עמוד שאין בו שום דבר אחר — זה אתגר, גם בלי חתימת ספק.
            'bare captcha interstitial' => [
                '<html><head><script src="https://www.google.com/recaptcha/api.js"></script></head>'
                .'<body><div class="g-recaptcha"></div></body></html>',
                'captcha',
            ],
        ];
    }

    #[DataProvider('challengePages')]
    public function test_it_recognises_a_verification_page(string $html, string $vendor): void
    {
        $detected = $this->detector->detect([], $html);

        $this->assertNotNull($detected, 'דף האתגר לא זוהה');
        $this->assertSame($vendor, $detected['vendor']);
    }

    /** @return array<string, array{0: string}> */
    public static function realPages(): array
    {
        $navigation = str_repeat('<a href="/p">מוצר</a>', 30);
        $prose = str_repeat('הטקסט האמיתי של העמוד, עם מוצרים ומבצעים ותיאורים. ', 30);

        return [
            // המקרה המסוכן ביותר: עמוד תוכן אמיתי שיש בו captcha לגיטימי.
            'contact form with reCAPTCHA' => [
                '<html><head><script src="https://www.google.com/recaptcha/api.js"></script></head><body>'
                .$navigation.'<h1>צור קשר</h1>'.$prose.'<div class="g-recaptcha"></div>'
                .'<p>אני לא רובוט</p></body></html>',
            ],
            // פוסט שמסביר לקוראים מה זה דף אימות — מלא במילות המפתח.
            'article about human verification' => [
                '<html><body>'.$navigation.'<h1>מה זה "verify you are human"?</h1>'
                .$prose.$prose.'</body></html>',
            ],
            'ordinary homepage' => [
                '<html><head><title>החנות שלנו</title></head><body>'.$navigation.$prose.'</body></html>',
            ],
            'empty response' => [''],
        ];
    }

    #[DataProvider('realPages')]
    public function test_it_leaves_a_real_page_alone(string $html): void
    {
        $this->assertNull($this->detector->detect([], $html));
    }

    /**
     * עמוד ריק באמת (WSOD) אינו דף אתגר.
     *
     * הוא עובר את מבחן הצורה — אין בו טקסט ואין בו קישורים — ולכן חשוב שהוא
     * ייפול על היעדר סימן: תקלת WSOD היא בדיקת מילת המפתח, לא זו.
     */
    public function test_a_blank_page_is_not_a_challenge(): void
    {
        $this->assertNull($this->detector->detect([], '<html><body></body></html>'));
    }

    /** שם הספק מוצג; ספק לא מוכר מקבל ניסוח כללי ולא מזהה פנימי. */
    public function test_vendor_labels_are_human_readable(): void
    {
        $this->assertSame('Cloudflare', $this->detector->label('cloudflare'));
        $this->assertSame('מערכת הגנה', $this->detector->label('generic'));
        $this->assertSame('מערכת הגנה', $this->detector->label(null));
        $this->assertSame('מערכת הגנה', $this->detector->label('something-new'));
    }
}
