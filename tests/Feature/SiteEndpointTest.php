<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_enabled_site_derives_its_endpoint_from_the_domain(): void
    {
        $site = Site::factory()->create([
            'domain' => 'example.co.il',
            'mcp_enabled' => true,
            'mcp_endpoint' => null,
        ]);

        $this->assertSame('https://example.co.il/wp-json/md-agent/v1/mcp', $site->mcp_endpoint);
    }

    public function test_a_disabled_site_keeps_a_blank_endpoint(): void
    {
        $site = Site::factory()->create([
            'domain' => 'example.co.il',
            'mcp_enabled' => false,
            'mcp_endpoint' => null,
        ]);

        $this->assertNull($site->mcp_endpoint);
    }

    public function test_changing_the_domain_regenerates_an_auto_derived_endpoint(): void
    {
        $site = Site::factory()->create([
            'domain' => 'old.co.il',
            'mcp_enabled' => true,
            'mcp_endpoint' => null,
        ]);
        $this->assertSame('https://old.co.il/wp-json/md-agent/v1/mcp', $site->mcp_endpoint);

        $site->update(['domain' => 'new.co.il']);

        $this->assertSame('https://new.co.il/wp-json/md-agent/v1/mcp', $site->fresh()->mcp_endpoint);
    }

    public function test_a_custom_endpoint_survives_a_domain_change(): void
    {
        $site = Site::factory()->create([
            'domain' => 'old.co.il',
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://proxy.example.net/custom/mcp',
        ]);

        $site->update(['domain' => 'new.co.il']);

        $this->assertSame('https://proxy.example.net/custom/mcp', $site->fresh()->mcp_endpoint);
    }
}
