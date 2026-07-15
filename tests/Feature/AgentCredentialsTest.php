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

    public function test_endpoint_is_built_from_the_host_even_when_the_domain_has_a_scheme(): void
    {
        // The domain column accepts a full URL; the endpoint must not double up
        // the scheme (https://https://…).
        $withScheme = Site::factory()->make(['domain' => 'https://multidigital.co.il']);
        $this->assertSame('https://multidigital.co.il/wp-json/md-agent/v1/mcp', $withScheme->conventionalMcpEndpoint());

        $withPath = Site::factory()->make(['domain' => 'https://multidigital.co.il/']);
        $this->assertSame('https://multidigital.co.il/wp-json/md-agent/v1/mcp', $withPath->conventionalMcpEndpoint());

        $bare = Site::factory()->make(['domain' => 'multidigital.co.il']);
        $this->assertSame('https://multidigital.co.il/wp-json/md-agent/v1/mcp', $bare->conventionalMcpEndpoint());
    }

    public function test_ensure_agent_credentials_heals_a_doubled_scheme_endpoint(): void
    {
        $site = Site::factory()->create([
            'domain' => 'https://multidigital.co.il',
            'mcp_endpoint' => 'https://https://multidigital.co.il/wp-json/md-agent/v1/mcp',
        ]);

        $codes = $site->ensureAgentCredentials();

        $this->assertSame('https://multidigital.co.il/wp-json/md-agent/v1/mcp', $codes['mcp_endpoint']);
        $this->assertSame('https://multidigital.co.il/wp-json/md-agent/v1/mcp', $site->fresh()->mcp_endpoint);
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

    public function test_ensure_agent_credentials_never_rotates_a_pre_existing_token_on_view(): void
    {
        // A site connected before agent_token_plain existed: it has a hash but no
        // retrievable copy. Opening the codes modal must NOT rotate it — that
        // would 401 the plugin already installed with the old token.
        $site = Site::factory()->create();
        $site->forceFill(['agent_token' => hash('sha256', 'legacy-token'), 'agent_token_plain' => null])->save();

        $codes = $site->ensureAgentCredentials();

        // Hash is untouched; the old token still authenticates.
        $this->assertSame(hash('sha256', 'legacy-token'), $site->fresh()->agent_token);
        $this->assertTrue(Site::forAgentToken('legacy-token')->is($site));
        // The token can't be shown — the view handles this (empty string).
        $this->assertSame('', $codes['update_token']);
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
