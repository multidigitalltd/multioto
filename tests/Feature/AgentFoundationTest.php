<?php

namespace Tests\Feature;

use App\Enums\SiteChangeStatus;
use App\Models\Site;
use App\Services\Agent\SiteChangeJournal;
use App\Services\Agent\SiteMemoryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AgentFoundationTest extends TestCase
{
    use RefreshDatabase;

    // ---- Per-site connection token -----------------------------------------

    public function test_a_site_token_is_stored_hashed_and_resolves_back(): void
    {
        $site = Site::factory()->create();

        $token = $site->generateAgentToken();

        // Stored as a hash, never in the clear.
        $this->assertNotSame($token, $site->fresh()->getAttributes()['agent_token']);
        $this->assertSame($site->id, Site::forAgentToken($token)?->id);

        // Rotating revokes the previous token.
        $new = $site->generateAgentToken();
        $this->assertNull(Site::forAgentToken($token));
        $this->assertSame($site->id, Site::forAgentToken($new)?->id);
    }

    public function test_the_mcp_secret_is_encrypted_at_rest(): void
    {
        $site = Site::factory()->create(['mcp_secret' => 'super-secret-key']);

        $this->assertSame('super-secret-key', $site->fresh()->mcp_secret);
        // The raw column is ciphertext, not the plaintext.
        $this->assertNotSame('super-secret-key', $site->fresh()->getRawOriginal('mcp_secret'));
    }

    // ---- Remote plugin-update channel --------------------------------------

    public function test_the_update_channel_rejects_a_missing_or_bad_token(): void
    {
        $this->getJson(route('agent.plugin.update'))->assertUnauthorized();
        $this->withToken('not-a-real-token')->getJson(route('agent.plugin.update'))->assertUnauthorized();
    }

    public function test_the_update_channel_offers_a_newer_version_and_records_the_checkin(): void
    {
        config(['agent.plugin.current_version' => '1.2.0']);
        $site = Site::factory()->create();
        $token = $site->generateAgentToken();

        $this->withToken($token)
            ->getJson(route('agent.plugin.update', ['version' => '1.0.0']))
            ->assertOk()
            ->assertJson(['version' => '1.2.0', 'update_available' => true])
            ->assertJsonPath('download_url', fn (?string $url): bool => is_string($url) && str_contains($url, 'signature='));

        // The check-in recorded which version the site runs and that it is alive.
        $this->assertSame('1.0.0', $site->fresh()->agent_plugin_version);
        $this->assertNotNull($site->fresh()->mcp_last_seen_at);
    }

    public function test_no_update_is_offered_when_the_site_is_current(): void
    {
        config(['agent.plugin.current_version' => '1.2.0']);
        $site = Site::factory()->create();
        $token = $site->generateAgentToken();

        $this->withToken($token)
            ->getJson(route('agent.plugin.update', ['version' => '1.2.0']))
            ->assertOk()
            ->assertJson(['update_available' => false, 'download_url' => null]);
    }

    public function test_a_signed_link_downloads_the_zip_and_an_unsigned_one_is_rejected(): void
    {
        Storage::fake('local');
        config(['agent.plugin.disk' => 'local', 'agent.plugin.path' => 'agent-plugin']);
        Storage::disk('local')->put('agent-plugin/1.2.0.zip', 'PK-fake-zip-bytes');

        $signed = URL::temporarySignedRoute('agent.plugin.download', now()->addMinutes(15), ['version' => '1.2.0']);
        $this->get($signed)->assertOk()->assertHeader('content-type', 'application/zip');

        // Without a valid signature the download is refused.
        $this->get(route('agent.plugin.download', ['version' => '1.2.0']))->assertForbidden();
    }

    public function test_a_signed_link_for_a_missing_version_is_not_found(): void
    {
        Storage::fake('local');
        config(['agent.plugin.disk' => 'local', 'agent.plugin.path' => 'agent-plugin']);

        $signed = URL::temporarySignedRoute('agent.plugin.download', now()->addMinutes(15), ['version' => '9.9.9']);
        $this->get($signed)->assertNotFound();
    }

    // ---- Per-site memory ----------------------------------------------------

    public function test_memory_is_written_read_overwritten_and_forgotten(): void
    {
        $site = Site::factory()->create();
        $store = app(SiteMemoryStore::class);

        $store->put($site, 'php_version', '8.1', 'dana');
        $this->assertSame('8.1', $store->get($site, 'php_version'));

        // Same key updates in place (no duplicate row).
        $store->put($site, 'php_version', '8.3', 'dana');
        $this->assertSame('8.3', $store->get($site, 'php_version'));
        $this->assertSame(1, $site->memories()->count());

        $store->put($site, 'notes', 'משתמש ב-Elementor', 'dana');
        $this->assertSame(['notes' => 'משתמש ב-Elementor', 'php_version' => '8.3'], $store->all($site));

        $store->forget($site, 'php_version');
        $this->assertNull($store->get($site, 'php_version'));
    }

    // ---- Change journal (sandbox) ------------------------------------------

    public function test_a_change_is_journaled_and_can_be_marked_reverted(): void
    {
        $site = Site::factory()->create();
        $journal = app(SiteChangeJournal::class);

        $change = $journal->record(
            $site,
            summary: 'עדכון Elementor 3.20→3.21',
            tool: 'wp_plugin_update',
            arguments: ['plugin' => 'elementor'],
            beforeState: '3.20',
            initiatedBy: 'ai',
            revertTool: 'wp_plugin_update',
            revertArguments: ['plugin' => 'elementor', 'version' => '3.20'],
        );

        $this->assertSame(SiteChangeStatus::Applied, $change->fresh()->status);
        // Revertable because it carries an inverse recipe.
        $this->assertTrue($change->isRevertable());

        $journal->markReverted($change);
        $this->assertSame(SiteChangeStatus::Reverted, $change->fresh()->status);
        $this->assertNotNull($change->fresh()->reverted_at);
    }

    public function test_a_change_without_a_before_state_cannot_be_reverted(): void
    {
        $site = Site::factory()->create();
        $journal = app(SiteChangeJournal::class);

        $change = $journal->record($site, summary: 'ניקוי מטמון', tool: 'wp_cache_flush');

        $this->assertFalse($change->isRevertable());
        $journal->markReverted($change);
        // Still applied — nothing to roll back to.
        $this->assertSame(SiteChangeStatus::Applied, $change->fresh()->status);
    }
}
