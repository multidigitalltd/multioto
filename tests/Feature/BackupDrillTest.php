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
     * A file whose contents are damaged still has a perfectly valid entry in
     * the archive's directory — only reading it through finds that out, and the
     * restore does exactly that before it overwrites the first live file.
     */
    public function test_an_attachment_missing_from_the_archive_is_caught(): void
    {
        Storage::disk('public')->put('logo.png', str_repeat('x', 2048));

        $backup = $this->backup();
        $this->assertGreaterThan(0, $backup->manifest['files']);

        // The list still names it; the member itself is gone — which is what a
        // truncated upload leaves behind, and what a name-only check misses.
        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->deleteName('files/public/logo.png');
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('logo.png', implode(' ', $report['problems']));
    }

    /**
     * The nightly switch turns off the automation. It does not mean the
     * archives already in the bucket stopped mattering, and a button that
     * reports the check started must not quietly do nothing.
     */
    public function test_a_person_can_ask_for_a_drill_with_nightly_backups_off(): void
    {
        $backup = $this->backup();
        config(['backup.enabled' => false]);

        (new DrillBackupJob(manual: true))->handle(app(BackupDrill::class));

        $this->assertNotNull($backup->fresh()->drilled_at);
    }

    public function test_the_scheduled_drill_stays_quiet_when_backups_are_off(): void
    {
        $backup = $this->backup();
        config(['backup.enabled' => false]);

        (new DrillBackupJob)->handle(app(BackupDrill::class));

        $this->assertNull($backup->fresh()->drilled_at);
    }

    /** Valid JSON is not a row: the restore rejects a line that reads "false". */
    public function test_rows_that_are_not_objects_are_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('database/customers.ndjson', "false\n0\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('אינן קריאות', implode(' ', $report['problems']));
    }

    /**
     * The damage nothing else can see.
     *
     * Altered bytes that happen to leave the declared number of parseable rows
     * behind pass every count and every parse — the archive's checksum is the
     * only thing that knows the data is not what was written. The restore reads
     * each member past its last line for exactly that reason, and refuses this
     * archive; a drill that certified it would be the reassurance somebody acts
     * on the day it matters.
     */
    public function test_a_table_whose_checksum_does_not_match_is_caught(): void
    {
        $backup = $this->backup();
        $this->assertSame(2, $backup->manifest['tables']['customers']);

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        // Stored, not deflated, so the bytes below can be edited in place while
        // the checksum written beside them keeps describing the originals.
        $zip->addFromString('database/customers.ndjson', "{\"id\":1,\"name\":\"AAAAAAAA\"}\n{\"id\":2,\"name\":\"AAAAAAAA\"}\n");
        $zip->setCompressionName('database/customers.ndjson', ZipArchive::CM_STORE);
        $zip->close();

        // Same length, same line count, still perfectly valid JSON — and no
        // longer the bytes the archive recorded.
        file_put_contents($path, str_replace('AAAAAAAA', 'BBBBBBBB', file_get_contents($path)));

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('סכום ביקורת', implode(' ', $report['problems']));
    }

    /** A JSON list is not a row either: insert() would read it as columns 0, 1, 2. */
    public function test_rows_that_are_json_lists_are_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('database/customers.ndjson', "[\"x\"]\n[1,2]\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('אינן קריאות', implode(' ', $report['problems']));
    }

    /**
     * A row whose columns this table does not have.
     *
     * insert() is handed exactly those keys and fails on them — with every
     * table already emptied, which is the moment a restore is at its least
     * recoverable. Cheaper to learn about it a month early.
     */
    public function test_rows_naming_columns_the_table_does_not_have_are_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('database/customers.ndjson', "{\"id\":1,\"bogus\":\"x\"}\n{\"id\":2,\"bogus\":\"y\"}\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('bogus', implode(' ', $report['problems']));
    }

    /**
     * The other direction: a column the table requires and the row does not
     * carry. The database has nothing to put there — no value, no default, and
     * NULL refused — so that insert fails just as hard as an unknown column.
     */
    public function test_rows_missing_a_required_column_are_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('database/customers.ndjson', "{\"id\":1}\n{\"id\":2}\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('עמודות חובה', implode(' ', $report['problems']));
        $this->assertStringContainsString('name', implode(' ', $report['problems']));
    }

    /** A column that may be left out is not a problem, and must not be reported as one. */
    public function test_a_good_archive_is_not_faulted_for_columns_a_row_may_omit(): void
    {
        $backup = $this->backup();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertSame([], $report['problems']);
    }

    /**
     * Nothing to check is not the same as checked.
     *
     * A screen that reports "the check started" when no archive exists is how
     * somebody comes to believe one was examined.
     */
    public function test_a_manual_drill_with_no_archive_says_so(): void
    {
        (new DrillBackupJob(manual: true))->handle(app(BackupDrill::class));

        $this->assertSame(1, SystemLog::where('source', 'backup')->where('level', 'warning')->count());
    }

    public function test_the_scheduled_drill_with_no_archive_stays_quiet(): void
    {
        (new DrillBackupJob)->handle(app(BackupDrill::class));

        $this->assertSame(0, SystemLog::where('source', 'backup')->count());
    }

    /**
     * The archive ages without anybody touching it.
     *
     * An ATTACHMENT_DISK changed after a rebuild makes every archive written
     * before it unrestorable — the restore stops outright rather than put a
     * database back without its files. That is precisely the drift a monthly
     * drill exists to find, months before somebody needs the archive.
     */
    public function test_files_from_a_disk_this_installation_no_longer_has_are_reported(): void
    {
        Storage::disk('public')->put('logo.png', str_repeat('x', 512));

        $backup = $this->backup();
        $this->assertGreaterThan(0, $backup->manifest['files']);

        // The rebuild: "public" is no longer one of the disks this installation
        // backs up, so the restore would refuse the archive that names it.
        config(['backup.files' => ['local' => []]]);

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('public', implode(' ', $report['problems']));
        $this->assertStringContainsString('ייעצר', implode(' ', $report['problems']));
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
