<?php

namespace Tests\Feature;

use App\Jobs\DrillBackupJob;
use App\Mail\NotificationMail;
use App\Models\Backup;
use App\Models\Customer;
use App\Models\SystemLog;
use App\Services\Backup\BackupDrill;
use App\Services\Backup\BackupRunner;
use App\Services\System\HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Opening the archive is the only thing that proves it is one.
 *
 * Everything else about backups reports on the write — the run finished, the
 * upload succeeded, the size looks about right — and none of that is the
 * question anybody has on the day it matters. These tests defend the one that
 * is: can it be read, and does it hold what it claims.
 */
class BackupDrillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        Storage::fake('local');
        Storage::fake('public');

        config([
            'backup.enabled' => true,
            'backup.disk' => 'backups',
            'backup.path' => 'archives',
            'backup.files' => ['local' => [], 'public' => []],
            'filesystems.disks.backups' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/backups')],
            'backup.allow_local_destination' => true,
        ]);
    }

    private function backup(): Backup
    {
        Customer::factory()->count(2)->create();

        return app(BackupRunner::class)->run();
    }

    /** The whole point: a real archive, read end to end, comes back clean. */
    public function test_a_good_archive_passes_the_drill(): void
    {
        $backup = $this->backup();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertSame([], $report['problems']);
        $this->assertGreaterThan(0, $report['tables']);
        $this->assertGreaterThan(0, $report['rows']);
    }

    /**
     * A destination that truncates large uploads is the failure this exists to
     * catch: the row says "completed", the size looks plausible, and the file
     * only fails to open on the day it is needed.
     */
    public function test_a_truncated_archive_is_caught(): void
    {
        $backup = $this->backup();

        Storage::disk('backups')->put($backup->path, 'PK'.str_repeat('0', 200));

        $this->expectExceptionMessageMatches('/אינו נפתח|מניפסט/u');

        app(BackupDrill::class)->run($backup);
    }

    /**
     * The manifest promises rows; the drill counts them. An archive whose table
     * ends early still opens, and still claims the full number.
     */
    public function test_rows_that_did_not_make_it_into_the_archive_are_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('database/customers.ndjson', "\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertNotSame([], $report['problems']);
        $this->assertStringContainsString('customers', implode(' ', $report['problems']));
    }

    /** A row that cannot be decoded is a row that cannot be restored. */
    public function test_unreadable_rows_are_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('database/customers.ndjson', "not json\nnot json either\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('אינן קריאות', implode(' ', $report['problems']));
    }

    public function test_the_job_records_a_clean_drill_and_says_nothing(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $backup = $this->backup();

        (new DrillBackupJob)->handle(app(BackupDrill::class));

        $this->assertNotNull($backup->fresh()->drilled_at);
        $this->assertSame([], $backup->fresh()->drill_report['problems']);
        Mail::assertNothingSent();
    }

    public function test_the_job_reports_a_failed_drill_to_the_team(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $backup = $this->backup();
        Storage::disk('backups')->delete($backup->path);

        (new DrillBackupJob)->handle(app(BackupDrill::class));

        // Stamped even though it failed: "when did anyone last open one" is the
        // question, and this one was opened.
        $this->assertNotNull($backup->fresh()->drilled_at);
        $this->assertNotSame([], $backup->fresh()->drill_report['problems']);

        Mail::assertSent(fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'לא עבר'));
        $this->assertSame(1, SystemLog::where('source', 'backup')->where('level', 'error')->count());
    }

    /**
     * A backup nobody has opened is a hope, and the health screen says so —
     * as something for a person to act on, not as a system that has stopped.
     */
    public function test_health_reports_a_backup_nobody_has_opened(): void
    {
        $this->backup();

        $report = app(HealthReport::class)->collect();
        $drill = collect($report['checks'])->firstWhere('key', 'drill');

        $this->assertSame(HealthReport::DEGRADED, $drill['status']);

        (new DrillBackupJob)->handle(app(BackupDrill::class));

        $drill = collect(app(HealthReport::class)->collect()['checks'])->firstWhere('key', 'drill');
        $this->assertSame(HealthReport::OK, $drill['status']);
    }

    public function test_a_drill_older_than_the_window_is_reported(): void
    {
        $backup = $this->backup();
        $backup->forceFill(['drilled_at' => now()->subDays(90), 'drill_report' => ['problems' => []]])->save();

        $drill = collect(app(HealthReport::class)->collect()['checks'])->firstWhere('key', 'drill');

        $this->assertSame(HealthReport::DEGRADED, $drill['status']);
    }
}
