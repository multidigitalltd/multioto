<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Jobs\InvestigateSiteJob;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\SystemLog;
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

    public function test_supports_agent_whenever_the_ai_layer_is_configured(): void
    {
        config(['billing.ai.enabled' => true, 'billing.ai.api_key' => 'k']);
        foreach (['anthropic', 'openai', 'google'] as $provider) {
            config(['billing.ai.provider' => $provider]);
            $this->assertTrue(app(ClaudeClient::class)->supportsAgent(), "agent should run on {$provider}");
        }

        config(['billing.ai.api_key' => '']);
        $this->assertFalse(app(ClaudeClient::class)->supportsAgent());
    }

    public function test_the_agent_reads_and_proposes_over_google_gemini(): void
    {
        // Prove the tool-use loop works on a non-Anthropic provider (Gemini),
        // which is what the team wants for cheaper tokens.
        config(['billing.ai.provider' => 'google', 'billing.ai.base_url' => 'https://gemini.test']);
        $site = $this->connectedSite();

        $i = 0;
        $responses = [
            // Turn 1: Gemini asks to read the plugin list (functionCall part).
            ['candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'site_read', 'args' => ['tool' => 'wp_plugin_list']]],
            ]]]]],
            // Turn 2: it proposes an update.
            ['candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'propose_action', 'args' => [
                    'tool' => 'wp_plugin_update', 'arguments' => ['plugin' => 'elementor'], 'summary' => 'עדכון Elementor',
                ]]],
            ]]]]],
            // Turn 3: final text.
            ['candidates' => [['content' => ['parts' => [['text' => 'הצעתי עדכון ל-Elementor.']]]]]],
        ];
        Http::fake([
            'gemini.test/*' => function () use (&$i, $responses) {
                return Http::response($responses[$i++] ?? end($responses));
            },
            'site.test/*' => fn (Request $r) => isset(json_decode($r->body(), true)['id'])
                ? Http::response(['jsonrpc' => '2.0', 'id' => json_decode($r->body(), true)['id'], 'result' => ['content' => [['type' => 'text', 'text' => 'elementor 3.20']], 'isError' => false]])
                : Http::response('', 202),
        ]);

        $summary = app(SiteAgent::class)->investigate($site, 'בדוק תוספים.');

        $this->assertStringContainsString('Elementor', (string) $summary);
        $action = PendingAction::where('type', 'site_action')->sole();
        $this->assertSame('wp_plugin_update', data_get($action->payload, 'tool'));
        $this->assertSame(ActionStatus::Pending, $action->status);
    }

    public function test_investigate_job_logs_a_clear_reason_when_the_ai_is_off(): void
    {
        config(['billing.ai.enabled' => false]);
        $site = $this->connectedSite();
        Http::fake();

        InvestigateSiteJob::dispatchSync($site->id, 'בדוק');

        $log = SystemLog::where('level', 'warning')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('לא הניב תוצאה', $log->message);
        $this->assertStringContainsString('כבוי', $log->message);
    }

    public function test_converse_returns_null_on_a_refusal(): void
    {
        Http::fake(['api.anthropic.test/*' => Http::response(['stop_reason' => 'refusal', 'content' => []])]);

        $result = app(ClaudeClient::class)->converse('sys', 'hi', [], fn () => ['content' => '']);
        $this->assertNull($result);
    }

    public function test_converse_runs_a_tool_loop_on_openai(): void
    {
        config(['billing.ai.provider' => 'openai', 'billing.ai.base_url' => 'https://openai.test/v1']);

        $i = 0;
        $responses = [
            // Turn 1: the model calls a tool (function calling).
            ['choices' => [['message' => ['content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'ping', 'arguments' => '{"x":1}']],
            ]]]]],
            // Turn 2: final text.
            ['choices' => [['message' => ['content' => 'עבד']]]],
        ];
        Http::fake(['openai.test/*' => function () use (&$i, $responses) {
            return Http::response($responses[$i++] ?? end($responses));
        }]);

        $seen = [];
        $result = app(ClaudeClient::class)->converse('sys', 'hi',
            [['name' => 'ping', 'description' => 'p', 'input_schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]]]],
            function (string $name, array $input) use (&$seen): array {
                $seen[] = [$name, $input];

                return ['content' => 'pong'];
            });

        $this->assertSame('עבד', $result);
        $this->assertSame([['ping', ['x' => 1]]], $seen); // the handler ran with decoded args
    }
}
