<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Jobs\InvestigateSiteJob;
use App\Models\PendingAction;
use App\Models\Site;
use App\Services\Agent\SiteAgent;
use App\Services\Agent\SiteMemoryStore;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The closed fix loop: an AI-proposed site fix is approved → executed → the
 * agent automatically re-investigates the ORIGINAL problem (read-only) and
 * either confirms it is solved or proposes the next step — command → result →
 * approval → … until the fix is confirmed, capped at verify_max_rounds.
 */
class FixLoopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'agent.actions_enabled' => true,
            'billing.ai.enabled' => true,
            'billing.ai.provider' => 'anthropic',
            'billing.ai.api_key' => 'test-key',
            'billing.ai.base_url' => 'https://api.anthropic.test',
            'billing.ai.model' => 'claude-opus-4-8',
        ]);
    }

    private function connectedSite(): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://site.test/wp-json/md-agent/mcp',
            'mcp_secret' => 'site-secret',
            'mcp_capabilities' => ['tools' => [
                ['name' => 'wp_health', 'description' => 'Site health', 'read_only' => true, 'destructive' => false],
                ['name' => 'wp_cache_flush', 'description' => 'Flush caches', 'read_only' => false, 'destructive' => false],
            ]],
        ]);
    }

    private function fakeMcpToolCall(): void
    {
        Http::fake([
            'site.test/*' => function (Request $request) {
                $body = json_decode($request->body(), true);

                if (! isset($body['id'])) {
                    return Http::response('', 202);
                }

                return Http::response([
                    'jsonrpc' => '2.0', 'id' => $body['id'],
                    'result' => ['content' => [['type' => 'text', 'text' => 'Cache flushed.']], 'isError' => false],
                ]);
            },
        ]);
    }

    private function aiProposal(Site $site, array $payloadExtra = []): PendingAction
    {
        return PendingAction::create([
            'type' => 'site_action',
            'status' => ActionStatus::Pending,
            'customer_id' => $site->customer_id,
            'summary' => 'ניקוי קאש',
            'payload' => ['site_id' => $site->id, 'tool' => 'wp_cache_flush', 'arguments' => [], ...$payloadExtra],
            'proposed_by' => 'ai',
        ]);
    }

    public function test_an_ai_proposal_carries_the_goal_and_round_for_the_loop(): void
    {
        $site = $this->connectedSite();

        Http::fake([
            'api.anthropic.test/*' => Http::sequence()
                ->push(['stop_reason' => 'tool_use', 'content' => [
                    ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'propose_action', 'input' => [
                        'tool' => 'wp_cache_flush', 'summary' => 'לנקות את הקאש',
                    ]],
                ]])
                ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'הצעתי ניקוי קאש.']]]),
            'site.test/*' => Http::response('', 202),
        ]);

        app(SiteAgent::class)->investigate($site, 'האתר איטי מאוד', round: 2);

        $action = PendingAction::where('type', 'site_action')->sole();
        $this->assertSame('האתר איטי מאוד', data_get($action->payload, 'goal'));
        $this->assertSame(2, data_get($action->payload, 'round'));
    }

    public function test_an_executed_ai_fix_dispatches_a_verification_of_the_original_goal(): void
    {
        Queue::fake([InvestigateSiteJob::class]);
        $site = $this->connectedSite();
        $this->fakeMcpToolCall();
        $action = $this->aiProposal($site, ['goal' => 'האתר איטי מאוד', 'round' => 1]);

        $reply = app(ApprovalGate::class)->approve($action);

        $this->assertStringContainsString('בוצעה', $reply);
        Queue::assertPushed(InvestigateSiteJob::class, function (InvestigateSiteJob $job) use ($site): bool {
            return $job->siteId === $site->id
                && $job->round === 2
                && str_contains($job->goal, 'האתר איטי מאוד')
                && str_contains($job->goal, 'wp_cache_flush');
        });
    }

    public function test_the_loop_stops_at_the_round_cap(): void
    {
        Queue::fake([InvestigateSiteJob::class]);
        config(['agent.verify_max_rounds' => 3]);
        $site = $this->connectedSite();
        $this->fakeMcpToolCall();
        $action = $this->aiProposal($site, ['goal' => 'האתר איטי', 'round' => 3]);

        app(ApprovalGate::class)->approve($action);

        Queue::assertNotPushed(InvestigateSiteJob::class);
    }

    public function test_a_manual_team_action_or_a_goalless_one_does_not_loop(): void
    {
        Queue::fake([InvestigateSiteJob::class]);
        $site = $this->connectedSite();
        $this->fakeMcpToolCall();

        // Team member picked a tool by hand — one call, no investigation behind it.
        $team = $this->aiProposal($site, ['goal' => 'בעיה', 'round' => 1]);
        $team->update(['proposed_by' => 'team']);
        app(ApprovalGate::class)->approve($team);

        // An AI action from before the loop existed (no goal in the payload).
        app(ApprovalGate::class)->approve($this->aiProposal($site));

        Queue::assertNotPushed(InvestigateSiteJob::class);
    }

    public function test_the_verify_toggle_turns_the_loop_off(): void
    {
        Queue::fake([InvestigateSiteJob::class]);
        config(['agent.verify_after_fix' => false]);
        $site = $this->connectedSite();
        $this->fakeMcpToolCall();

        app(ApprovalGate::class)->approve($this->aiProposal($site, ['goal' => 'האתר איטי', 'round' => 1]));

        Queue::assertNotPushed(InvestigateSiteJob::class);
    }

    public function test_a_verification_pass_reports_its_outcome_to_the_owner_whatsapp(): void
    {
        config([
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default', 'billing.waha.owner_number' => '0501112222',
        ]);
        Http::fake(['waha.test/*' => Http::response(['id' => 'wa-1'])]);

        $site = $this->connectedSite();
        $this->mock(SiteAgent::class)
            ->shouldReceive('investigate')
            ->once()
            ->andReturn('✅ הבעיה נפתרה — האתר נטען מהר.');

        (new InvestigateSiteJob($site->id, 'בדוק אם האתר עדיין איטי', round: 2))
            ->handle(app(SiteAgent::class), app(SiteMemoryStore::class));

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'sendText')
            && str_contains((string) $r->data()['text'], 'הבעיה נפתרה')
            && str_contains((string) $r->data()['text'], $site->domain));
    }

    public function test_a_first_round_investigation_does_not_message_the_owner(): void
    {
        config([
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default', 'billing.waha.owner_number' => '0501112222',
        ]);
        Http::fake(['waha.test/*' => Http::response(['id' => 'wa-1'])]);

        $site = $this->connectedSite();
        $this->mock(SiteAgent::class)
            ->shouldReceive('investigate')->once()->andReturn('אבחון ראשוני: הכל תקין.');

        (new InvestigateSiteJob($site->id, 'אבחן את האתר'))
            ->handle(app(SiteAgent::class), app(SiteMemoryStore::class));

        Http::assertNothingSent();
    }
}
