<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_baseline_security_headers_are_present(): void
    {
        $response = $this->get('/support');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get('http://localhost/support')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/support')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    private function policy(): string
    {
        return (string) $this->get('/support')->headers->get('Content-Security-Policy');
    }

    /** הכותרת נשלחת, ומגבילה כברירת מחדל למקור שלנו. */
    public function test_a_content_security_policy_is_sent_and_defaults_to_our_own_origin(): void
    {
        $this->assertStringContainsString("default-src 'self'", $this->policy());
    }

    /**
     * עמוד הכרטיס של קארדקום מוטמע ב-iframe, ולכן מותר במפורש.
     *
     * זו הבדיקה היקרה ביותר כאן: מדיניות בלי ההיתר הזה מורידה כל הזנת כרטיס
     * במערכת — הרשמה, החלפת אמצעי תשלום וחיוב ידני — ודווקא בשקט, כי הדפדפן
     * פשוט לא יצייר את המסגרת.
     */
    public function test_the_card_page_may_still_be_embedded(): void
    {
        $this->assertStringContainsString(
            "frame-src 'self' https://secure.cardcom.solutions",
            $this->policy(),
        );
    }

    /** מה שעוצר קוד זר מלהגיע לשום מקום, גם אם הצליח לרוץ. */
    public function test_foreign_code_has_nowhere_to_load_from_or_send_to(): void
    {
        $policy = $this->policy();

        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
    }

    /**
     * מתג חירום: אפשר להעביר את המדיניות למצב דיווח בלי פריסה.
     *
     * הכותרת הזאת יושבת לפני פאנל חיובים חי עם iframe של תשלום. אם היא חוסמת
     * משהו שלא נצפה, "לחזור אחורה ולפרוס מחדש" הוא זמן תגובה שגוי.
     */
    public function test_the_policy_can_be_switched_to_report_only_without_a_deploy(): void
    {
        config(['security.csp.report_only' => true]);

        $response = $this->get('/support');

        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $response->headers->get('Content-Security-Policy-Report-Only'),
        );
        $response->assertHeaderMissing('Content-Security-Policy');
    }
}
