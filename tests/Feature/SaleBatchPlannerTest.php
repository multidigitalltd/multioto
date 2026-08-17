<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Agent\McpClient;
use App\Services\Agent\SaleBatchPlanner;
use App\Services\Ai\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * "תוריד 20% על כל החולצות" הופך לרשימת מחירים שאפשר לבדוק.
 *
 * אישור שכתוב בו "20% הנחה על החולצות" אינו דבר שמישהו יכול לאמת; אישור שכתוב
 * בו "חולצה שחורה — 99 ₪ → 79 ₪" כן. הבעלים מאשר מחירים, לא תארים — וההבדל
 * מתגלה ברגע שההנחה הייתה אמורה לרדת ממחיר שכבר הוזל בשבוע שעבר.
 */
class SaleBatchPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://shop.co.il/wp-json/md-agent/mcp',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $intent
     * @param  list<int>  $chosenIds
     * @param  list<array<string, mixed>>  $found
     */
    private function planner(?array $intent, array $chosenIds, array $found): SaleBatchPlanner
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn($intent, ['product_ids' => $chosenIds]);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturn(['content' => []]);
        $mcp->shouldReceive('textContent')->andReturn((string) json_encode($found));

        return new SaleBatchPlanner($ai, $mcp);
    }

    /** @return list<array<string, mixed>> */
    private function shirts(): array
    {
        return [
            ['id' => 1, 'name' => 'חולצה שחורה', 'regular_price' => '99', 'sale_price' => null],
            ['id' => 2, 'name' => 'חולצה לבנה', 'regular_price' => '120', 'sale_price' => null],
            ['id' => 3, 'name' => 'מגבת', 'regular_price' => '40', 'sale_price' => null],
        ];
    }

    /** כל מוצר מקבל שורה שמצטטת את המחיר הנוכחי ואת החדש. */
    public function test_the_proposal_quotes_every_price_before_and_after(): void
    {
        $plan = $this->planner(
            ['can_do' => true, 'search' => 'חולצה', 'percent' => 20, 'to' => '2026-08-31'],
            [1, 2],
            $this->shirts(),
        )->plan($this->site(), 'תוריד 20% על כל החולצות עד סוף החודש');

        $this->assertCount(2, $plan['calls']);
        $this->assertStringContainsString('חולצה שחורה — 99 ₪ → 79.20 ₪', $plan['summary']);
        $this->assertStringContainsString('חולצה לבנה — 120 ₪ → 96.00 ₪', $plan['summary']);
        $this->assertSame('79.20', $plan['calls'][0]['arguments']['sale_price']);
        $this->assertSame('2026-08-31', $plan['calls'][0]['arguments']['sale_to']);
    }

    /**
     * מוצר שהחיפוש מצא אבל ההוראה לא התכוונה אליו — לא נכנס.
     *
     * חיפוש "חולצה" מחזיר גם את המגבת. אילו מונח החיפוש היה מחליט, היינו מורידים
     * מחיר על מוצר שאיש לא התכוון אליו.
     */
    public function test_a_product_the_search_found_but_nobody_meant_is_left_out(): void
    {
        $plan = $this->planner(
            ['can_do' => true, 'search' => 'חולצה', 'percent' => 20],
            [1, 2],
            $this->shirts(),
        )->plan($this->site(), 'תוריד 20% על כל החולצות');

        $this->assertStringNotContainsString('מגבת', $plan['summary']);
    }

    /** ומזהה שהמודל המציא ולא הוצע לו — נדחה. */
    public function test_an_invented_product_id_is_refused(): void
    {
        $plan = $this->planner(
            ['can_do' => true, 'search' => 'חולצה', 'percent' => 20],
            [1, 999],
            $this->shirts(),
        )->plan($this->site(), 'תוריד 20% על כל החולצות');

        $this->assertCount(1, $plan['calls']);
        $this->assertSame(1, $plan['calls'][0]['arguments']['product_id']);
    }

    /** מבצע בלי תאריך סיום נאמר בקול — זה המבצע שמתגלה חודש אחר כך בהנהלת החשבונות. */
    public function test_a_sale_with_no_end_date_says_so(): void
    {
        $plan = $this->planner(
            ['can_do' => true, 'search' => 'חולצה', 'percent' => 20],
            [1],
            $this->shirts(),
        )->plan($this->site(), 'תוריד 20% על החולצות');

        $this->assertStringContainsString('ללא תאריך סיום', $plan['summary']);
        $this->assertArrayNotHasKey('sale_to', $plan['calls'][0]['arguments']);
    }

    /** הוראה שאינה מבצע ברור נענית בלא-כלום, ולא בניחוש. */
    public function test_an_unclear_instruction_is_declined(): void
    {
        $this->assertNull(
            $this->planner(['can_do' => false], [], $this->shirts())
                ->plan($this->site(), 'תוריד כמה שאפשר על החולצות')
        );
    }

    /** ואחוז מופרך נדחה — 95% הוא הרבה יותר סביר כטעות קריאה מאשר כהחלטה. */
    public function test_an_absurd_discount_is_refused(): void
    {
        $this->assertNull(
            $this->planner(['can_do' => true, 'search' => 'חולצה', 'percent' => 95], [1], $this->shirts())
                ->plan($this->site(), 'תוריד 95% על החולצות')
        );
    }

    /** מוצר בלי מחיר רגיל אינו נכנס — אין ממה להוריד אחוזים. */
    public function test_a_product_with_no_regular_price_is_skipped(): void
    {
        $plan = $this->planner(
            ['can_do' => true, 'search' => 'חולצה', 'percent' => 20],
            [1, 4],
            [
                ['id' => 1, 'name' => 'חולצה שחורה', 'regular_price' => '99'],
                ['id' => 4, 'name' => 'חולצה בהתאמה אישית', 'regular_price' => null],
            ],
        )->plan($this->site(), 'תוריד 20% על החולצות');

        $this->assertCount(1, $plan['calls']);
    }

    /** והחישוב נעשה באגורות — אחוז על float נסחף, ומחיר שסוטה באגורה הוא מחיר שלא אושר. */
    public function test_the_discount_is_computed_in_agorot(): void
    {
        $plan = $this->planner(
            ['can_do' => true, 'search' => 'חולצה', 'percent' => 33],
            [1],
            [['id' => 1, 'name' => 'חולצה', 'regular_price' => '19.99']],
        )->plan($this->site(), 'תוריד 33% על החולצות');

        // 1999 אגורות × 67% = 1339.33 → 1339
        $this->assertSame('13.39', $plan['calls'][0]['arguments']['sale_price']);
    }
}
