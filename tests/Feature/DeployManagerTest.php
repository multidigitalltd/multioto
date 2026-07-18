<?php

namespace Tests\Feature;

use App\Services\System\DeployManager;
use Tests\TestCase;

class DeployManagerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/multioto-ops-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_it_reports_configured_when_ops_dir_is_writable(): void
    {
        $this->assertTrue((new DeployManager($this->dir))->isConfigured());
        $this->assertFalse((new DeployManager($this->dir.'/missing'))->isConfigured());
    }

    public function test_requesting_an_update_writes_a_flag_and_is_idempotent(): void
    {
        $manager = new DeployManager($this->dir);

        $this->assertFalse($manager->isPending());
        $this->assertTrue($manager->requestUpdate('admin@example.com'));

        $this->assertFileExists($this->dir.'/deploy.request');
        $this->assertTrue($manager->isPending());

        // A second request while one is pending is a no-op.
        $this->assertFalse($manager->requestUpdate('admin@example.com'));
    }

    public function test_it_does_not_request_while_a_deploy_is_locked(): void
    {
        file_put_contents($this->dir.'/deploy.lock', 'running');
        $manager = new DeployManager($this->dir);

        $this->assertTrue($manager->isPending());
        $this->assertFalse($manager->requestUpdate());
    }

    public function test_it_reads_the_version_and_last_status(): void
    {
        file_put_contents($this->dir.'/version.json', json_encode(['sha' => 'abc123', 'short' => 'abc123', 'date' => '2026-07-08 10:00']));
        file_put_contents($this->dir.'/deploy.status', json_encode(['state' => 'success', 'message' => 'ok', 'at' => '2026-07-08 10:01']));

        $manager = new DeployManager($this->dir);

        $this->assertSame('abc123', $manager->currentVersion()['short']);
        $this->assertSame('success', $manager->lastStatus()['state']);
    }

    public function test_missing_files_return_null(): void
    {
        $manager = new DeployManager($this->dir);

        $this->assertNull($manager->currentVersion());
        $this->assertNull($manager->lastStatus());
        $this->assertNull($manager->availableUpdate());
    }

    public function test_it_reports_an_available_update_only_when_behind(): void
    {
        $manager = new DeployManager($this->dir);

        // behind > 0 → an update is available.
        file_put_contents($this->dir.'/available.json', json_encode(['behind' => 3, 'short' => 'def456', 'at' => '2026-07-18 20:00']));
        $this->assertSame(3, $manager->availableUpdate()['behind']);

        // behind = 0 → up to date, treated as none.
        file_put_contents($this->dir.'/available.json', json_encode(['behind' => 0]));
        $this->assertNull($manager->availableUpdate());
    }
}
