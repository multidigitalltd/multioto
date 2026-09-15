<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Putting a release onto the disk sites download from.
 *
 * The published file has to BE the committed release — a version number that
 * means different bytes on the disk than in the repo is a build nobody can
 * reproduce, on sites nobody can roll back.
 */
class PublishAgentPluginTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_the_committed_release_for_the_offered_version(): void
    {
        Storage::fake('plugins');
        config(['agent.plugin.disk' => 'plugins', 'agent.plugin.path' => 'agent-plugin']);

        $version = (string) config('agent.plugin.current_version');

        $this->artisan('agent:publish-plugin')->assertSuccessful();

        Storage::disk('plugins')->assertExists("agent-plugin/{$version}.zip");
        $this->assertSame(
            file_get_contents(base_path("wordpress-plugin/releases/multioto-agent-{$version}.zip")),
            Storage::disk('plugins')->get("agent-plugin/{$version}.zip"),
        );
    }

    public function test_an_existing_file_is_left_alone_unless_forced(): void
    {
        Storage::fake('plugins');
        config(['agent.plugin.disk' => 'plugins', 'agent.plugin.path' => 'agent-plugin']);

        $version = (string) config('agent.plugin.current_version');
        Storage::disk('plugins')->put("agent-plugin/{$version}.zip", 'an earlier build');

        // Sites may already have downloaded this exact version. Replacing the
        // bytes under the same number, silently, is how two sites end up on
        // "1.5.0" running different code.
        $this->artisan('agent:publish-plugin')->assertSuccessful();
        $this->assertSame('an earlier build', Storage::disk('plugins')->get("agent-plugin/{$version}.zip"));

        $this->artisan('agent:publish-plugin --force')->assertSuccessful();
        $this->assertNotSame('an earlier build', Storage::disk('plugins')->get("agent-plugin/{$version}.zip"));
    }

    public function test_a_version_with_no_committed_release_fails_loudly(): void
    {
        Storage::fake('plugins');
        config(['agent.plugin.disk' => 'plugins', 'agent.plugin.path' => 'agent-plugin']);

        // Reporting success here would leave the team believing sites can
        // update to a build that does not exist.
        $this->artisan('agent:publish-plugin 9.9.9')->assertFailed();
        Storage::disk('plugins')->assertMissing('agent-plugin/9.9.9.zip');
    }

    public function test_a_malformed_version_is_refused(): void
    {
        Storage::fake('plugins');
        config(['agent.plugin.disk' => 'plugins', 'agent.plugin.path' => 'agent-plugin']);

        // The version becomes a path segment; anything that is not a version
        // number has no business being one.
        $this->artisan('agent:publish-plugin ../../etc/passwd')->assertFailed();
    }
}
