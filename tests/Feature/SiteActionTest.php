<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\SiteChangeStatus;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\SiteChange;
use App\Services\Agent\SiteToolCatalog;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The kill-switch defaults OFF; these tests exercise the enabled path.
        config(['agent.actions_enabled' => true]);
    }

    private function connectedSite(array $attributes = []): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example-site.co.il/wp-json/md-agent/mcp',
            'mcp_secret' => 'site-secret',
            ...$attributes,
        ]);
    }

    private function fakeToolCall(string $text = 'done', bool $isError = false): void
    {
        Http::fake([
            'example-site.co.il/*' => function (Request $request) use ($text, $isError) {
                $body = json_decode($request->body(), true);

                if (! isset($body['id'])) {
                    return Http::response('', 202);
                }

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $body['id'],
                    'result' => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError],
                ]);
            },
        ]);
    }

    private function proposal(Site $site, string $tool, array $arguments = []): PendingAction
    {
        return PendingAction::create([
            'type' => 'site_action',
            'status' => ActionStatus::Pending,
            'customer_id' => $site->customer_id,
            'summary' => "פעולת AI: {$tool}",
            'payload' => ['site_id' => $site->id, 'tool' => $tool, 'arguments' => $arguments],
            'proposed_by' => 'ai',
        ]);
    }

    // ---- Risk catalog --------------------------------------------------------

    public function test_tools_classify_into_risk_tiers_with_a_safe_default(): void
    {
        $catalog = app(SiteToolCatalog::class);

        $this->assertSame(0, $catalog->tier('wp_plugin_list'));
        $this->assertSame(1, $catalog->tier('wp_cache_flush'));
        $this->assertSame(2, $catalog->tier('wp_plugin_update'));
        $this->assertSame(3, $catalog->tier('wp_php_exec'));
        // Both "delete" and "list" — the destructive match must win.
        $this->assertSame(3, $catalog->tier('wp_delete_from_list'));
        // Unclassified tools are treated as a change, never as read-only.
        $this->assertSame(2, $catalog->tier('mystery_tool'));
    }

    public function test_destructive_tools_are_confined_to_staging(): void
    {
        $catalog = app(SiteToolCatalog::class);
        $production = Site::factory()->create(['environment' => 'production']);
        $staging = Site::factory()->create(['environment' => 'staging']);

        $this->assertFalse($catalog->allowedOn($production, 'wp_php_exec'));
        $this->assertTrue($catalog->allowedOn($staging, 'wp_php_exec'));
        $this->assertTrue($catalog->allowedOn($production, 'wp_plugin_update'));
    }

    public function test_a_destructive_hint_confines_a_benign_named_tool_to_staging(): void
    {
        $catalog = app(SiteToolCatalog::class);

        // "db_query" has a benign name (name-tier 2) but the site declares it
        // destructive — it must be treated as tier 3 (staging-only).
        $caps = ['tools' => [['name' => 'db_query', 'read_only' => false, 'destructive' => true]]];
        $production = Site::factory()->create(['environment' => 'production', 'mcp_capabilities' => $caps]);
        $staging = Site::factory()->create(['environment' => 'staging', 'mcp_capabilities' => $caps]);

        $this->assertSame(3, $catalog->resolveTier($production, 'db_query'));
        $this->assertFalse($catalog->allowedOn($production, 'db_query'));
        $this->assertTrue($catalog->allowedOn($staging, 'db_query'));
    }

    // ---- Approved execution --------------------------------------------------

    public function test_an_approved_action_runs_the_tool_and_journals_the_change(): void
    {
        $site = $this->connectedSite();
        $this->fakeToolCall('Plugin elementor updated to 3.21');
        $action = $this->proposal($site, 'wp_plugin_update', ['plugin' => 'elementor']);

        $reply = app(ApprovalGate::class)->approve($action);

        $this->assertStringContainsString('בוצעה', $reply);
        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);

        // The MCP call went out with the site's own secret.
        Http::assertSent(fn (Request $r): bool => json_decode($r->body(), true)['method'] === 'tools/call'
            && $r->header('Authorization')[0] === 'Bearer site-secret');

        // …and the change journal holds the record, linked to the approval.
        $change = SiteChange::sole();
        $this->assertSame($site->id, $change->site_id);
        $this->assertSame('wp_plugin_update', $change->tool);
        $this->assertSame($action->id, $change->pending_action_id);
        $this->assertSame(SiteChangeStatus::Applied, $change->status);
        $this->assertStringContainsString('3.21', (string) $change->after_state);
    }

    public function test_a_read_only_tool_is_not_journaled(): void
    {
        $site = $this->connectedSite();
        $this->fakeToolCall('plugin list…');
        $action = $this->proposal($site, 'wp_plugin_list');

        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertSame(0, SiteChange::count());
    }

    public function test_a_destructive_tool_on_production_fails_even_after_approval(): void
    {
        $site = $this->connectedSite(['environment' => 'production']);
        Http::fake(); // must never be called
        $action = $this->proposal($site, 'wp_php_exec', ['code' => 'echo 1;']);

        $reply = app(ApprovalGate::class)->approve($action);

        $this->assertStringContainsString('נכשל', $reply);
        $this->assertSame(ActionStatus::Failed, $action->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_failed_tool_call_marks_the_action_failed_and_journals_the_failure(): void
    {
        $site = $this->connectedSite();
        $this->fakeToolCall('plugin not found', isError: true);
        $action = $this->proposal($site, 'wp_plugin_update', ['plugin' => 'missing']);

        $reply = app(ApprovalGate::class)->approve($action);

        $this->assertStringContainsString('נכשל', $reply);
        $this->assertSame(ActionStatus::Failed, $action->fresh()->status);

        $change = SiteChange::sole();
        $this->assertSame(SiteChangeStatus::Failed, $change->status);
        $this->assertNotNull($change->error);
    }

    public function test_a_disabled_connection_refuses_to_execute(): void
    {
        $site = $this->connectedSite(['mcp_enabled' => false]);
        Http::fake();
        $action = $this->proposal($site, 'wp_cache_flush');

        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Failed, $action->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_rejecting_a_site_action_never_touches_the_site(): void
    {
        $site = $this->connectedSite();
        Http::fake();
        $action = $this->proposal($site, 'wp_plugin_update', ['plugin' => 'elementor']);

        app(ApprovalGate::class)->reject($action);

        $this->assertSame(ActionStatus::Rejected, $action->fresh()->status);
        Http::assertNothingSent();
        $this->assertSame(0, SiteChange::count());
    }

    public function test_the_kill_switch_blocks_execution_even_after_approval(): void
    {
        config(['agent.actions_enabled' => false]);
        $site = $this->connectedSite();
        Http::fake(); // must never be called
        $action = $this->proposal($site, 'wp_cache_flush');

        $reply = app(ApprovalGate::class)->approve($action);

        $this->assertStringContainsString('נכשל', $reply);
        $this->assertSame(ActionStatus::Failed, $action->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_recorded_revert_recipe_enables_a_gated_live_rollback(): void
    {
        $site = $this->connectedSite();
        $this->fakeToolCall('updated');

        // Apply a change that knows how to undo itself.
        $apply = PendingAction::create([
            'type' => 'site_action',
            'status' => ActionStatus::Pending,
            'customer_id' => $site->customer_id,
            'summary' => 'עדכון תוסף',
            'payload' => [
                'site_id' => $site->id,
                'tool' => 'wp_plugin_update',
                'arguments' => ['plugin' => 'elementor', 'version' => '3.21'],
                'revert' => ['tool' => 'wp_plugin_update', 'arguments' => ['plugin' => 'elementor', 'version' => '3.20']],
            ],
            'proposed_by' => 'ai',
        ]);
        app(ApprovalGate::class)->approve($apply);

        $change = SiteChange::sole();
        $this->assertTrue($change->isRevertable());
        $this->assertSame('wp_plugin_update', $change->revert_tool);

        // Approving the inverse action rolls the original change back.
        $revert = PendingAction::create([
            'type' => 'site_action',
            'status' => ActionStatus::Pending,
            'customer_id' => $site->customer_id,
            'summary' => 'שחזור',
            'payload' => [
                'site_id' => $site->id,
                'tool' => (string) $change->revert_tool,
                'arguments' => (array) $change->revert_arguments,
                'reverts_change_id' => $change->id,
            ],
            'proposed_by' => 'team',
        ]);
        app(ApprovalGate::class)->approve($revert);

        $this->assertSame(SiteChangeStatus::Reverted, $change->fresh()->status);
        $this->assertNotNull($change->fresh()->reverted_at);
    }
}
