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
 * "תשנה את הכותרת בדף הבית" — הסוכן קורא, מבין איך העמוד בנוי, ומציע נכון.
 *
 * החצי הזה של ההבטחה הוא שהופך את כלי האלמנטור מיכולת שקיימת בקוד ליכולת
 * שמישהו יכול להשתמש בה. והמבחן האמיתי הוא לא שהסוכן מצליח — אלא שהוא מסרב
 * לערוך עמוד אלמנטור בדרך שנראית מוצלחת ואינה משנה דבר.
 */
class ConsoleAgentContentTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::factory()->create([
            'domain' => 'site.co.il',
            'site_type' => SiteType::Brochure,
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://site.co.il/wp-json/md-agent/mcp',
            'mcp_secret' => 'secret',
        ]);
    }

    /**
     * Answer each MCP tool call from a map of tool name => JSON text, so a run
     * that reads a page and then its Elementor texts gets both.
     *
     * Fixtures are encoded the way the plugin really encodes them
     * (JSON_UNESCAPED_UNICODE) — escaped Hebrew would make this test pass or
     * fail for a reason that has nothing to do with the code under test.
     *
     * @param  array<string, string>  $byTool
     */
    private function fakeSite(array $byTool): void
    {
        Http::fake([
            'site.co.il/*' => function (Request $request) use ($byTool) {
                $body = json_decode($request->body(), true);

                if (! isset($body['id'])) {
                    return Http::response('', 202);
                }

                $tool = (string) ($body['params']['name'] ?? '');

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $body['id'],
                    'result' => [
                        'content' => [['type' => 'text', 'text' => $byTool[$tool] ?? '{}']],
                        'isError' => false,
                    ],
                ]);
            },
        ]);
    }

    /** @param  array<string, mixed>  $input */
    private function callTool(string $tool, array $input): string
    {
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('converse')->andReturnUsing(
            fn (string $system, string $prompt, array $tools, callable $handler): string => (string) $handler($tool, $input)['content'],
        );

        return (new ConsoleAgent($ai, app(ApprovalGate::class), app(McpClient::class)))
            ->run('תשנה את הכותרת בדף הבית')['summary'] ?? '';
    }

    private function elementorPage(): array
    {
        return [
            'wp_content_get' => (string) json_encode([
                'id' => 12, 'title' => 'דף הבית', 'content' => '', 'status' => 'publish',
                'type' => 'page', 'built_with_elementor' => true,
            ], JSON_UNESCAPED_UNICODE),
            'wp_elementor_texts_get' => (string) json_encode([
                'id' => 12,
                'texts' => [['widget_id' => 'a1b2c3', 'widget' => 'heading', 'setting' => 'title', 'text' => 'ברוכים הבאים']],
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * קריאת עמוד אלמנטור מחזירה גם את הטקסטים שבו, עם מזהי הרכיבים.
     *
     * בלי זה הסוכן היה קורא content ריק ומסיק שאין בעמוד טקסט.
     */
    public function test_reading_an_elementor_page_returns_its_real_texts(): void
    {
        $site = $this->site();
        $this->fakeSite($this->elementorPage());

        $answer = $this->callTool('read_site_content', ['site_id' => $site->id, 'id' => 12]);

        $this->assertStringContainsString('ברוכים הבאים', $answer);
        $this->assertStringContainsString('a1b2c3', $answer);
    }

    /**
     * ועריכה רגילה של עמוד אלמנטור נדחית — עם הפניה לכלי שכן עובד.
     *
     * זו הבדיקה החשובה כאן: כתיבה ל-content הייתה מצליחה, משנה עותק שאיש לא
     * רואה, ומאפשרת לסוכן לדווח על שינוי שמעולם לא הופיע.
     */
    public function test_a_plain_edit_of_an_elementor_page_is_refused_and_redirected(): void
    {
        $site = $this->site();
        $this->fakeSite($this->elementorPage());

        $answer = $this->callTool('propose_content_edit', [
            'site_id' => $site->id, 'id' => 12, 'title' => 'כותרת חדשה',
        ]);

        $this->assertStringContainsString('אלמנטור', $answer);
        $this->assertStringContainsString('propose_elementor_text', $answer);
        $this->assertSame(0, PendingAction::count());
    }

    /** הצעת טקסט באלמנטור מציגה לפני ואחרי. */
    public function test_an_elementor_text_proposal_shows_before_and_after(): void
    {
        $site = $this->site();
        $this->fakeSite($this->elementorPage());

        $this->callTool('propose_elementor_text', [
            'site_id' => $site->id, 'id' => 12, 'widget_id' => 'a1b2c3',
            'text' => 'ברוכים הבאים לחנות שלנו', 'current_text' => 'ברוכים הבאים',
        ]);

        $action = PendingAction::sole();

        $this->assertSame('wp_elementor_text_update', $action->payload['tool']);
        $this->assertSame('a1b2c3', $action->payload['arguments']['widget_id']);
        $this->assertStringContainsString('לפני: ברוכים הבאים', $action->summary);
        $this->assertStringContainsString('אחרי: ברוכים הבאים לחנות שלנו', $action->summary);
    }

    /** עמוד רגיל נערך בדרך הרגילה. */
    public function test_a_regular_page_is_edited_normally(): void
    {
        $site = $this->site();
        $this->fakeSite(['wp_content_get' => (string) json_encode([
            'id' => 5, 'title' => 'צור קשר', 'content' => '<p>ישן</p>',
            'status' => 'publish', 'type' => 'page', 'built_with_elementor' => false,
        ], JSON_UNESCAPED_UNICODE)]);

        $this->callTool('propose_content_edit', ['site_id' => $site->id, 'id' => 5, 'title' => 'צרו קשר']);

        $action = PendingAction::sole();

        $this->assertSame('wp_content_update', $action->payload['tool']);
        $this->assertSame('צרו קשר', $action->payload['arguments']['title']);
        $this->assertStringContainsString('צור קשר', $action->summary);
    }

    /** שדות מותאמים מוצעים עם המפתחות שנקראו. */
    public function test_custom_fields_are_proposed_with_their_keys(): void
    {
        $site = $this->site();
        $this->fakeSite([]);

        $this->callTool('propose_field_change', [
            'site_id' => $site->id, 'id' => 40, 'fields' => ['price' => 2500000],
        ]);

        $action = PendingAction::sole();

        $this->assertSame('wp_fields_update', $action->payload['tool']);
        $this->assertSame(['price' => 2500000], $action->payload['arguments']['fields']);
    }

    /** והצעה בלי מזהה רכיב נדחית, במקום להישלח על רכיב מנוחש. */
    public function test_an_elementor_proposal_without_a_widget_id_is_refused(): void
    {
        $site = $this->site();
        $this->fakeSite($this->elementorPage());

        $answer = $this->callTool('propose_elementor_text', [
            'site_id' => $site->id, 'id' => 12, 'text' => 'חדש',
        ]);

        $this->assertStringContainsString('read_site_content', $answer);
        $this->assertSame(0, PendingAction::count());
    }
}
