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
