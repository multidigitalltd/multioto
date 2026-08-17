<?php

namespace Tests\Feature;

use App\Services\Agent\RevertRecipe;
use Tests\TestCase;

/**
 * מתכון השחזור נגזר ממה שקרה, לא ממה שמישהו ניחש שיקרה.
 *
 * ליומן השינויים תמיד הייתה יכולת לשמור קריאה הפוכה, והפאנל מציע "שחזר" בכל
 * פעם שיש כזו. מה שלא היה הוא מקור לקריאה ההפוכה של עריכת תוכן או מחיר: מי
 * שהציע את השינוי היה צריך לנחש את הערך הקודם ולצרף אותו — וניחוש שנעשה לפני
 * הקריאה הוא בדיוק מה שיוצא שגוי כששתי עריכות נוחתות באותה דקה.
 */
class RevertRecipeTest extends TestCase
{
    private function recipe(): RevertRecipe
    {
        return app(RevertRecipe::class);
    }

    /** עריכת טקסט בעמוד מוחזרת לטקסט שהיה. */
    public function test_a_content_edit_is_reverted_to_what_was_there(): void
    {
        $recipe = $this->recipe()->for(
            'wp_content_update',
            ['id' => 12, 'title' => 'כותרת חדשה'],
            json_encode(['updated_id' => 12, 'previous' => ['title' => 'כותרת ישנה']]),
        );

        $this->assertSame('wp_content_update', $recipe['tool']);
        $this->assertSame(['id' => 12, 'title' => 'כותרת ישנה'], $recipe['arguments']);
    }

    /**
     * ורק השדות שהשתנו מוחזרים.
     *
     * שחזור שהיה כותב מחדש גם את הכותרת של עמוד שאיש לא נגע בה היה מבטל עבודה
     * של מישהו אחר תוך כדי שהוא טוען שהוא מבטל את שלנו.
     */
    public function test_only_the_fields_that_changed_are_restored(): void
    {
        $recipe = $this->recipe()->for(
            'wp_content_update',
            ['id' => 12, 'content' => 'תוכן חדש'],
            json_encode(['updated_id' => 12, 'previous' => ['content' => 'תוכן ישן']]),
        );

        $this->assertArrayNotHasKey('title', $recipe['arguments']);
        $this->assertSame('תוכן ישן', $recipe['arguments']['content']);
    }

    /** שדות מותאמים מוחזרים לערכיהם הקודמים. */
    public function test_custom_fields_are_restored(): void
    {
        $recipe = $this->recipe()->for(
            'wp_fields_update',
            ['id' => 40, 'fields' => ['price' => 2500000]],
            json_encode(['updated_id' => 40, 'updated' => ['price'], 'previous' => ['price' => 2300000]]),
        );

        $this->assertSame(['id' => 40, 'fields' => ['price' => 2300000]], $recipe['arguments']);
    }

    /** טקסט באלמנטור מוחזר לרכיב הנכון, לפי השדה שהכלי דיווח עליו. */
    public function test_an_elementor_text_is_restored_to_the_widget_it_came_from(): void
    {
        $recipe = $this->recipe()->for(
            'wp_elementor_text_update',
            ['id' => 7, 'widget_id' => 'a1b2c3', 'text' => 'חדש'],
            json_encode([
                'updated_id' => 7, 'widget_id' => 'a1b2c3', 'widget' => 'heading',
                'setting' => 'title', 'previous' => 'ישן',
            ]),
        );

        $this->assertSame('wp_elementor_text_update', $recipe['tool']);
        $this->assertSame('a1b2c3', $recipe['arguments']['widget_id']);
        $this->assertSame('title', $recipe['arguments']['setting']);
        $this->assertSame('ישן', $recipe['arguments']['text']);
    }

    /**
     * מחיר מוחזר רק בשדות שהשתנו בפועל.
     *
     * ה-snapshot של מוצר מתאר את כל שדותיו; שחזור כולם היה מבטל מלאי שמישהו
     * תיקן בינתיים, על סמך שינוי שלא נגע בו כלל.
     */
    public function test_a_price_change_restores_only_the_price(): void
    {
        $recipe = $this->recipe()->for(
            'wc_product_update',
            ['product_id' => 55, 'regular_price' => '89'],
            json_encode([
                'updated_id' => 55,
                'changed' => ['regular_price' => '89'],
                'previous' => ['regular_price' => '79', 'stock_quantity' => 4, 'status' => 'publish'],
            ]),
        );

        $this->assertSame(['product_id' => 55, 'regular_price' => '79'], $recipe['arguments']);
    }

    /**
     * סיום מבצע מוחזר כמחרוזת ריקה ולא כ-null.
     *
     * ריק הוא איך שהכלים מאייתים "נקה את השדה"; null היה נקרא כ"אל תיגע", כלומר
     * שחזור שמשאיר את השינוי על כנו ומדווח שהצליח.
     */
    public function test_clearing_a_field_reverts_to_an_explicit_empty(): void
    {
        $recipe = $this->recipe()->for(
            'wc_product_update',
            ['product_id' => 55, 'sale_price' => '69'],
            json_encode([
                'updated_id' => 55,
                'changed' => ['sale_price' => '69'],
                'previous' => ['sale_price' => null],
            ]),
        );

        $this->assertSame('', $recipe['arguments']['sale_price']);
    }

    /**
     * כלי שאינו מדווח מה החליף — אין ממה לגזור, ולא ננחש.
     *
     * ניחוש הפעולה ההפוכה לקריאה שלא תיארה את השפעתה הוא איך ש"שחזור" הופך
     * לשינוי שני ושונה.
     */
    public function test_a_tool_that_reports_nothing_gets_no_recipe(): void
    {
        $this->assertNull($this->recipe()->for('wp_cache_flush', [], '{"ok":true}'));
        $this->assertNull($this->recipe()->for('wp_plugin_update', ['plugin' => 'x'], 'עודכן בהצלחה'));
    }

    /** ופעולה שלא שינתה דבר אינה מייצרת מתכון ריק. */
    public function test_a_change_with_no_previous_values_gets_no_recipe(): void
    {
        $this->assertNull($this->recipe()->for(
            'wc_product_update',
            ['product_id' => 55],
            json_encode(['updated_id' => 55, 'changed' => [], 'previous' => ['regular_price' => '79']]),
        ));
    }
}
