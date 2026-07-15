<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_agent_credentials_generates_a_ready_to_copy_set(): void
    {
        $site = Site::factory()->create([
            'domain' => 'shop.example.com',
            'mcp_endpoint' => null,
            'mcp_secret' => null,
        ]);

        $codes = $site->ensureAgentCredentials();

        $this->assertSame(rtrim(config('app.url'), '/'), $codes['panel_url']);
        $this->assertSame('https://shop.example.com/wp-json/md-agent/v1/mcp', $codes['mcp_endpoint']);
        $this->assertNotEmpty($codes['mcp_secret']);
        $this->assertNotEmpty($codes['update_token']);

        // Persisted, and the encrypted values round-trip back out.
        $fresh = $site->fresh();
        $this->assertSame($codes['mcp_secret'], $fresh->mcp_secret);
        $this->assertSame($codes['update_token'], $fresh->agent_token_plain);
        // The connection toggle is NOT flipped implicitly.
        $this->assertFalse((bool) $fresh->mcp_enabled);
    }

    public function test_ensure_agent_credentials_is_idempotent(): void
    {
        $site = Site::factory()->create(['mcp_endpoint' => null, 'mcp_secret' => null]);

        $first = $site->ensureAgentCredentials();
        $second = $site->fresh()->ensureAgentCredentials();

        // Existing codes are kept, never silently rotated.
        $this->assertSame($first['mcp_secret'], $second['mcp_secret']);
        $this->assertSame($first['update_token'], $second['update_token']);
        $this->assertSame($first['mcp_endpoint'], $second['mcp_endpoint']);
    }

    public function test_generate_agent_token_stores_hash_and_retrievable_copy(): void
    {
        $site = Site::factory()->create();

        $token = $site->generateAgentToken();

        // The plaintext is retrievable for display…
        $this->assertSame($token, $site->fresh()->agent_token_plain);
        // …and the hash still resolves the site (constant-time lookup path).
        $this->assertTrue(Site::forAgentToken($token)->is($site));
        // Rotating revokes the previous token.
        $rotated = $site->generateAgentToken();
        $this->assertNull(Site::forAgentToken($token));
        $this->assertTrue(Site::forAgentToken($rotated)->is($site));
    }

    public function test_admin_can_download_the_current_plugin_build(): void
    {
        $this->actingAs(User::factory()->create()); // factory default = admin

        $version = config('agent.plugin.current_version');
        $response = $this->get(route('agent.plugin.latest'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $this->assertStringContainsString(
            "multioto-agent-{$version}.zip",
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_a_non_admin_cannot_download_the_plugin(): void
    {
        $this->actingAs(User::factory()->agent()->create());

        $this->get(route('agent.plugin.latest'))->assertForbidden();
    }

    public function test_the_plugin_download_requires_authentication(): void
    {
        $this->get(route('agent.plugin.latest'))->assertRedirect();
    }
}
