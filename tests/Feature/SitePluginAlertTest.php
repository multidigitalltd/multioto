<?php

namespace Tests\Feature;

use App\Jobs\CheckSitePluginChangesJob;
use App\Models\Site;
use App\Services\Agent\McpClient;
use App\Services\Agent\SitePluginInventory;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SitePluginAlertTest extends TestCase
{
    use RefreshDatabase;

    private function connectedSite(?array $snapshot): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://site.example.com/wp-json/md-agent/v1/mcp',
            'mcp_capabilities' => ['tools' => [['name' => 'wp_plugin_list']]],
            'plugin_snapshot' => $snapshot,
        ]);
    }

    /** McpClient stub whose wp_plugin_list returns the given text. */
    private function mcpReturning(string $text): McpClient
    {
        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->andReturn(['content' => [['type' => 'text', 'text' => $text]]]);
        $mcp->shouldReceive('textContent')->andReturn($text);

        return $mcp;
    }

    public function test_the_first_run_baselines_without_alerting(): void
    {
        $site = $this->connectedSite(null);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');

        (new CheckSitePluginChangesJob($site->id))->handle(
            $this->mcpReturning("akismet active 5.3\nhello-dolly inactive 1.7"),
            $team,
        );

        $this->assertSame(['akismet', 'hello-dolly'], $site->fresh()->plugin_snapshot['plugins']);
    }

    public function test_a_newly_installed_plugin_alerts_the_team(): void
    {
        $site = $this->connectedSite(['plugins' => ['akismet', 'hello-dolly']]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once();

        (new CheckSitePluginChangesJob($site->id))->handle(
            $this->mcpReturning("akismet active 5.3\nhello-dolly inactive 1.7\nmalware-x active 1.0"),
            $team,
        );

        // The snapshot now includes the new plugin (so it won't alert again).
        $this->assertContains('malware-x', $site->fresh()->plugin_snapshot['plugins']);
    }

    public function test_a_version_bump_is_not_treated_as_a_new_install(): void
    {
        $site = $this->connectedSite(['plugins' => ['akismet', 'hello-dolly']]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');

        (new CheckSitePluginChangesJob($site->id))->handle(
            $this->mcpReturning("akismet active 5.9\nhello-dolly inactive 1.8"),
            $team,
        );
    }

    /** A site whose plugin exposes wp_admin_list; the MCP stub answers per tool. */
    private function adminSite(?array $snapshot): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://site.example.com/wp-json/md-agent/v1/mcp',
            'mcp_capabilities' => ['tools' => [['name' => 'wp_admin_list']]],
            'plugin_snapshot' => $snapshot,
        ]);
    }

    public function test_a_new_admin_user_alerts_the_team_loudly(): void
    {
        $site = $this->adminSite(['admins' => ['yossi']]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()->withArgs(fn (string $title, string $body): bool => str_contains($title, 'משתמש מנהל חדש')
            && str_contains($body, 'hacker99')
            && str_contains($body, 'נפרץ'));

        (new CheckSitePluginChangesJob($site->id))->handle(
            $this->mcpReturning((string) json_encode([
                ['id' => 1, 'login' => 'yossi', 'email' => 'yossi@a.co.il'],
                ['id' => 7, 'login' => 'hacker99', 'email' => 'x@evil.test'],
            ])),
            $team,
        );

        $this->assertContains('hacker99', $site->fresh()->plugin_snapshot['admins']);
    }

    public function test_an_admin_email_change_is_not_a_new_admin(): void
    {
        // The login is the identity — an email update must not alert.
        $site = $this->adminSite(['admins' => ['yossi']]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');

        (new CheckSitePluginChangesJob($site->id))->handle(
            $this->mcpReturning((string) json_encode([
                ['id' => 1, 'login' => 'yossi', 'email' => 'new-mail@b.co.il'],
            ])),
            $team,
        );
    }

    public function test_an_admin_named_like_a_status_word_cannot_hide_from_the_alert(): void
    {
        // The plugin/theme normalizer strips words like "active" and version
        // tokens — an attacker must not be able to hide behind such a login.
        $site = $this->adminSite(['admins' => ['yossi']]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()->withArgs(fn (string $title, string $body): bool => str_contains($body, 'active')
            && str_contains($body, 'v1.2'));

        (new CheckSitePluginChangesJob($site->id))->handle(
            $this->mcpReturning((string) json_encode([
                ['id' => 1, 'login' => 'yossi', 'email' => 'y@a.co.il'],
                ['id' => 8, 'login' => 'Active', 'email' => 'a@evil.test'],
                ['id' => 9, 'login' => 'v1.2', 'email' => 'b@evil.test'],
            ])),
            $team,
        );

        $snapshot = $site->fresh()->plugin_snapshot['admins'];
        $this->assertContains('active', $snapshot);
        $this->assertContains('v1.2', $snapshot);
    }

    public function test_the_first_admin_snapshot_baselines_silently(): void
    {
        $site = $this->adminSite(null);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');

        (new CheckSitePluginChangesJob($site->id))->handle(
            $this->mcpReturning((string) json_encode([['id' => 1, 'login' => 'yossi', 'email' => 'y@a.co.il']])),
            $team,
        );

        $this->assertSame(['yossi'], $site->fresh()->plugin_snapshot['admins']);
    }

    public function test_inventory_parses_json_and_text(): void
    {
        $json = json_encode([['slug' => 'woocommerce', 'version' => '9.1'], ['slug' => 'akismet', 'version' => '5.3']]);
        $this->assertSame(['akismet', 'woocommerce'], SitePluginInventory::identities((string) $json));

        $text = "WooCommerce | active | 9.1\nAkismet | inactive | 5.3";
        $this->assertSame(['akismet', 'woocommerce'], SitePluginInventory::identities($text));
    }

    public function test_the_stable_plugin_file_is_used_as_the_identity_not_the_display_name(): void
    {
        // The bundled wp_plugin_list returns both — a display-name change must not
        // read as a new install, so the stable 'plugin' file path is the key.
        $json = json_encode([['plugin' => 'akismet/akismet.php', 'name' => 'Akismet Anti-Spam', 'version' => '5.3']]);

        $this->assertSame(['akismet/akismet.php'], SitePluginInventory::identities((string) $json));
    }
}
