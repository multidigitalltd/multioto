<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PanelFont;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * קריאוּת הטקסט בפאנל.
 *
 * התלונה הייתה "נטוי ודק", ושני החלקים שלה נענים בנפרד: משקל בסיס אמיתי במקום
 * 400, ואיסור על הדפדפן לזייף אלכסון מגופן שלא נטען — הדבר שגורם לממשק שלם
 * להיראות באיטליק בלי ששום קוד ביקש זאת.
 *
 * ורשימת הנפילה נבדקת כאן כי היא החלק שקל לאבד: פילמנט שם ב---font-family שם
 * גופן אחד בלבד, ובלי המשך מפורש Rubik שלא נטען נופל לגופן המערכת — שבעברית
 * משתנה בין מכונות.
 */
class PanelTypographyTest extends TestCase
{
    use RefreshDatabase;

    private function panel(): TestResponse
    {
        return $this->actingAs(User::factory()->create())->get('/admin');
    }

    public function test_the_panel_text_is_not_left_at_the_lightest_weight(): void
    {
        $this->panel()
            ->assertOk()
            ->assertSee('font-weight: 500', false);
    }

    /** ומי שהמשקל הזה כבד לו מדי יכול להחזיר, בלי פריסה. */
    public function test_the_weight_is_tunable(): void
    {
        config(['billing.branding.panel_font_weight' => 400]);

        $this->panel()->assertSee('font-weight: 400', false);
    }

    /**
     * הדפדפן אינו רשאי לזייף נטייה.
     *
     * זו התלונה עצמה: ממשק שנראה כולו באיטליק בלי ששום קוד ביקש זאת.
     */
    public function test_the_browser_may_not_fake_an_oblique(): void
    {
        $this->panel()->assertSee('font-synthesis-style: none', false);
    }

    /**
     * אבל איטליק מכוון נשאר נטוי.
     *
     * Rubik מגיע כברירת מחדל בלי גופן איטליק כלל, ולכן איסור גורף על זיוף
     * הנטייה היה מיישר את ההדגשה של לקוח בפנייה ומכבה את כפתור האיטליק בעורך —
     * כלומר מכבה תכונה אמיתית כדי לתקן מראה.
     */
    public function test_deliberate_italics_are_not_flattened(): void
    {
        $this->panel()->assertSee('font-synthesis-style: auto', false);
    }

    /** וגופן שלא נטען נופל לגופן שיודע עברית, ולא למה שהמכונה תבחר. */
    public function test_a_font_that_fails_to_load_falls_back_to_hebrew_faces(): void
    {
        $this->panel()
            ->assertSee("'Heebo', 'Segoe UI', 'Noto Sans Hebrew'", false);
    }

    /** והגופן שנבחר הוא זה שנטען. */
    public function test_the_stylesheet_asks_for_the_configured_family(): void
    {
        $this->panel()->assertSee('family=Heebo:wght@400;500;600;700', false);
    }

    /**
     * החלפת המשפחה משנה גם את מה שנטען וגם את מה שה-CSS מבקש.
     *
     * שני אלה נקבעים במקומות שונים בזמן — הכתובת בעליית הפאנל, וה-CSS בכל
     * בקשה — ולכן הם נבדקים דרך PanelFont, המקור היחיד של שניהם. משפחה
     * שמוגדרת במקום אחד ומקודדת קשיח בשני היא הדרך שבה החלפת פונט נראית כאילו
     * לא עשתה כלום.
     */
    public function test_changing_the_family_changes_both_the_stylesheet_and_the_css(): void
    {
        config(['billing.branding.panel_font' => 'Assistant']);

        $this->assertSame('Assistant', PanelFont::family());
        $this->assertStringContainsString('family=Assistant:wght@', PanelFont::url());
        $this->assertStringStartsWith("'Assistant', ", PanelFont::stack());
    }

    /**
     * וברירת המחדל מבקשת משקלים בלבד.
     *
     * ציר ital שנדרש ממשפחה שאין לה אחד גורם לגוגל לדחות את **כל** הבקשה —
     * כלומר לאבד את הגופן כולו, לא רק את האיטליק שלו.
     */
    public function test_the_default_request_asks_for_weights_only(): void
    {
        $this->assertStringNotContainsString('ital', PanelFont::url());
    }

    /** ומשפחה שכן מספקת איטליק אמיתי יכולה לבקש אותו במפורש. */
    public function test_a_family_with_real_italics_can_ask_for_them(): void
    {
        $url = 'https://fonts.googleapis.com/css2?family=Rubik:ital,wght@0,400;1,400&display=swap';

        config(['billing.branding.panel_font_url' => $url]);

        $this->assertSame($url, PanelFont::url());
    }

    /** ומשפחה ריקה בהגדרות אינה משאירה את הפאנל בלי גופן. */
    public function test_a_blank_family_falls_back_to_the_default(): void
    {
        config(['billing.branding.panel_font' => '  ']);

        $this->assertSame(PanelFont::DEFAULT_FAMILY, PanelFont::family());
    }
}
