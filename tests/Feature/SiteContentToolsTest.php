<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Agent\SiteToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * סיווג הסיכון של כלי הכתיבה החדשים — שדות מותאמים, אלמנטור וווקומרס.
 *
 * הסיווג נעשה לפי תת-מחרוזת בשם הכלי, וזו שיטה שנכשלת בשקט: כלי כתיבה ששמו
 * מכיל במקרה מילה מרשימת הקריאה היה רץ בלי אישור על אתר של לקוח. לכן כל כלי
 * חדש נבדק כאן במפורש, ולא נסמך על כך שהכלל "עבד עד עכשיו".
 *
 * החלוקה הנכונה: קריאה — מדרגה 0, רצה חופשי; כתיבה — מדרגה 2, דורשת אישור;
 * מדרגה 3 שמורה להרס (מחיקות, קבצים, SQL) ומוגבלת לאתרי בדיקה, ולכן אסור
 * שכלי ניהול תוכן שגרתי ייפול לשם — הוא היה חדל לעבוד על אתרים אמיתיים.
 */
class SiteContentToolsTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::factory()->create(['mcp_enabled' => true]);
    }

    /** @return list<string> */
    private function readTools(): array
    {
        return [
            'wp_post_types_list',
            'wp_fields_schema',
            'wp_fields_get',
            'wp_elementor_texts_get',
            'wc_product_search',
            'wc_product_get',
            'wc_coupon_list',
        ];
    }

    /** @return list<string> */
    private function writeTools(): array
    {
        return [
            'wp_fields_update',
            'wp_elementor_text_update',
            'wc_product_update',
            'wc_product_create',
            'wc_coupon_create',
            'wc_coupon_expire',
        ];
    }

    /** כלי קריאה רצים חופשי — אחרת הסוכן לא יכול אפילו לבדוק מה המצב לפני שיציע. */
    public function test_the_new_read_tools_are_tier_zero(): void
    {
        $catalog = app(SiteToolCatalog::class);
        $site = $this->site();

        foreach ($this->readTools() as $tool) {
            $this->assertSame(0, $catalog->resolveTier($site, $tool), "{$tool} אמור להיות מדרגה 0");
            $this->assertTrue($catalog->allowedOn($site, $tool), "{$tool} אמור לרוץ על כל אתר");
        }
    }

    /**
     * כל כלי כתיבה דורש אישור — וזו הבדיקה שמגינה על העיקרון כולו.
     *
     * שינוי מחיר, טקסט בעמוד או שדה בנכס הוא שינוי אצל לקוח משלם. אם אחד מהם
     * ייפול למדרגה 0 בגלל מילה בשם, הוא ירוץ בלי שאיש יראה אותו קודם.
     */
    public function test_every_new_write_tool_requires_an_approval(): void
    {
        $catalog = app(SiteToolCatalog::class);
        $site = $this->site();

        foreach ($this->writeTools() as $tool) {
            $this->assertSame(2, $catalog->resolveTier($site, $tool), "{$tool} אמור לדרוש אישור (מדרגה 2)");
            $this->assertFalse($catalog->isReadOnly($site, $tool), "{$tool} אינו כלי קריאה");
        }
    }

    /**
     * וכלי כתיבה אינו מדרגה 3.
     *
     * מדרגה 3 מוגבלת לאתרי בדיקה, ולכן סיווג שגוי שם אינו "מחמיר יותר" אלא
     * הופך את הכלי לבלתי שמיש בדיוק במקום שבו הוא נחוץ — אצל הלקוח.
     */
    public function test_a_routine_content_change_is_not_confined_to_staging(): void
    {
        $catalog = app(SiteToolCatalog::class);
        $site = $this->site();

        foreach ($this->writeTools() as $tool) {
            $this->assertTrue($catalog->allowedOn($site, $tool), "{$tool} אמור להיות מותר על אתר חי");
        }
    }

    /**
     * כלי שמצהיר על עצמו כהרסני מוסלם למדרגה 3 גם אם שמו תמים.
     *
     * ההצהרה של האתר יכולה רק להחמיר, לעולם לא להקל — כך שתוסף עתידי שיוסיף
     * כלי מסוכן לא יוכל להיכנס בדלת של שם נעים.
     */
    public function test_a_destructive_declaration_still_escalates(): void
    {
        $site = Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_capabilities' => [
                'tools' => [['name' => 'wc_product_update', 'read_only' => false, 'destructive' => true]],
            ],
        ]);

        $this->assertSame(3, app(SiteToolCatalog::class)->resolveTier($site, 'wc_product_update'));
    }
}
