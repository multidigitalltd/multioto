<?php

namespace Tests\Feature;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
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

    public function test_nothing_is_written_under_the_served_name_until_the_file_is_whole(): void
    {
        config(['agent.plugin.disk' => 'plugins', 'agent.plugin.path' => 'agent-plugin']);

        $version = (string) config('agent.plugin.current_version');
        $target = "agent-plugin/{$version}.zip";
        $staged = null;

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('put')->once()->andReturnUsing(function (string $path) use (&$staged, $target) {
            // Sites check in for updates around the clock. If the half-written
            // copy sat at the served name, one of them would download a
            // truncated zip and install a broken plugin.
            $this->assertNotSame($target, $path);
            $staged = $path;

            return true;
        });
        $disk->shouldReceive('move')->once()->andReturnUsing(function (string $from, string $to) use (&$staged, $target) {
            $this->assertSame($staged, $from);
            $this->assertSame($target, $to);

            return true;
        });

        Storage::set('plugins', $disk);

        $this->artisan('agent:publish-plugin')->assertSuccessful();
    }

    public function test_a_publish_that_fails_leaves_the_previous_release_untouched(): void
    {
        config(['agent.plugin.disk' => 'plugins', 'agent.plugin.path' => 'agent-plugin']);

        $deleted = [];

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('put')->once()->andReturn(true);
        // The disk fills up, or the volume is read-only, at the last step.
        $disk->shouldReceive('move')->once()->andReturn(false);
        $disk->shouldReceive('delete')->andReturnUsing(function (string $path) use (&$deleted) {
            $deleted[] = $path;

            return true;
        });

        Storage::set('plugins', $disk);

        // The build sites are already updating to has to survive a failed
        // replacement, and the leftover must not accumulate on the disk.
        $this->artisan('agent:publish-plugin --force')->assertFailed();
        $this->assertCount(1, $deleted);
        $this->assertStringContainsString('.tmp', $deleted[0]);
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
