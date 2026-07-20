<?php

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\DetectSiteTypeJob;
use App\Models\Site;
use App\Services\Agent\McpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SiteTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_infers_a_store_from_a_woocommerce_plugin_list(): void
    {
        $this->assertSame(SiteType::Store, SiteType::fromPluginList('akismet, WooCommerce, elementor'));
        $this->assertSame(SiteType::Store, SiteType::fromPluginList('woocommerce/woocommerce.php'));
        $this->assertSame(SiteType::Brochure, SiteType::fromPluginList('akismet, elementor, yoast'));

        // A WooCommerce EXTENSION without the core plugin is not a store.
        $this->assertSame(SiteType::Brochure, SiteType::fromPluginList('woocommerce-gateway-stripe/woocommerce-gateway-stripe.php'));
    }

    public function test_apply_detected_type_fills_only_when_unset_unless_forced(): void
    {
        $site = Site::factory()->create(['site_type' => null]);

        $site->applyDetectedType('woocommerce active');
        $this->assertSame(SiteType::Store, $site->fresh()->site_type);

        // A later background detection must NOT override the stored value…
        $site->applyDetectedType('no shop here');
        $this->assertSame(SiteType::Store, $site->fresh()->site_type);

        // …unless the operator explicitly re-detects (force).
        $site->applyDetectedType('no shop here', force: true);
        $this->assertSame(SiteType::Brochure, $site->fresh()->site_type);
    }

    public function test_the_detect_job_classifies_from_the_plugin_list_tool(): void
    {
        $site = Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://shop.test/wp-json/md-agent/v1/mcp',
            'site_type' => null,
            'mcp_capabilities' => ['tools' => [['name' => 'wp_plugin_list']]],
        ]);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->once()->andReturn(['content' => []]);
        $mcp->shouldReceive('textContent')->once()->andReturn('WooCommerce 8.6 (active)');
        $this->app->instance(McpClient::class, $mcp);

        (new DetectSiteTypeJob($site->id))->handle($mcp);

        $this->assertSame(SiteType::Store, $site->fresh()->site_type);
    }

    public function test_the_detect_job_skips_a_site_without_the_plugin_tool(): void
    {
        $site = Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://x.test/wp-json/md-agent/v1/mcp',
            'site_type' => null,
            'mcp_capabilities' => ['tools' => [['name' => 'wp_health']]],
        ]);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldNotReceive('callTool');
        $this->app->instance(McpClient::class, $mcp);

        (new DetectSiteTypeJob($site->id))->handle($mcp);

        $this->assertNull($site->fresh()->site_type);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
