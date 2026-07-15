<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Models\PendingAction;
use App\Models\Site;
use App\Services\Agent\SiteAgent;
use App\Services\Ai\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.ai.enabled' => true,
            'billing.ai.provider' => 'anthropic',
            'billing.ai.api_key' => 'test-key',
            'billing.ai.base_url' => 'https://api.anthropic.test',
            'billing.ai.model' => 'claude-opus-4-8',
            'billing.ai.effort' => 'low',
            'agent.actions_enabled' => true,
        ]);
    }

    private function connectedSite(): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://site.test/wp-json/md-agent/mcp',
            'mcp_secret' => 'site-secret',
            'mcp_capabilities' => ['tools' => [
                ['name' => 'wp_plugin_list', 'description' => 'List plugins', 'read_only' => true, 'destructive' => false],
                ['name' => 'wp_plugin_update', 'description' => 'Update a plugin', 'read_only' => false, 'destructive' => false],
            ]],
        ]);
    }

    /** Queue of Anthropic responses to hand back in order. */
    private function fakeClaude(array $responses): void
    {
        $i = 0;
        Http::fake([
            'api.anthropic.test/*' => function () use (&$i, $responses) {
                return Http::response($responses[$i++] ?? end($responses));
            },
            'site.test/*' => function (Request $request) {
                $body = json_decode($request->body(), true);
                if (! isset($body['id'])) {
                    return Http::response('', 202);
                }

                return Http::response([
                    'jsonrpc' => '2.0', 'id' => $body['id'],
                    'result' => ['content' => [['type' => 'text', 'text' => 'akismet 5.3, elementor 3.20']], 'isError' => false],
                ]);
            },
        ]);
    }

    public function test_the_agent_reads_the_site_then_proposes_a_gated_fix(): void
    {
        $site = $this->connectedSite();

        $this->fakeClaude([
            // Turn 1: the model calls the read tool.
            ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'site_read', 'input' => ['tool' => 'wp_plugin_list']],
            ]],
            // Turn 2: with the reading in hand, it proposes an update.
            ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'propose_action', 'input' => [
                    'tool' => 'wp_plugin_update',
                    'arguments' => ['plugin' => 'elementor'],
                    'summary' => 'לעדכן את Elementor לגרסה האחרונה',
                    'revert_tool' => 'wp_plugin_update',
                    'revert_arguments' => ['plugin' => 'elementor', 'version' => '3.20'],
                ]],
            ]],
            // Turn 3: it writes its summary and stops.
            ['stop_reason' => 'end_turn', 'content' => [
                ['type' => 'text', 'text' => 'מצאתי ש-Elementor אינו מעודכן. הצעתי עדכון לאישור.'],
            ]],
        ]);

        $summary = app(SiteAgent::class)->investigate($site, 'בדוק תוספים לא מעודכנים.');

        $this->assertStringContainsString('Elementor', $summary);

        // The read ran live against the site; the fix is a pending proposal — NOT executed.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'site.test')
            && ($r->data()['method'] ?? '') === 'tools/call');

        $action = PendingAction::where('type', 'site_action')->sole();
        $this->assertSame(ActionStatus::Pending, $action->status);
        $this->assertSame('wp_plugin_update', data_get($action->payload, 'tool'));
        $this->assertSame('wp_plugin_update', data_get($action->payload, 'revert.tool'));
        $this->assertSame('ai', $action->proposed_by);
    }

    public function test_the_agent_cannot_propose_a_destructive_tool_on_production(): void
    {
        $site = $this->connectedSite(); // environment defaults to production

        $this->fakeClaude([
            ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'propose_action', 'input' => [
                    'tool' => 'wp_php_exec', 'summary' => 'הרץ קוד', 'arguments' => ['code' => 'x'],
                ]],
            ]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'לא ניתן.']]],
        ]);

        app(SiteAgent::class)->investigate($site, 'תקן');

        // The destructive proposal was refused by the toolset — nothing queued.
        $this->assertSame(0, PendingAction::where('type', 'site_action')->count());
    }

    public function test_a_mutating_tool_with_a_read_ish_name_is_refused_on_the_read_path(): void
    {
        // "search_replace" contains the tier-0 substring "search" but is NOT
        // annotated read-only — the classic mass DB-rewrite. It must NOT execute
        // via site_read (the path that skips approval + the kill-switch).
        $site = Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://site.test/wp-json/md-agent/mcp',
            'mcp_secret' => 's',
            'mcp_capabilities' => ['tools' => [
                ['name' => 'search_replace', 'description' => 'Mass DB rewrite', 'read_only' => false, 'destructive' => true],
            ]],
        ]);

        $this->fakeClaude([
            ['stop_reason' => 'tool_use', 'content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'site_read', 'input' => ['tool' => 'search_replace', 'arguments' => ['from' => 'a', 'to' => 'b']]],
            ]],
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'נחסם.']]],
        ]);

        app(SiteAgent::class)->investigate($site, 'החלף מחרוזת');

        // The read was refused — the destructive tool was never called on the site.
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'site.test')
            && ($r->data()['method'] ?? '') === 'tools/call');
    }

    public function test_site_operations_use_the_separate_site_rules(): void
    {
        config([
            'billing.ai.rules' => 'TICKET_ONLY_RULE',
            'billing.ai.site_rules' => 'SITE_ONLY_RULE',
        ]);
        $site = $this->connectedSite();
        $this->fakeClaude([
            ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'סיימתי.']]],
        ]);

        app(SiteAgent::class)->investigate($site, 'בדוק');

        // The site agent's system prompt carries the site rules — never the
        // customer-reply rules.
        Http::assertSent(function (Request $r): bool {
            if (! str_contains($r->url(), 'api.anthropic.test')) {
                return false;
            }
            $system = $r->data()['system'] ?? '';
            $system = is_array($system) ? json_encode($system, JSON_UNESCAPED_UNICODE) : (string) $system;

            return str_contains($system, 'SITE_ONLY_RULE') && ! str_contains($system, 'TICKET_ONLY_RULE');
        });
    }

    public function test_investigate_returns_null_when_the_site_is_not_connected(): void
    {
        $site = Site::factory()->create(['mcp_enabled' => false]);
        Http::fake();

        $this->assertNull(app(SiteAgent::class)->investigate($site, 'x'));
        Http::assertNothingSent();
    }

    public function test_converse_returns_null_on_a_refusal(): void
    {
        Http::fake(['api.anthropic.test/*' => Http::response(['stop_reason' => 'refusal', 'content' => []])]);

        $result = app(ClaudeClient::class)->converse('sys', 'hi', [], fn () => ['content' => '']);
        $this->assertNull($result);
    }

    public function test_converse_is_disabled_for_non_anthropic_providers(): void
    {
        config(['billing.ai.provider' => 'openai']);
        Http::fake();

        $this->assertNull(app(ClaudeClient::class)->converse('sys', 'hi', [], fn () => ['content' => '']));
        Http::assertNothingSent();
    }
}
