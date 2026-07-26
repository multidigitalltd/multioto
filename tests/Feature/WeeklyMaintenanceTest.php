<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Jobs\WeeklyMaintenanceJob;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\StandingApproval;
use App\Models\SystemLog;
use App\Services\Agent\MaintenanceRunner;
use App\Services\Agent\McpClient;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Weekly proactive maintenance: pending plugin updates are proposed through
 * the approval gate (auto-running under a standing approval), each update is
 * followed by a homepage health check, and the batch stops the moment the
 * site stops answering.
 */
class WeeklyMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.waha.owner_number' => '', 'agent.actions_enabled' => true]);
    }

    private function connectedSite(): Site
    {
        return Site::factory()->create([
            'domain' => 'maintained.co.il',
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://maintained.co.il/wp-json/md-agent/v1/mcp',
        ]);
    }

    /** @param  list<array<string, mixed>>  $pluginRows */
    private function mcpWithPluginList(array $pluginRows): Mockery\MockInterface
    {
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')
            ->with(Mockery::type(Site::class), 'wp_plugin_list')
            ->andReturn(['content' => [['type' => 'text', 'text' => json_encode($pluginRows)]]]);
        $mcp->shouldReceive('textContent')
            ->andReturnUsing(fn (array $result): string => (string) ($result['content'][0]['text'] ?? ''));
        $this->app->instance(McpClient::class, $mcp);

        return $mcp;
    }

    public function test_pending_updates_are_proposed_through_the_gate(): void
    {
        $site = $this->connectedSite();
        $this->mcpWithPluginList([
            ['plugin' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.0', 'update_available' => true],
            ['plugin' => 'hello.php', 'name' => 'Hello Dolly', 'version' => '1.7', 'update_available' => false],
        ]);

        (new WeeklyMaintenanceJob($site->id))->handle(app(MaintenanceRunner::class), app(ApprovalGate::class));

        $action = PendingAction::sole();
        $this->assertSame('maintenance_update', $action->type);
        $this->assertSame(ActionStatus::Pending, $action->status);
        $this->assertStringContainsString('Akismet', $action->summary);
        $this->assertStringNotContainsString('Hello Dolly', $action->summary);
        $this->assertCount(1, data_get($action->payload, 'updates'));
    }

    public function test_no_updates_means_no_proposal(): void
    {
        $site = $this->connectedSite();
        $this->mcpWithPluginList([
            ['plugin' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.3', 'update_available' => false],
        ]);

        (new WeeklyMaintenanceJob($site->id))->handle(app(MaintenanceRunner::class), app(ApprovalGate::class));

        $this->assertSame(0, PendingAction::count());
    }

    public function test_a_standing_approval_runs_the_whole_batch_automatically(): void
    {
        $site = $this->connectedSite();
        Http::fake(['*' => Http::response('<html>בית</html>')]); // homepage healthy

        $mcp = $this->mcpWithPluginList([
            ['plugin' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.0', 'update_available' => true],
            ['plugin' => 'yoast/wp-seo.php', 'name' => 'Yoast SEO', 'version' => '21.0', 'update_available' => true],
        ]);
        $mcp->shouldReceive('callTool')
            ->with(Mockery::type(Site::class), 'wp_plugin_update', Mockery::any(), Mockery::any())
            ->twice()
            ->andReturn(['content' => [['type' => 'text', 'text' => 'updated']]]);

        StandingApproval::create(['action_key' => 'maintenance_update', 'label' => 'תחזוקה שבועית']);

        (new WeeklyMaintenanceJob($site->id))->handle(app(MaintenanceRunner::class), app(ApprovalGate::class));

        $action = PendingAction::sole();
        $this->assertSame(ActionStatus::Executed, $action->status);
        $this->assertNotNull($action->standing_approval_id);
        $this->assertTrue(SystemLog::query()->where('message', 'like', '%תחזוקה שבועית לאתר maintained.co.il הושלמה%')->exists());
    }

    public function test_the_batch_stops_when_the_site_stops_answering(): void
    {
        $site = $this->connectedSite();
        Http::fake(['*' => Http::response('error', 500)]); // homepage broken after update

        $mcp = $this->mcpWithPluginList([
            ['plugin' => 'a/a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => true],
            ['plugin' => 'b/b.php', 'name' => 'B', 'version' => '1.0', 'update_available' => true],
        ]);
        // Only the FIRST update runs — the failed health check stops the batch.
        $mcp->shouldReceive('callTool')
            ->with(Mockery::type(Site::class), 'wp_plugin_update', ['plugin' => 'a/a.php'], Mockery::any())
            ->once()
            ->andReturn(['content' => [['type' => 'text', 'text' => 'updated']]]);

        StandingApproval::create(['action_key' => 'maintenance_update', 'label' => 'תחזוקה שבועית']);

        (new WeeklyMaintenanceJob($site->id))->handle(app(MaintenanceRunner::class), app(ApprovalGate::class));

        $action = PendingAction::sole();
        $this->assertSame(ActionStatus::Failed, $action->status);
        $this->assertStringContainsString('הפסיק להגיב', (string) $action->error);
    }

    public function test_the_updates_cap_is_respected_and_disclosed(): void
    {
        config(['agent.weekly_maintenance_max_updates' => 1]);
        $site = $this->connectedSite();
        $this->mcpWithPluginList([
            ['plugin' => 'a/a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => true],
            ['plugin' => 'b/b.php', 'name' => 'B', 'version' => '1.0', 'update_available' => true],
        ]);

        (new WeeklyMaintenanceJob($site->id))->handle(app(MaintenanceRunner::class), app(ApprovalGate::class));

        $action = PendingAction::sole();
        $this->assertCount(1, data_get($action->payload, 'updates'));
        $this->assertStringContainsString('ימתינו לשבוע הבא', $action->summary);
    }

    public function test_an_unreadable_plugin_list_leaves_a_trace(): void
    {
        $site = $this->connectedSite();
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andThrow(new \RuntimeException('connection refused'));
        $this->app->instance(McpClient::class, $mcp);

        (new WeeklyMaintenanceJob($site->id))->handle(app(MaintenanceRunner::class), app(ApprovalGate::class));

        $this->assertSame(0, PendingAction::count());
        $this->assertTrue(SystemLog::query()->where('message', 'like', '%לא ניתן היה לקרוא את רשימת התוספים%')->exists());
    }
}
