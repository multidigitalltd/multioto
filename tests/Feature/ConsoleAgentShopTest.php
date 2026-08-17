<?php

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\PendingAction;
use App\Models\Site;
use App\Services\Agent\ConsoleAgent;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * הסוכן עושה את עבודת החנות בעצמו.
 *
 * המטרה היא שהמנהל יכתוב משפט אחד ויקבל הצעה שאפשר לאשר — בלי לחפש מוצרים
 * ידנית, בלי לפתוח את ווקומרס, ובלי לחשב מחירים במחשבון. מה שהסוכן לא רשאי
 * לעשות הוא להמציא: הוראה שאינה מבצע מדויק חוזרת כשאלה, לא כמחיר.
 */
class ConsoleAgentShopTest extends TestCase
{
    use RefreshDatabase;

    private function store(array $attributes = []): Site
    {
        return Site::factory()->create([
            'domain' => 'shop.co.il',
            'site_type' => SiteType::Store,
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://shop.co.il/wp-json/md-agent/mcp',
            'mcp_secret' => 'secret',
            ...$attributes,
        ]);
    }

    private function fakeShop(string $json): void
    {
        Http::fake([
            'shop.co.il/*' => function (Request $request) use ($json) {
                $body = json_decode($request->body(), true);

                if (! isset($body['id'])) {
                    return Http::response('', 202);
                }

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $body['id'],
                    'result' => ['content' => [['type' => 'text', 'text' => $json]], 'isError' => false],
                ]);
            },
        ]);
    }

    /**
     * Drive one tool call through the agent's loop and return what the tool
     * answered, so the assertions are about the tool's behaviour rather than
     * about a model's wording.
     *
     * @param  array<string, mixed>  $input
     */
    private function callTool(string $tool, array $input, ?array $structured = null): string
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->andReturn($structured, ['product_ids' => [1, 2]]);
        $ai->shouldReceive('converse')->andReturnUsing(
            fn (string $system, string $prompt, array $tools, callable $handler): string => (string) $handler($tool, $input)['content'],
        );

        // The sale planner resolves its own model client, so the scripted one
        // has to be the container's too — otherwise the tool under test quietly
        // talks to a disabled client and every sale "cannot be planned".
        $this->app->instance(ClaudeClient::class, $ai);

        return (new ConsoleAgent($ai, app(ApprovalGate::class), app(McpClient::class)))
            ->run('תוריד 20% על החולצות')['summary'] ?? '';
    }

    private function shirtsJson(): string
    {
        return (string) json_encode([
            ['id' => 1, 'name' => 'חולצה שחורה', 'regular_price' => '99', 'sale_price' => null, 'stock_status' => 'instock'],
            ['id' => 2, 'name' => 'חולצה לבנה', 'regular_price' => '120', 'sale_price' => null, 'stock_status' => 'instock'],
        ]);
    }

    /** משפט אחד → הצעת מבצע שמפרטת כל מוצר עם המחיר לפני ואחרי. */
    public function test_one_sentence_becomes_a_priced_sale_proposal(): void
    {
        $site = $this->store();
        $this->fakeShop($this->shirtsJson());

        $this->callTool(
            'propose_sale',
            ['site_id' => $site->id, 'instruction' => 'תוריד 20% על כל החולצות עד 31/08'],
            ['can_do' => true, 'search' => 'חולצה', 'percent' => 20, 'to' => '2026-08-31'],
        );

        $action = PendingAction::where('type', 'site_action_batch')->sole();

        $this->assertCount(2, $action->payload['calls']);
        $this->assertStringContainsString('99 ₪ → 79.20 ₪', $action->summary);
    }

    /**
     * שאלה על מחיר נענית בקריאה, בלי להציע שום שינוי.
     *
     * סוכן שהדרך היחידה שלו להסתכל על החנות היא להציע בה שינוי — יציע שינויים
     * כדי להסתכל.
     */
    public function test_a_price_question_proposes_nothing(): void
    {
        $site = $this->store();
        $this->fakeShop($this->shirtsJson());

        $answer = $this->callTool('read_shop_products', ['site_id' => $site->id, 'search' => 'חולצה']);

        $this->assertStringContainsString('חולצה שחורה', $answer);
        $this->assertSame(0, PendingAction::count());
    }

    /** הוראה שאינה מבצע מדויק חוזרת כסירוב שמנחה לשאול — ולא כמחיר מנוחש. */
    public function test_an_unclear_instruction_comes_back_as_a_question_not_a_price(): void
    {
        $site = $this->store();
        $this->fakeShop($this->shirtsJson());

        $answer = $this->callTool(
            'propose_sale',
            ['site_id' => $site->id, 'instruction' => 'תוריד כמה שאפשר'],
            ['can_do' => false],
        );

        $this->assertStringContainsString('שאל את המנהל', $answer);
        $this->assertSame(0, PendingAction::count());
    }

    /** אתר שאינו חנות אינו מקבל כלי חנות — ולא הצעה שאי אפשר להריץ. */
    public function test_a_brochure_site_is_refused_before_anything_is_proposed(): void
    {
        $site = $this->store(['site_type' => SiteType::Brochure]);

        $answer = $this->callTool('read_shop_products', ['site_id' => $site->id, 'search' => 'חולצה']);

        $this->assertStringContainsString('אינו חנות', $answer);
        $this->assertSame(0, PendingAction::count());
    }

    /**
     * וכך גם אתר מנותק.
     *
     * הצעה נגד אתר שאינו מחובר היא אישור שהמנהל נותן לשינוי שלא יוכל לרוץ.
     */
    public function test_a_disconnected_site_is_refused_before_anything_is_proposed(): void
    {
        $site = $this->store(['mcp_enabled' => false]);

        $answer = $this->callTool(
            'propose_sale',
            ['site_id' => $site->id, 'instruction' => 'תוריד 20% על החולצות'],
            ['can_do' => true, 'search' => 'חולצה', 'percent' => 20],
        );

        $this->assertStringContainsString('אינו מחובר', $answer);
        $this->assertSame(0, PendingAction::count());
    }

    /** שינוי מוצר בודד מוצע עם המחיר הנוכחי בגוף ההצעה, כדי שיאושר מספר ולא תיאור. */
    public function test_a_single_product_change_is_proposed_with_its_current_price(): void
    {
        $site = $this->store();
        $this->fakeShop($this->shirtsJson());

        $this->callTool('propose_product_change', [
            'site_id' => $site->id,
            'product_id' => 1,
            'sale_price' => '79',
            'note' => 'המחיר היום 99 ₪',
        ]);

        $action = PendingAction::where('type', 'site_action')->sole();

        $this->assertSame('wc_product_update', $action->payload['tool']);
        $this->assertSame('79', $action->payload['arguments']['sale_price']);
        $this->assertStringContainsString('המחיר היום 99 ₪', $action->summary);
    }

    /** ושינוי בלי שום שדה נדחה, במקום להיווצר כהצעה ריקה. */
    public function test_a_change_with_no_fields_is_refused(): void
    {
        $site = $this->store();

        $answer = $this->callTool('propose_product_change', ['site_id' => $site->id, 'product_id' => 1]);

        $this->assertStringContainsString('לא צוין שום שדה', $answer);
        $this->assertSame(0, PendingAction::count());
    }
}
