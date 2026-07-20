<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Agent\SiteToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The companion plugin's WooCommerce tools are read-only diagnostics, so the
 * panel must classify them as tier-0 reads — runnable during AI investigation
 * without an approval, and never confined to staging.
 */
class WooCommerceToolsTest extends TestCase
{
    use RefreshDatabase;

    private function storeSite(): Site
    {
        return Site::factory()->create([
            'mcp_enabled' => true,
            'mcp_capabilities' => [
                'tools' => [
                    ['name' => 'wc_order_get', 'read_only' => true, 'destructive' => false],
                    ['name' => 'wc_shipping_zones_list', 'read_only' => true, 'destructive' => false],
                ],
            ],
        ]);
    }

    public function test_woocommerce_read_tools_are_tier_zero_read_only(): void
    {
        $catalog = app(SiteToolCatalog::class);
        $site = $this->storeSite();

        foreach (['wc_order_get', 'wc_shipping_zones_list'] as $tool) {
            $this->assertSame(0, $catalog->resolveTier($site, $tool), "{$tool} should be tier 0");
            $this->assertTrue($catalog->isReadOnly($site, $tool), "{$tool} should qualify as a read");
            $this->assertTrue($catalog->allowedOn($site, $tool), "{$tool} should be allowed anywhere");
        }
    }
}
