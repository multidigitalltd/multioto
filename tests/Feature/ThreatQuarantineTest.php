<?php

namespace Tests\Feature;

use App\Jobs\CheckSitePluginChangesJob;
use App\Jobs\PurgeSiteThreatsJob;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Services\Agent\McpClient;
use App\Services\Agent\SiteToolCatalog;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\ThreatQuarantine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Removing an intrusion without asking anyone.
 *
 * The behaviour under test is deliberately unlike everything else the agent
 * does: no proposal, no approval, no undo. So the tests are mostly about the
 * fences — that it removes exactly what is on the list and nothing adjacent,
 * that it never locks a site's owner out, and that a removal nobody watched
 * still reaches the team.
 */
class ThreatQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private function site(array $attributes = []): Site
    {
        return Site::factory()->create(array_merge([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://example.test/wp-json/md-agent/v1/mcp',
            'mcp_secret' => 'secret',
        ], $attributes));
    }

    /** A JSON-RPC tools/call reply carrying `$text`. */
    private function toolResult(string $text): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
        ]];
    }

    public function test_the_panel_and_the_plugin_quarantine_the_same_things(): void
    {
        $guard = file_get_contents(__DIR__.'/../../wordpress-plugin/multioto-agent/includes/class-guard.php');

        // Two copies of a list that decides what gets deleted is one copy too
        // many; this is what keeps them honest. The plugin's is authoritative —
        // it is the one that runs on the customer's server.
        foreach (ThreatQuarantine::users() as $login) {
            $this->assertStringContainsString("'{$login}'", $guard,
                "The plugin guard must quarantine '{$login}' too, or the panel is watching for something no site removes.");
        }

        foreach (ThreatQuarantine::plugins() as $slug) {
            $this->assertStringContainsString("'{$slug}'", $guard);
        }

        $this->assertContains('sys_maint', ThreatQuarantine::users());
        $this->assertContains('wp-file-manager', ThreatQuarantine::plugins());
    }

    public function test_a_quarantined_admin_is_matched_and_a_lookalike_is_not(): void
    {
        $admins = json_encode([
            ['id' => 1, 'login' => 'owner'],
            ['id' => 9, 'login' => 'sys_maint'],
            // Close, but not it. Deleting this would be the system removing an
            // account a customer created — the failure that ends all trust in
            // an automatic removal.
            ['id' => 10, 'login' => 'sys_maintenance'],
        ]);

        $this->assertSame(['sys_maint'], ThreatQuarantine::usersIn($admins));
    }

    public function test_a_quarantined_plugin_is_matched_by_its_folder_not_its_name(): void
    {
        $plugins = json_encode([
            ['plugin' => 'akismet/akismet.php', 'name' => 'Akismet'],
            // The display name is whatever the header says — an intruder edits
            // it in a second. The folder is what actually loads.
            ['plugin' => 'wp-file-manager/file_folder_manager.php', 'name' => 'ניהול תוכן'],
        ]);

        $this->assertSame(['wp-file-manager'], ThreatQuarantine::pluginsIn($plugins));
        $this->assertSame('wp-file-manager/file_folder_manager.php',
            ThreatQuarantine::pluginFileFor($plugins, 'wp-file-manager'));
    }

    public function test_a_plugin_merely_named_like_the_quarantined_one_is_not_matched(): void
    {
        $plugins = json_encode([
            ['plugin' => 'my-media-library/index.php', 'name' => 'WP File Manager Pro'],
        ]);

        $this->assertSame([], ThreatQuarantine::pluginsIn($plugins));
    }

    public function test_the_guard_purges_and_the_removal_reaches_the_team(): void
    {
        $site = $this->site();

        $status = fn (array $present, array $actions): string => json_encode([
            'quarantine' => ['users' => ['sys_maint'], 'plugins' => ['wp-file-manager']],
            'present' => $present,
            'actions' => $actions,
            'last_id' => 4,
        ]);

        Http::fakeSequence()
            // First status: the account is there.
            ->push($this->toolResult($status(['users' => ['sys_maint'], 'plugins' => []], [])))
            // wp_guard_purge
            ->push($this->toolResult('{"removed":1}'))
            // Status again: gone, and the log says what happened.
            ->push($this->toolResult($status(['users' => [], 'plugins' => []], [
                ['id' => 4, 'kind' => 'user', 'target' => 'sys_maint', 'result' => 'removed', 'detail' => 'משתמש #12 (מנהל) נמחק.'],
            ])));

        (new PurgeSiteThreatsJob($site->id))->handle(app(McpClient::class), app(TeamNotifier::class));

        $event = SiteEvent::where('site_id', $site->id)->sole();
        $this->assertSame('threat_purged', $event->type);
        $this->assertSame('critical', $event->severity);
        $this->assertStringContainsString('sys_maint', $event->title);

        // The cursor advanced, so the same removal is not reported every hour.
        $this->assertSame(4, $site->fresh()->guard_cursor);
    }

    public function test_a_clean_site_is_left_alone_and_says_nothing(): void
    {
        $site = $this->site();

        Http::fake([
            '*' => Http::response($this->toolResult(json_encode([
                'present' => ['users' => [], 'plugins' => []],
                'actions' => [],
                'last_id' => 0,
            ]))),
        ]);

        (new PurgeSiteThreatsJob($site->id))->handle(app(McpClient::class), app(TeamNotifier::class));

        $this->assertSame(0, SiteEvent::where('site_id', $site->id)->count());
        $this->assertSame(0, $site->fresh()->guard_cursor);
    }

    public function test_a_threat_the_guard_could_not_remove_is_reported_not_swallowed(): void
    {
        $site = $this->site();

        $stuck = json_encode([
            // Still present after the purge ran: the guard refused (the last
            // administrator) or could not write.
            'present' => ['users' => ['sys_maint'], 'plugins' => []],
            'actions' => [
                ['id' => 7, 'kind' => 'user', 'target' => 'sys_maint', 'result' => 'skipped', 'detail' => 'מנהל האתר היחיד.'],
            ],
            'last_id' => 7,
        ]);

        Http::fakeSequence()
            ->push($this->toolResult($stuck))
            ->push($this->toolResult('{"removed":0}'))
            ->push($this->toolResult($stuck));

        (new PurgeSiteThreatsJob($site->id))->handle(app(McpClient::class), app(TeamNotifier::class));

        $event = SiteEvent::where('site_id', $site->id)->sole();
        // Not "purged": a removal that did not happen must never be filed as one.
        $this->assertSame('threat_found', $event->type);
    }

    public function test_an_older_plugin_falls_back_to_deactivating_and_says_so(): void
    {
        $site = $this->site(['agent_plugin_version' => '1.4.0']);

        Http::fakeSequence()
            // wp_guard_status — unknown tool on an older plugin.
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'Unknown tool: wp_guard_status']])
            ->push($this->toolResult(json_encode([['id' => 1, 'login' => 'owner']])))
            ->push($this->toolResult(json_encode([['plugin' => 'wp-file-manager/file_folder_manager.php', 'name' => 'WP File Manager']])))
            // wp_plugin_deactivate
            ->push($this->toolResult('התוסף כובה.'));

        (new PurgeSiteThreatsJob($site->id))->handle(app(McpClient::class), app(TeamNotifier::class));

        $event = SiteEvent::where('site_id', $site->id)->sole();
        $this->assertSame('threat_purged', $event->type);
        // Honest about being half a job: the files are still on the server.
        $this->assertStringContainsString('קבצי התוסף עדיין על השרת', (string) $event->detail);
    }

    public function test_spotting_a_quarantined_addition_starts_a_purge_instead_of_an_alert(): void
    {
        Queue::fake([PurgeSiteThreatsJob::class]);

        $site = $this->site([
            'mcp_capabilities' => ['tools' => [['name' => 'wp_admin_list'], ['name' => 'wp_plugin_list'], ['name' => 'wp_theme_list']]],
            'plugin_snapshot' => ['admins' => ['owner'], 'plugins' => [], 'themes' => []],
        ]);

        Http::fakeSequence()
            ->push($this->toolResult(json_encode([['plugin' => 'akismet/akismet.php']])))
            ->push($this->toolResult('[]'))
            ->push($this->toolResult(json_encode([['id' => 1, 'login' => 'owner'], ['id' => 2, 'login' => 'sys_maint']])));

        (new CheckSitePluginChangesJob($site->id))->handle(app(McpClient::class), app(TeamNotifier::class));

        Queue::assertPushed(PurgeSiteThreatsJob::class, fn ($job): bool => $job->siteId === $site->id);
    }

    public function test_an_ordinary_new_admin_is_reported_and_not_purged(): void
    {
        Queue::fake([PurgeSiteThreatsJob::class]);

        $site = $this->site([
            'mcp_capabilities' => ['tools' => [['name' => 'wp_admin_list']]],
            'plugin_snapshot' => ['admins' => ['owner']],
        ]);

        Http::fake(['*' => Http::response($this->toolResult(
            json_encode([['id' => 1, 'login' => 'owner'], ['id' => 2, 'login' => 'dana']]),
        ))]);

        (new CheckSitePluginChangesJob($site->id))->handle(app(McpClient::class), app(TeamNotifier::class));

        // A new administrator is a serious finding, but it is a finding — the
        // automatic removal is reserved for the two names on the list.
        Queue::assertNotPushed(PurgeSiteThreatsJob::class);
        $this->assertSame('admin_added', SiteEvent::where('site_id', $site->id)->sole()->type);
    }

    public function test_the_purge_tool_is_classified_as_destructive(): void
    {
        $catalog = app(SiteToolCatalog::class);

        // The panel calls this from a scheduled job, which is not gated. The AI
        // agent reaches the same tool through the approval gate, and there a
        // tool that deletes must sit at the top tier: it may propose it, never
        // run it on its own.
        $this->assertSame(3, $catalog->tier('wp_guard_purge'));
        $this->assertSame(0, $catalog->tier('wp_guard_status'));
    }
}
