<?php

namespace Tests\Feature;

use App\Models\User;
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

    /** ולכן גם נטענים גופני האיטליק עצמם, ולא רק מותר לזייף אותם. */
    public function test_the_real_italic_faces_are_requested(): void
    {
        $this->panel()->assertSee('family=Rubik:ital,wght@', false);
    }

    /** ו-Rubik שלא נטען נופל לגופן שיודע עברית, ולא למה שהמכונה תבחר. */
    public function test_a_font_that_fails_to_load_falls_back_to_hebrew_faces(): void
    {
        $this->panel()
            ->assertSee("'Rubik', 'Segoe UI', 'Noto Sans Hebrew'", false);
    }
}
