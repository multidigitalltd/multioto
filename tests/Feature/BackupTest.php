<?php

namespace Tests\Feature;

use App\Enums\BackupStatus;
use App\Enums\ChargeStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ManageBackups;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\ImportBackupsJob;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\IssueProformaJob;
use App\Jobs\ProcessManualChargeJob;
use App\Jobs\RestoreBackupJob;
use App\Jobs\RunBackupJob;
use App\Jobs\SendPaymentLinkJob;
use App\Mail\NotificationMail;
use App\Models\Backup;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Site;
use App\Models\User;
use App\Services\Backup\BackupArchive;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\OperationGate;
use App\Services\Backup\RestoreClaim;
use App\Services\Billing\DunningMachine;
use App\Services\Billing\ManualChargeService;
use App\Services\Cardcom\CardcomClient;
use App\Services\Linet\InvoiceIssuer;
use App\Services\Linet\ProformaIssuer;
use App\Services\Notifications\TemplateEngine;
use App\Services\Waha\WahaClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * A copy of the business, off the machine that holds it, that can actually be
 * put back.
 *
 * The tests that earn their place here are the ones about trust: that a failed
 * run leaves a visible failure rather than silence, that the archive really
 * contains the rows, that restoring returns exactly what was there, and that
 * restoring into a schema the archive was not taken from is refused rather than
 * half-applied.
 */
class BackupTest extends TestCase
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
        ]);
    }

    private function runBackup(?int $userId = null): Backup
    {
        return app(BackupRunner::class)->run($userId);
    }

    /*
    | ----------------------------------------------------------------
    | Taking a backup
    | ----------------------------------------------------------------
    */

    public function test_a_backup_is_written_to_the_external_destination(): void
    {
        Customer::factory()->count(3)->create();

        $backup = $this->runBackup();

        $this->assertSame(BackupStatus::Completed, $backup->status);
        Storage::disk('backups')->assertExists($backup->path);
        $this->assertGreaterThan(0, $backup->size_bytes);
        $this->assertStringStartsWith('archives/', $backup->path);
    }

    public function test_the_archive_contains_the_rows_and_the_uploaded_files(): void
    {
        Customer::factory()->create(['name' => 'דני כהן']);
        Storage::disk('local')->put('attachments/note.txt', 'תוכן הצרופה');

        $backup = $this->runBackup();

        $local = $this->pullArchive($backup);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($local) === true);

        $rows = (string) $zip->getFromName('database/customers.ndjson');
        $this->assertStringContainsString('דני כהן', $rows);

        $this->assertSame('תוכן הצרופה', $zip->getFromName('files/local/attachments/note.txt'));
        $zip->close();

        $this->assertSame(1, $backup->manifest['tables']['customers']);
        $this->assertSame(1, $backup->fileCount());
    }

    public function test_runtime_tables_are_left_out(): void
    {
        $backup = $this->runBackup();
        $tables = array_keys($backup->manifest['tables']);

        // Queue payloads and sessions describe what the server was doing, not
        // the business — restoring them would resurrect stale jobs.
        $this->assertNotContains('jobs', $tables);
        $this->assertNotContains('sessions', $tables);
        // And the backup history itself, or a restore would delete the list of
        // archives while running from one of them.
        $this->assertNotContains('backups', $tables);
    }

    public function test_a_failed_run_is_recorded_rather_than_silent(): void
    {
        // A destination that does not exist — the realistic misconfiguration.
        config(['backup.disk' => 'nope']);

        try {
            $this->runBackup();
            $this->fail('a broken destination must not pass quietly');
        } catch (\Throwable) {
            // expected
        }

        $backup = Backup::sole();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertNotNull($backup->error);
    }

    public function test_the_button_records_who_pressed_it(): void
    {
        $user = User::factory()->create();

        $manual = $this->runBackup($user->id);
        $nightly = $this->runBackup();

        $this->assertFalse($manual->isAutomatic());
        $this->assertTrue($nightly->isAutomatic());
    }

    public function test_the_job_does_nothing_while_backups_are_switched_off(): void
    {
        config(['backup.enabled' => false]);

        (new RunBackupJob)->handle(app(BackupRunner::class));

        $this->assertSame(0, Backup::count());
    }

    /*
    | ----------------------------------------------------------------
    | Retention
    | ----------------------------------------------------------------
    */

    public function test_old_archives_are_pruned_but_never_the_last_few(): void
    {
        config(['backup.retention_days' => 7, 'backup.keep_at_least' => 2]);

        // Four old archives; the two newest must survive their own age.
        for ($i = 0; $i < 4; $i++) {
            $backup = $this->runBackup();
            Backup::whereKey($backup->id)->update(['created_at' => now()->subDays(30)]);
        }

        app(BackupRunner::class)->prune();

        // The floor protects the two newest completed archives; a retention
        // window set too tight must never leave the business with nothing.
        $this->assertSame(2, Backup::count());
    }

    public function test_retention_keeps_the_newest_archives_by_date_not_by_id(): void
    {
        config(['backup.retention_days' => 1, 'backup.keep_at_least' => 1]);

        $first = $this->runBackup();
        $second = $this->runBackup();

        // Rows adopted from the destination after a rebuild are dated by the
        // file they describe, and the ids follow whatever order the bucket
        // listed them in — here the LOWER id is the newer archive.
        Backup::whereKey($first->id)->update(['created_at' => now()->subDays(10)]);
        Backup::whereKey($second->id)->update(['created_at' => now()->subDays(40)]);

        app(BackupRunner::class)->prune();

        $this->assertNotNull(Backup::find($first->id));
        $this->assertNull(Backup::find($second->id));
    }

    public function test_a_row_whose_archive_is_gone_does_not_fill_the_retention_floor(): void
    {
        config(['backup.retention_days' => 1, 'backup.keep_at_least' => 1]);

        $real = $this->runBackup();
        $ghost = $this->runBackup();

        // Removed at the destination by an operator or a bucket lifecycle rule.
        Storage::disk('backups')->delete($ghost->path);
        Backup::query()->update(['created_at' => now()->subDays(30)]);

        app(BackupRunner::class)->prune();

        // The floor is meant to guarantee a recovery point. A row pointing at
        // nothing is not one, and must not stand in for the last real archive.
        $this->assertNotNull(Backup::find($real->id));
        Storage::disk('backups')->assertExists($real->path);
    }

    public function test_retention_does_not_delete_an_archive_no_scan_has_read_yet(): void
    {
        config(['backup.retention_days' => 1, 'backup.keep_at_least' => 1]);

        $this->runBackup();
        Storage::disk('backups')->put('archives/from-the-old-server.zip', 'תוכן שלא נקרא');

        $unread = Backup::create([
            'status' => BackupStatus::Failed,
            'disk' => 'backups',
            'path' => 'archives/from-the-old-server.zip',
            'error' => BackupRunner::IMPORT_UNREADABLE,
        ]);
        Backup::whereKey($unread->id)->update(['created_at' => now()->subDays(30)]);

        app(BackupRunner::class)->prune();

        // It is dated by the file it describes, so after a rebuild it is old
        // enough to prune on sight — and it may be a perfectly good recovery
        // point that one dropped connection made look corrupt.
        $this->assertNotNull(Backup::find($unread->id));
        Storage::disk('backups')->assertExists('archives/from-the-old-server.zip');
    }

    public function test_the_screen_survives_a_destination_that_cannot_answer(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $backup = $this->runBackup();

        $disk = \Mockery::mock(Storage::disk('backups'))->makePartial();
        $disk->shouldReceive('exists')->andThrow(new \RuntimeException('S3 unreachable'));
        Storage::set('backups', $disk);

        // A broken destination is exactly what the operator came to this screen
        // to fix; it must not be the thing that stops the screen from opening.
        Livewire::test(ManageBackups::class)->assertOk();

        $this->assertStringContainsString(
            'לא ניתן להגיע ליעד האחסון',
            (string) app(BackupRestorer::class)->blockedReason($backup),
        );
    }

    public function test_retention_leaves_an_archive_claimed_for_restore_alone(): void
    {
        config(['backup.retention_days' => 1, 'backup.keep_at_least' => 1]);

        $keep = $this->runBackup();
        $claimed = $this->runBackup();
        Backup::whereKey($claimed->id)->update([
            'created_at' => now()->subDays(30),
            'restore_status' => BackupStatus::Running,
        ]);

        // The restore job may still be queued behind the backup that triggered
        // this pass; deleting the archive now would leave it with nothing to
        // restore from and no explanation.
        $this->assertSame(0, app(BackupRunner::class)->prune());

        $this->assertNotNull(Backup::find($claimed->id));
        $this->assertNotNull(Backup::find($keep->id));
        Storage::disk('backups')->assertExists($claimed->path);
    }

    /*
    | ----------------------------------------------------------------
    | Putting it back
    | ----------------------------------------------------------------
    */

    public function test_restoring_brings_back_what_was_there(): void
    {
        $customer = Customer::factory()->create(['name' => 'לקוח מהגיבוי']);
        Storage::disk('local')->put('attachments/keep.txt', 'קובץ מהגיבוי');

        $backup = $this->runBackup();

        // Everything changes after the backup.
        Customer::query()->delete();
        Storage::disk('local')->delete('attachments/keep.txt');
        Customer::factory()->create(['name' => 'לקוח שנוצר אחרי']);

        app(BackupRestorer::class)->restore($backup);

        $this->assertSame(1, Customer::count());
        $this->assertSame('לקוח מהגיבוי', Customer::sole()->name);
        $this->assertSame($customer->id, Customer::sole()->id);
        $this->assertSame('קובץ מהגיבוי', Storage::disk('local')->get('attachments/keep.txt'));
        $this->assertSame(BackupStatus::Completed, $backup->fresh()->restore_status);
    }

    public function test_restoring_puts_related_rows_back_in_an_order_the_keys_allow(): void
    {
        // A site points at a customer: restoring the child before the parent
        // would fail on the foreign key.
        $site = Site::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $backup = $this->runBackup();

        Site::query()->delete();
        Customer::query()->delete();

        app(BackupRestorer::class)->restore($backup);

        $this->assertSame(1, Site::count());
        $this->assertSame($site->customer_id, Site::sole()->customer_id);
    }

    /**
     * A customer points at their default payment token and the token points
     * back at the customer. No insert order satisfies both, so this is the
     * ordinary saved-card state failing to restore at all — the case the first
     * round trip missed because its customers had no card.
     */
    public function test_a_customer_with_a_saved_card_restores(): void
    {
        $customer = Customer::factory()->create();
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $customer->update(['default_token_id' => $token->id]);

        $backup = $this->runBackup();

        PaymentToken::query()->update(['customer_id' => $customer->id]);
        Customer::query()->update(['default_token_id' => null]);
        PaymentToken::query()->delete();
        Customer::query()->delete();

        app(BackupRestorer::class)->restore($backup);

        $this->assertSame($token->id, Customer::sole()->default_token_id);
        $this->assertSame($customer->id, PaymentToken::sole()->customer_id);
    }

    public function test_an_archive_missing_a_table_is_refused_before_anything_is_deleted(): void
    {
        Customer::factory()->count(2)->create();
        $backup = $this->runBackup();

        $this->corruptArchive($backup, fn (ZipArchive $zip) => $zip->deleteName('database/customers.ndjson'));

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a corrupt archive must not be restored');
        } catch (\Throwable) {
            // expected
        }

        // Nothing was emptied on the way to finding out.
        $this->assertSame(2, Customer::count());
    }

    public function test_a_truncated_table_is_refused_rather_than_restored_short(): void
    {
        Customer::factory()->count(3)->create();
        $backup = $this->runBackup();

        // One row lost in transit — the manifest still says three.
        $this->corruptArchive($backup, function (ZipArchive $zip): void {
            $lines = explode("\n", trim((string) $zip->getFromName('database/customers.ndjson')));
            array_pop($lines);
            $zip->addFromString('database/customers.ndjson', implode("\n", $lines)."\n");
        });

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a short table must not restore quietly');
        } catch (\Throwable) {
            // expected
        }

        // The transaction rolled back, so the live data is untouched.
        $this->assertSame(3, Customer::count());
    }

    public function test_a_public_destination_is_refused(): void
    {
        // The archive's name is predictable and it holds every customer record.
        config(['backup.disk' => 'public']);

        $this->expectException(\RuntimeException::class);

        $this->runBackup();
    }

    public function test_a_disk_that_is_itself_backed_up_is_refused_as_the_destination(): void
    {
        // It lives on this server, so it is not disaster recovery — and each
        // run would archive the previous archives, for ever.
        config(['backup.disk' => 'local']);

        $this->expectException(\RuntimeException::class);

        $this->runBackup();
    }

    public function test_a_file_missing_from_the_archive_fails_the_restore(): void
    {
        Storage::disk('local')->put('attachments/keep.txt', 'קובץ');
        $backup = $this->runBackup();

        $this->corruptArchive($backup, fn (ZipArchive $zip) => $zip->deleteName('files/local/attachments/keep.txt'));

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a missing attachment must not restore quietly');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
    }

    public function test_a_backup_will_not_start_while_a_restore_holds_the_lock(): void
    {
        $lock = Cache::lock(RunBackupJob::LOCK, 60);
        $this->assertTrue($lock->get());

        // Reading rows from one state and files from another would produce an
        // archive that looks fine and is internally inconsistent — so no
        // archive is written at all.
        (new RunBackupJob)->handle(app(BackupRunner::class));

        $this->assertSame([], Storage::disk('backups')->allFiles());
        $this->assertSame(BackupStatus::Failed, Backup::sole()->status);

        $lock->release();
    }

    public function test_a_misconfigured_destination_still_leaves_a_failed_row_and_an_alert(): void
    {
        Mail::fake();
        config([
            'backup.disk' => 'public',
            'billing.notifications.team_email' => 'team@multi.test',
        ]);

        try {
            $this->runBackup();
            $this->fail('a public destination must not pass');
        } catch (\Throwable) {
            // expected
        }

        // The nightly run must never disappear without a trace.
        $this->assertSame(BackupStatus::Failed, Backup::sole()->status);
        Mail::assertSent(NotificationMail::class);
    }

    public function test_a_restore_blocked_by_the_lock_says_so_instead_of_vanishing(): void
    {
        $backup = $this->runBackup();
        $backup->update(['restore_status' => BackupStatus::Running]);

        $lock = Cache::lock(RunBackupJob::LOCK, 60);
        $this->assertTrue($lock->get());

        // The panel already promised the operator that the restore started.
        (new RestoreBackupJob($backup->id))->handle(app(BackupRestorer::class));

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
        $this->assertStringContainsString('נסו שוב', (string) $backup->fresh()->restore_error);

        $lock->release();
    }

    public function test_a_killed_worker_does_not_leave_a_backup_stuck_on_running(): void
    {
        $job = new RunBackupJob;

        // Stamped with the run it belongs to, exactly as the runner stamps it:
        // the failure handler looks for ITS row, not for the latest one.
        Backup::create(['status' => BackupStatus::Running, 'disk' => 'backups',
            'path' => 'archives/x.zip', 'run_attempt' => $job->attempt]);

        $job->failed(new \RuntimeException('worker timed out'));

        $this->assertSame(BackupStatus::Failed, Backup::sole()->status);
        $this->assertNotNull(Backup::sole()->error);
    }

    public function test_an_archive_without_its_file_list_is_refused(): void
    {
        Storage::disk('local')->put('attachments/keep.txt', 'קובץ');
        $backup = $this->runBackup();

        // Losing the list must not silently downgrade to "whatever survived".
        $this->corruptArchive($backup, fn (ZipArchive $zip) => $zip->deleteName('files.json'));

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('an archive without its file list must not restore');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
    }

    /**
     * Files uploaded after the backup are left alone — deliberately. Deleting
     * them cannot be made safe: SignupController writes its signature to disk
     * BEFORE the insert a restore blocks on, so a cleanup would delete a file
     * whose row is committed moments later. An orphan costs disk; a wrongly
     * deleted signature is gone.
     */
    public function test_restoring_overwrites_archived_files_and_leaves_newer_ones(): void
    {
        Storage::disk('local')->put('attachments/old.txt', 'היה בגיבוי');
        $backup = $this->runBackup();

        Storage::disk('local')->put('attachments/old.txt', 'שונה אחרי');
        Storage::disk('local')->put('attachments/new.txt', 'הועלה אחרי');

        app(BackupRestorer::class)->restore($backup);

        $this->assertSame('היה בגיבוי', Storage::disk('local')->get('attachments/old.txt'));
        Storage::disk('local')->assertExists('attachments/new.txt');
    }

    public function test_a_backup_blocked_by_the_lock_leaves_a_failed_row(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@multi.test']);

        $lock = Cache::lock(RunBackupJob::LOCK, 60);
        $this->assertTrue($lock->get());

        (new RunBackupJob)->handle(app(BackupRunner::class));

        // A night that produced no copy of the business must not be silent.
        $this->assertSame(BackupStatus::Failed, Backup::sole()->status);
        Mail::assertSent(NotificationMail::class);

        $lock->release();
    }

    public function test_a_timeout_failure_also_emails_the_team(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@multi.test']);

        $job = new RunBackupJob;

        Backup::create(['status' => BackupStatus::Running, 'disk' => 'backups',
            'path' => 'archives/x.zip', 'run_attempt' => $job->attempt]);

        $job->failed(new \RuntimeException('worker timed out'));

        // A status quietly flipped in the database is not a notification.
        $this->assertSame(BackupStatus::Failed, Backup::sole()->status);
        Mail::assertSent(NotificationMail::class);
    }

    public function test_two_backups_in_the_same_second_do_not_share_a_path(): void
    {
        Carbon::setTestNow('2026-08-10 03:30:00');

        $first = $this->runBackup();
        $second = $this->runBackup();

        // Sharing a path would have the second overwrite the first, and pruning
        // either would delete the object the other still points at.
        $this->assertNotSame($first->path, $second->path);
        Storage::disk('backups')->assertExists($first->path);
        Storage::disk('backups')->assertExists($second->path);
    }

    public function test_a_backup_in_flight_cannot_be_deleted(): void
    {
        $running = Backup::create(['status' => BackupStatus::Running, 'disk' => 'backups', 'path' => 'archives/x.zip']);
        $done = $this->runBackup();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(ManageBackups::class)
            ->assertTableActionHidden('delete', $running)
            ->assertTableActionVisible('delete', $done);
    }

    /**
     * A file too large to archive is recorded as skipped, not backed up — and
     * its row IS restored, still pointing at it. Deleting it as "not in the
     * archive" would destroy customer data no backup ever held.
     */
    public function test_restoring_keeps_a_file_the_backup_deliberately_skipped(): void
    {
        Storage::disk('local')->put('attachments/huge.bin', str_repeat('x', 2048));
        Storage::disk('local')->put('attachments/small.txt', 'קטן');

        config(['backup.max_file_bytes' => 1024]);

        $backup = $this->runBackup();
        $this->assertNotEmpty($backup->manifest['skipped_files']);

        app(BackupRestorer::class)->restore($backup);

        Storage::disk('local')->assertExists('attachments/huge.bin');
        Storage::disk('local')->assertExists('attachments/small.txt');
    }

    public function test_a_backup_that_left_files_out_says_so_and_tells_the_team(): void
    {
        Mail::fake();
        config([
            'backup.max_file_bytes' => 1024,
            'billing.notifications.team_email' => 'team@multi.test',
        ]);

        Storage::disk('local')->put('attachments/huge.bin', str_repeat('x', 2048));

        $backup = $this->runBackup();

        // The archive is still worth keeping, so it is not a failure — but the
        // rows pointing at that file ARE backed up, so a restore would recreate
        // a reference to a file no archive ever held.
        $this->assertSame(BackupStatus::Completed, $backup->status);
        $this->assertCount(1, $backup->manifest['skipped_files']);
        Mail::assertSent(NotificationMail::class);
    }

    public function test_a_second_restore_cannot_be_started_while_one_is_running(): void
    {
        $backup = $this->runBackup();
        $backup->update(['restore_status' => BackupStatus::Running]);

        // The second would finish AFTER the first and put the same old snapshot
        // back, wiping everything accepted in between.
        $this->assertNotNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));
    }

    public function test_a_corrupt_file_member_is_caught_before_any_live_file_is_touched(): void
    {
        Storage::disk('local')->put('attachments/a.txt', 'מקורי');
        $backup = $this->runBackup();

        Storage::disk('local')->put('attachments/a.txt', 'שונה אחרי');
        $this->corruptArchive($backup, fn (ZipArchive $zip) => $zip->deleteName('files/local/attachments/a.txt'));

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a corrupt archive must not be restored');
        } catch (\Throwable) {
            // expected
        }

        // File writes cannot be rolled back, so the check has to happen first.
        $this->assertSame('שונה אחרי', Storage::disk('local')->get('attachments/a.txt'));
    }

    public function test_archives_at_the_destination_are_found_again_after_the_database_is_lost(): void
    {
        Customer::factory()->create(['name' => 'לקוח מהגיבוי']);
        $original = $this->runBackup();

        // The disaster the whole feature exists for: the server and its
        // database are gone, and only the bucket is left. The history table is
        // deliberately not inside the archive, so it cannot bring itself back.
        Backup::query()->delete();

        $found = app(BackupRunner::class)->importFromDisk();

        $this->assertSame(1, $found['imported']);

        $imported = Backup::sole();
        $this->assertSame($original->path, $imported->path);
        $this->assertSame(BackupStatus::Completed, $imported->status);
        $this->assertNull(app(BackupRestorer::class)->blockedReason($imported));

        // And it really is restorable, which is the only thing that matters.
        Customer::query()->delete();
        app(BackupRestorer::class)->restore($imported);
        $this->assertSame('לקוח מהגיבוי', Customer::sole()->name);
    }

    public function test_a_second_scan_does_not_list_the_same_archive_twice(): void
    {
        $this->runBackup();

        app(BackupRunner::class)->importFromDisk();
        $found = app(BackupRunner::class)->importFromDisk();

        $this->assertSame(0, $found['imported']);
        $this->assertSame(1, Backup::count());
    }

    public function test_an_unreadable_archive_at_the_destination_is_listed_as_failed(): void
    {
        Storage::disk('backups')->put('archives/not-really-a-backup.zip', 'לא ארכיון');

        $found = app(BackupRunner::class)->importFromDisk();

        // Listed rather than hidden: it is taking up paid storage, and someone
        // has to be able to see it in order to delete it.
        $this->assertSame(1, $found['unreadable']);
        $this->assertSame(BackupStatus::Failed, Backup::sole()->status);
    }

    public function test_an_archive_that_could_not_be_read_once_is_tried_again(): void
    {
        Customer::factory()->create(['name' => 'לקוח מהגיבוי']);
        $backup = $this->runBackup();
        $path = $backup->path;
        Backup::query()->delete();

        // A dropped connection or a full temp disk makes a perfectly good
        // archive look corrupt.
        $healthy = Storage::disk('backups');
        $broken = \Mockery::mock($healthy)->makePartial();
        $broken->shouldReceive('readStream')->andReturn(false);
        Storage::set('backups', $broken);

        $this->assertSame(1, app(BackupRunner::class)->importFromDisk()['unreadable']);
        $this->assertSame(BackupStatus::Failed, Backup::sole()->status);

        // Second scan, storage healthy again: skipping it for ever would leave
        // the business unable to restore from an archive sitting right there.
        Storage::set('backups', $healthy);

        $this->assertSame(1, app(BackupRunner::class)->importFromDisk()['imported']);

        $repaired = Backup::sole();
        $this->assertSame($path, $repaired->path);
        $this->assertSame(BackupStatus::Completed, $repaired->status);
        $this->assertNull(app(BackupRestorer::class)->blockedReason($repaired));
    }

    public function test_two_scans_at_once_cannot_both_adopt_the_same_archive(): void
    {
        $backup = $this->runBackup();
        $path = $backup->path;
        Backup::query()->delete();

        app(BackupRunner::class)->importFromDisk();

        // As if a second administrator's scan had read the (empty) list before
        // the first one saved: deleting either row would take the file out
        // from under the other.
        $duplicate = new Backup(['status' => BackupStatus::Completed, 'disk' => 'backups', 'path' => $path]);

        $this->expectException(UniqueConstraintViolationException::class);
        $duplicate->save();
    }

    public function test_a_scan_that_read_the_archive_wins_over_one_that_could_not(): void
    {
        $backup = $this->runBackup();
        $path = $backup->path;
        Backup::query()->delete();

        // As if another scan, whose read failed, saved its row in the moment
        // between this one checking the list and saving its own.
        $racer = function () use ($path): void {
            DB::table('backups')->insert([
                'status' => BackupStatus::Failed->value,
                'disk' => 'backups',
                'path' => $path,
                'error' => BackupRunner::IMPORT_UNREADABLE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        Backup::creating(function () use (&$racer): void {
            if ($racer !== null) {
                ($racer)();
                $racer = null;
            }
        });

        app(BackupRunner::class)->importFromDisk();

        // One row, and it is the readable one — a working archive must not be
        // lost because someone else's connection dropped.
        $adopted = Backup::sole();
        $this->assertSame(BackupStatus::Completed, $adopted->status);
        $this->assertNull(app(BackupRestorer::class)->blockedReason($adopted));
    }

    public function test_a_restore_does_not_turn_manual_backups_into_automatic_ones(): void
    {
        $user = User::factory()->create();
        $backup = $this->runBackup($user->id);

        // The history table is not restored, but its rows point at users that
        // are — and deleting those users nulls the reference on the way past.
        app(BackupRestorer::class)->restore($backup);

        $this->assertFalse($backup->fresh()->isAutomatic());
        $this->assertSame($user->id, $backup->fresh()->user_id);
    }

    public function test_a_stale_payload_cannot_start_after_the_claim_moved_on(): void
    {
        $backup = $this->runBackup();
        Customer::factory()->create(['name' => 'לקוח שהתקבל אחרי']);

        // The claim was taken over and the replacement restore already
        // finished; the lost payload arrives now.
        $backup->update([
            'restore_status' => BackupStatus::Completed,
            'restore_attempt' => 'the-new-one',
            'restore_started_at' => now(),
            'restored_at' => now(),
        ]);

        (new RestoreBackupJob($backup->id, 'the-lost-one'))->handle(app(BackupRestorer::class));

        $this->assertSame(1, Customer::where('name', 'לקוח שהתקבל אחרי')->count());
    }

    public function test_a_payload_without_an_attempt_id_cannot_take_a_claim_that_has_one(): void
    {
        $backup = $this->runBackup();
        Customer::factory()->create(['name' => 'לקוח שהתקבל אחרי']);

        // A payload queued before the attempt ids existed, arriving after a
        // fresh claim was made with one.
        $backup->update([
            'restore_status' => BackupStatus::Running,
            'restore_attempt' => 'the-new-one',
            'restore_started_at' => null,
        ]);

        // Rebuilt the way the queue rebuilds it — without the constructor, and
        // without a property that did not exist when it was written.
        $legacy = unserialize('O:25:"App\Jobs\RestoreBackupJob":1:{s:8:"backupId";i:'.$backup->id.';}');
        $this->assertNull($legacy->attempt);

        $legacy->handle(app(BackupRestorer::class));

        $this->assertSame(1, Customer::where('name', 'לקוח שהתקבל אחרי')->count());
    }

    public function test_a_second_restore_of_the_same_archive_can_still_be_recorded_as_failed(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        Queue::fake([RestoreBackupJob::class]);

        $backup = $this->runBackup();
        $backup->update(['restore_status' => BackupStatus::Completed, 'restored_at' => now()->subHour()]);

        Livewire::test(ManageBackups::class)
            ->callTableAction('restore', $backup, ['confirm' => config('backup.restore_confirmation')]);

        // The previous attempt's completion mark would otherwise stop the
        // failure handler from recording THIS attempt going wrong, and the
        // claim would sit on "running" with nothing able to clear it.
        $this->assertNull($backup->fresh()->restored_at);

        (new RestoreBackupJob($backup->id, $backup->fresh()->restore_attempt))
            ->failed(new \RuntimeException('the worker died'));

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
    }

    public function test_money_jobs_hold_themselves_while_a_restore_is_running(): void
    {
        Queue::fake([ChargeSubscriptionJob::class, IssueInvoiceJob::class]);

        $backup = $this->runBackup();
        $backup->update(['restore_status' => BackupStatus::Running]);

        // A charge that lands while a restore replaces the row recording it
        // leaves the customer charged with nothing to show for it — the one
        // outcome no later repair can undo.
        (new ChargeSubscriptionJob(1))->handle(app(CardcomClient::class), app(DunningMachine::class));
        (new IssueInvoiceJob(1))->handle(app(InvoiceIssuer::class));

        Queue::assertPushed(ChargeSubscriptionJob::class);
        Queue::assertPushed(IssueInvoiceJob::class);
    }

    public function test_a_manual_charge_and_a_panel_invoice_wait_for_the_restore_too(): void
    {
        Queue::fake([ProcessManualChargeJob::class]);

        $backup = $this->runBackup();
        $backup->update(['restore_status' => BackupStatus::Running]);

        (new ProcessManualChargeJob(1))->handle(app(CardcomClient::class));
        Queue::assertPushed(ProcessManualChargeJob::class);

        // The panel's "issue invoice" button calls the service directly, so the
        // guard has to live there and not only in the job.
        $customer = Customer::factory()->create();
        $charge = Charge::create([
            'customer_id' => $customer->id,
            'status' => ChargeStatus::Succeeded,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'attempt_number' => 1,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'description' => 'חיוב ידני',
        ]);

        $result = app(InvoiceIssuer::class)->issue($charge);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('גיבוי או שחזור', (string) $result['error']);
    }

    public function test_a_payment_link_and_a_proforma_wait_for_the_restore_too(): void
    {
        Queue::fake([SendPaymentLinkJob::class, IssueProformaJob::class]);

        $backup = $this->runBackup();
        $backup->update(['restore_status' => BackupStatus::Running]);

        $customer = Customer::factory()->create();

        // A payment page is payable the moment it exists; a proforma is emailed
        // and its id lives in a row the restore is about to replace.
        (new SendPaymentLinkJob($customer->id, 11800, 'דרישת תשלום', 'email'))
            ->handle(app(ManualChargeService::class), app(TemplateEngine::class), app(WahaClient::class));
        (new IssueProformaJob(1))->handle(app(ProformaIssuer::class));

        Queue::assertPushed(SendPaymentLinkJob::class);
        Queue::assertPushed(IssueProformaJob::class);

        // And the services refuse directly too, for the panel paths that call
        // them without a queue.
        $this->expectException(\RuntimeException::class);
        app(ManualChargeService::class)->createHostedPage($customer, 11800, 'דרישת תשלום');
    }

    public function test_a_restore_names_the_payments_and_documents_it_discards(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@multi.test']);

        $backup = $this->runBackup();

        // Created after the archive: Cardcom can still take the money for this
        // page, and the row that says whose payment it is goes away.
        $customer = Customer::factory()->create();
        Charge::create([
            'customer_id' => $customer->id,
            'status' => ChargeStatus::Pending,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'attempt_number' => 1,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'cardcom_low_profile_id' => 'lp-still-payable',
        ]);

        app(BackupRestorer::class)->restore($backup);

        // The restore cannot recall the page — but it must not let it vanish
        // in silence either, and the whole list has to survive the restore
        // that caused it.
        Mail::assertSent(NotificationMail::class);

        $report = $backup->fresh()->restore_report;
        $this->assertSame(1, $report['count']);
        $this->assertStringContainsString('lp-still-payable', implode(' ', $report['items']));
    }

    public function test_a_reconciliation_that_ran_out_of_room_says_so_even_when_it_found_nothing(): void
    {
        Mail::fake();
        config([
            'billing.notifications.team_email' => 'team@multi.test',
            // Three archived references fill a ceiling of two (the collector
            // reads one past it to tell "finished" from "ran out"). On a real
            // install the same thing happens with 5,000 older payments that are
            // all safely in the archive.
            'backup.reconcile_limit' => 2,
        ]);

        $customer = Customer::factory()->create();

        foreach (['tx-old-one', 'tx-old-two', 'tx-old-three'] as $reference) {
            Charge::create([
                'customer_id' => $customer->id,
                'status' => ChargeStatus::Succeeded,
                'amount_agorot' => 10000,
                'vat_agorot' => 1800,
                'total_agorot' => 11800,
                'attempt_number' => 1,
                'period_start' => now()->startOfMonth(),
                'period_end' => now()->endOfMonth(),
                'cardcom_transaction_id' => $reference,
            ]);
        }

        // Both are in the archive, so the scan confirms nothing missing — and
        // fills its quota on them before it ever reaches the one below.
        $backup = $this->runBackup();

        Charge::create([
            'customer_id' => $customer->id,
            'status' => ChargeStatus::Pending,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'attempt_number' => 1,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'cardcom_low_profile_id' => 'lp-never-reached',
        ]);

        app(BackupRestorer::class)->restore($backup);

        // "Found none" and "never looked" are not the same answer. Staying quiet
        // here would tell the team the money is accounted for at exactly the
        // moment the check could not see the newest payment.
        $report = $backup->fresh()->restore_report;
        $this->assertNotNull($report);
        $this->assertTrue($report['truncated']);
        $this->assertSame(0, $report['count']);
        Mail::assertSent(NotificationMail::class);
    }

    public function test_a_scan_that_examined_everything_is_not_called_truncated(): void
    {
        Mail::fake();
        config([
            'billing.notifications.team_email' => 'team@multi.test',
            'backup.reconcile_limit' => 2,
        ]);

        $customer = Customer::factory()->create();

        foreach (['tx-one', 'tx-two'] as $reference) {
            Charge::create([
                'customer_id' => $customer->id,
                'status' => ChargeStatus::Succeeded,
                'amount_agorot' => 10000,
                'vat_agorot' => 1800,
                'total_agorot' => 11800,
                'attempt_number' => 1,
                'period_start' => now()->startOfMonth(),
                'period_end' => now()->endOfMonth(),
                'cardcom_transaction_id' => $reference,
            ]);
        }

        $backup = $this->runBackup();

        app(BackupRestorer::class)->restore($backup);

        // Exactly at the ceiling means every reference WAS examined. Calling
        // that "may be incomplete" would send the team to audit Cardcom and
        // Linet by hand over a scan that finished.
        $this->assertNull($backup->fresh()->restore_report);
        Mail::assertNothingSent();
    }

    public function test_a_restore_does_not_report_the_payments_it_puts_back(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@multi.test']);

        $customer = Customer::factory()->create();
        Charge::create([
            'customer_id' => $customer->id,
            'status' => ChargeStatus::Succeeded,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'attempt_number' => 1,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'cardcom_transaction_id' => 'tx-in-the-archive',
        ]);

        // In the archive, so the restore puts it straight back.
        $backup = $this->runBackup();

        app(BackupRestorer::class)->restore($backup);

        // Naming it would bury the handful that genuinely went missing, and an
        // alert nobody reads is the same as no alert.
        $this->assertNull($backup->fresh()->restore_report);
        Mail::assertNothingSent();
    }

    public function test_a_forgotten_running_row_does_not_stop_billing_for_ever(): void
    {
        config(['backup.operation_window_minutes' => 60]);

        // A worker that vanished mid-run, or a database that went away during
        // its failure bookkeeping.
        $abandoned = $this->runBackup();
        Backup::whereKey($abandoned->id)->update([
            'status' => BackupStatus::Running,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        // Holding every charge behind it would stop the business billing
        // altogether — a far worse outcome than one unguarded charge.
        $this->assertFalse(app(OperationGate::class)->isRunning());
    }

    public function test_a_superseded_payload_cannot_cancel_the_claim_that_replaced_it(): void
    {
        $backup = $this->runBackup();

        $backup->update([
            'restore_status' => BackupStatus::Running,
            'restore_attempt' => 'the-new-one',
            'restore_queued_at' => now(),
            'restore_started_at' => null,
        ]);

        // The superseded payload throws on its way to the claim check — a
        // database blip, say — and its failure handler runs.
        (new RestoreBackupJob($backup->id, 'the-lost-one'))->failed(new \RuntimeException('blip'));

        // Cancelling the current attempt would leave its valid payload with
        // nothing to restore into, or make a running restore look reclaimable.
        $this->assertSame(BackupStatus::Running, $backup->fresh()->restore_status);
        $this->assertSame('the-new-one', $backup->fresh()->restore_attempt);
    }

    public function test_a_redelivered_restore_job_does_not_run_a_second_time(): void
    {
        $customer = Customer::factory()->create(['name' => 'לקוח מהגיבוי']);
        $backup = $this->runBackup();

        $backup->update(['restore_status' => BackupStatus::Running]);
        (new RestoreBackupJob($backup->id))->handle(app(BackupRestorer::class));

        // Accepted after the restore finished — a worker that died before
        // acknowledging its payload gets the same job again, and running it
        // twice would wipe this.
        $after = Customer::factory()->create(['name' => 'לקוח שהתקבל אחרי השחזור']);

        (new RestoreBackupJob($backup->id))->handle(app(BackupRestorer::class));

        $this->assertNotNull(Customer::find($after->id));
        $this->assertNotNull(Customer::find($customer->id));
    }

    public function test_the_sequences_are_left_alone_when_the_restore_does_not_commit(): void
    {
        Storage::disk('local')->put('attachments/keep.txt', 'קובץ מהגיבוי');
        $backup = $this->runBackup();

        // The last thing a restore does before committing is write the files.
        $disk = \Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->andReturn(false);
        Storage::set('local', $disk);

        $restorer = new class(app(BackupArchive::class)) extends BackupRestorer
        {
            public bool $sequencesReset = false;

            protected function resetSequences(array $tables): void
            {
                $this->sequencesReset = true;
            }
        };

        try {
            $restorer->restore($backup);
            $this->fail('a failed file write must not restore quietly');
        } catch (\Throwable) {
            // expected
        }

        // setval() does not roll back with the transaction: rewinding the
        // sequences and then losing the rows they were rewound for would make
        // every following insert in production collide with a live primary key.
        $this->assertFalse($restorer->sequencesReset);
    }

    public function test_a_corrupt_table_payload_is_caught_before_a_single_row_is_deleted(): void
    {
        Customer::factory()->create(['name' => 'לקוח חי']);
        $backup = $this->runBackup();

        // A bit flip inside a row leaves the line structure intact, so the row
        // count still matches — the checksum is the only thing that notices.
        $this->corruptPayload($backup, 'database/customers.ndjson');

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a damaged table member must not be restored');
        } catch (\Throwable) {
            // expected
        }

        // Caught before the transaction, so the live rows were never touched.
        $this->assertSame('לקוח חי', Customer::sole()->name);
    }

    public function test_a_corrupt_file_payload_is_caught_before_any_live_file_is_touched(): void
    {
        Storage::disk('local')->put('attachments/a.txt', 'מקורי א');
        Storage::disk('local')->put('attachments/b.txt', 'מקורי ב');
        $backup = $this->runBackup();

        Storage::disk('local')->put('attachments/a.txt', 'שונה אחרי');
        Storage::disk('local')->put('attachments/b.txt', 'שונה אחרי');

        // Unlike a missing member, a damaged payload still has a perfectly
        // valid directory entry: opening it succeeds and only reading it fails.
        $this->corruptPayload($backup, 'files/local/attachments/b.txt');

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a damaged payload must not be restored');
        } catch (\Throwable) {
            // expected
        }

        // Neither one — including the file listed BEFORE the damaged member,
        // which is the one a mid-way failure would already have overwritten.
        $this->assertSame('שונה אחרי', Storage::disk('local')->get('attachments/a.txt'));
        $this->assertSame('שונה אחרי', Storage::disk('local')->get('attachments/b.txt'));
    }

    public function test_a_file_that_arrives_short_is_not_passed_off_as_backed_up(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@multi.test']);
        Storage::disk('local')->put('attachments/big.bin', str_repeat('x', 4096));

        // The source is replaced mid-read: the stream simply ends early and
        // reports no error at all.
        $disk = \Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('readStream')->andReturnUsing(function (): mixed {
            $handle = fopen('php://temp', 'r+');
            fwrite($handle, str_repeat('x', 100));
            rewind($handle);

            return $handle;
        });
        Storage::set('local', $disk);

        $backup = $this->runBackup();

        // Kept out and reported, rather than archived at the wrong length with
        // a valid checksum over it — which a restore would put back in silence.
        $this->assertContains('local:attachments/big.bin', $backup->manifest['skipped_files']);
        $this->assertSame(0, $backup->fileCount());
        Mail::assertSent(NotificationMail::class);
    }

    public function test_no_backup_for_too_long_is_reported_even_though_nothing_failed(): void
    {
        Mail::fake();
        config([
            'billing.notifications.team_email' => 'team@multi.test',
            'backup.stale_after_hours' => 36,
        ]);

        $backup = $this->runBackup();
        Backup::whereKey($backup->id)->update(['created_at' => now()->subDays(3)]);

        // Nothing failed: a queue with no worker, or a scheduler nobody
        // restarted, leaves no failed row at all — and that silence looks
        // exactly like a healthy night.
        app(BackupRunner::class)->alertIfStale();

        Mail::assertSent(NotificationMail::class);
    }

    public function test_the_screen_warns_about_a_missing_backup_without_any_background_process(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        config(['backup.stale_after_hours' => 36]);

        $backup = $this->runBackup();
        Backup::whereKey($backup->id)->update(['created_at' => now()->subDays(3)]);

        // The nightly alert runs from the scheduler, and a scheduler that has
        // stopped cannot report itself. Someone opening the page is the one
        // path that depends on no background process at all.
        Livewire::test(ManageBackups::class)
            ->assertOk()
            ->assertSee('לא הושלם אף גיבוי');
    }

    public function test_a_fresh_row_whose_archive_is_missing_is_not_treated_as_a_backup(): void
    {
        config(['backup.stale_after_hours' => 36]);

        $backup = $this->runBackup();

        // A lifecycle rule on the bucket, or an operator tidying up, removes
        // the objects and leaves the rows — and every night would then quietly
        // renew a promise that nothing can keep.
        Storage::disk('backups')->delete($backup->path);

        $this->assertStringContainsString(
            'אינו נמצא ביעד האחסון',
            (string) app(BackupRunner::class)->staleWarning(),
        );
    }

    public function test_files_go_back_to_what_they_were_when_a_restore_cannot_finish(): void
    {
        Storage::disk('local')->put('attachments/a.txt', 'מהגיבוי');
        Storage::disk('local')->put('attachments/b.txt', 'מהגיבוי');
        $backup = $this->runBackup();

        Storage::disk('local')->put('attachments/a.txt', 'חי');
        Storage::disk('local')->put('attachments/b.txt', 'חי');

        // The storage refuses the second write. The database rolls back with
        // the transaction; the files cannot, unless something puts them back.
        $written = 0;
        $healthy = Storage::disk('local');
        $flaky = \Mockery::mock($healthy)->makePartial();
        $flaky->shouldReceive('put')->andReturnUsing(function (string $path, $contents) use (&$written, $healthy): bool {
            // Only the second write is refused — putting the originals back
            // has to be allowed to work, or the test proves nothing.
            return ++$written === 2 ? false : (bool) $healthy->put($path, $contents);
        });
        Storage::set('local', $flaky);

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a failed file write must not pass');
        } catch (\Throwable) {
            // expected
        }

        Storage::set('local', $healthy);

        // Live database beside archive-aged files is a pairing that was never
        // true at any moment — worse than either state on its own.
        $this->assertSame('חי', Storage::disk('local')->get('attachments/a.txt'));
        $this->assertSame('חי', Storage::disk('local')->get('attachments/b.txt'));
    }

    public function test_a_file_whose_size_cannot_be_read_is_left_out_rather_than_archived_unchecked(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@multi.test']);
        Storage::disk('local')->put('attachments/a.txt', 'תוכן');

        // Metadata forbidden while reads still work: with no size there is
        // nothing to check the copy against, and an unchecked copy is how a
        // truncated file ends up in the archive under a valid checksum.
        $disk = \Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('size')->andThrow(new \RuntimeException('HEAD forbidden'));
        Storage::set('local', $disk);

        $backup = $this->runBackup();

        $this->assertContains('local:attachments/a.txt', $backup->manifest['skipped_files']);
        Mail::assertSent(NotificationMail::class);
    }

    public function test_an_ambiguous_dispatch_does_not_reopen_the_restore(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $backup = $this->runBackup();

        config([
            'queue.default' => 'broken',
            'queue.connections.broken' => ['driver' => 'no-such-driver'],
        ]);

        Livewire::test(ManageBackups::class)
            ->callTableAction('restore', $backup, ['confirm' => config('backup.restore_confirmation')]);

        // The queue may have taken the payload and failed only to say so.
        // "Failed" would let somebody start a second restore on top of a first
        // one that is already running.
        $this->assertSame(BackupStatus::Running, $backup->fresh()->restore_status);
        $this->assertNotNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));
    }

    public function test_a_claim_whose_job_never_started_can_be_taken_over_later(): void
    {
        config(['backup.restore_claim_minutes' => 30]);
        $backup = $this->runBackup();

        $backup->update([
            'restore_status' => BackupStatus::Running,
            'restore_attempt' => 'the-lost-one',
            'restore_queued_at' => now()->subHours(2),
            'restore_started_at' => null,
        ]);

        // Left alone it would refuse every later attempt for ever and hide its
        // own delete action.
        $this->assertNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));

        // And the payload that was lost, if it ever turns up, finds itself
        // superseded rather than restoring on top of a newer attempt.
        $backup->update(['restore_attempt' => 'the-new-one']);
        Customer::factory()->create(['name' => 'לקוח שהתקבל אחרי']);

        (new RestoreBackupJob($backup->id, 'the-lost-one'))->handle(app(BackupRestorer::class));

        $this->assertSame(1, Customer::where('name', 'לקוח שהתקבל אחרי')->count());
    }

    public function test_the_destination_scan_runs_in_the_background(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        Queue::fake([ImportBackupsJob::class]);

        // Reading every unknown archive can take far longer than a web request
        // is given — and the recovery it serves is exactly when that matters.
        Livewire::test(ManageBackups::class)->callTableAction('import');

        Queue::assertPushed(ImportBackupsJob::class);
    }

    public function test_a_live_file_that_cannot_be_read_stops_the_restore_instead_of_being_lost(): void
    {
        Storage::disk('local')->put('attachments/a.txt', 'מהגיבוי');
        $backup = $this->runBackup();

        Storage::disk('local')->put('attachments/a.txt', 'חי');

        // Writes allowed, reads refused. Recorded as "there was nothing here",
        // undoing would delete the live file for good.
        $healthy = Storage::disk('local');
        $unreadable = \Mockery::mock($healthy)->makePartial();
        $unreadable->shouldReceive('readStream')->andReturn(false);
        Storage::set('local', $unreadable);

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('an unreadable live file must stop the restore');
        } catch (\Throwable) {
            // expected
        }

        Storage::set('local', $healthy);

        $this->assertSame('חי', Storage::disk('local')->get('attachments/a.txt'));
    }

    public function test_a_committed_restore_is_not_offered_again_when_only_the_sequences_failed(): void
    {
        Customer::factory()->create(['name' => 'לקוח מהגיבוי']);
        $backup = $this->runBackup();
        Customer::query()->delete();

        $restorer = new class(app(BackupArchive::class)) extends BackupRestorer
        {
            protected function resetSequences(array $tables): void
            {
                throw new \RuntimeException('lock timeout');
            }
        };

        $restorer->restore($backup);

        // The replacement is committed and production is already at the
        // archived snapshot. Calling that "failed" invites a second attempt,
        // and the second attempt deletes everything accepted since the first.
        $this->assertSame(BackupStatus::Completed, $backup->fresh()->restore_status);
        $this->assertStringContainsString('איפוס מוני המזהים נכשל', (string) $backup->fresh()->restore_error);
        $this->assertSame('לקוח מהגיבוי', Customer::sole()->name);

        // And the screen must not offer to run it again: the second run would
        // only delete what has been accepted since the first one landed.
        $this->assertNotNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));
    }

    public function test_a_short_copy_of_a_live_file_stops_the_restore(): void
    {
        Storage::disk('local')->put('attachments/a.txt', 'מהגיבוי');
        $backup = $this->runBackup();

        Storage::disk('local')->put('attachments/a.txt', str_repeat('חי', 200));

        // The read of the LIVE file ends early and reports no error. Kept as
        // the original, undoing would overwrite the live file with a truncated
        // version of itself — corruption dressed up as a rollback.
        $healthy = Storage::disk('local');
        $flaky = \Mockery::mock($healthy)->makePartial();
        $flaky->shouldReceive('readStream')->andReturnUsing(function (): mixed {
            $handle = fopen('php://temp', 'r+');
            fwrite($handle, 'חי');
            rewind($handle);

            return $handle;
        });
        Storage::set('local', $flaky);

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a short copy of the live file must stop the restore');
        } catch (\Throwable) {
            // expected
        }

        Storage::set('local', $healthy);

        $this->assertSame(str_repeat('חי', 200), Storage::disk('local')->get('attachments/a.txt'));
    }

    public function test_a_committed_restore_stays_committed_when_the_bookkeeping_fails(): void
    {
        Customer::factory()->create(['name' => 'לקוח מהגיבוי']);
        $backup = $this->runBackup();
        Customer::query()->delete();

        $restorer = new class(app(BackupArchive::class)) extends BackupRestorer
        {
            protected function resetSequences(array $tables): void
            {
                // Stands in for anything after the commit — including the
                // write that records the outcome.
                throw new \RuntimeException('connection lost');
            }
        };

        // And it does not throw: an exception would run the job's failure
        // handler, which cannot tell this apart from a restore that never
        // started — least of all when the write meant to record the difference
        // is the thing that failed.
        $restorer->restore($backup);

        // Whatever failed afterwards, the data is already replaced. "Failed"
        // would read as "nothing happened, try again".
        $this->assertSame(BackupStatus::Completed, $backup->fresh()->restore_status);
        $this->assertNotNull($backup->fresh()->restored_at);
        $this->assertNotNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));
    }

    public function test_a_live_file_whose_existence_cannot_be_checked_stops_the_restore(): void
    {
        Storage::disk('local')->put('attachments/a.txt', 'מהגיבוי');
        $backup = $this->runBackup();

        Storage::disk('local')->put('attachments/a.txt', 'חי');

        // "No" and "cannot say" are different answers: recorded as absent, the
        // undo path would delete the live file.
        $healthy = Storage::disk('local');
        $blind = \Mockery::mock($healthy)->makePartial();
        $blind->shouldReceive('exists')->andThrow(new \RuntimeException('HEAD forbidden'));
        Storage::set('local', $blind);

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('an unverifiable live file must stop the restore');
        } catch (\Throwable) {
            // expected
        }

        Storage::set('local', $healthy);

        $this->assertSame('חי', Storage::disk('local')->get('attachments/a.txt'));
    }

    public function test_a_recent_backup_raises_no_stale_alert(): void
    {
        Mail::fake();
        config([
            'billing.notifications.team_email' => 'team@multi.test',
            'backup.stale_after_hours' => 36,
        ]);

        $this->runBackup();

        app(BackupRunner::class)->alertIfStale();

        Mail::assertNothingSent();
    }

    public function test_a_nightly_backup_that_cannot_be_queued_is_recorded(): void
    {
        Mail::fake();
        config([
            'billing.notifications.team_email' => 'team@multi.test',
            'queue.default' => 'broken',
            'queue.connections.broken' => ['driver' => 'no-such-driver'],
        ]);

        RunBackupJob::dispatchNightly();

        // A night that produced no copy of the business, with no row and no
        // alert, is the one failure this whole feature exists to prevent.
        $this->assertSame(BackupStatus::Failed, Backup::sole()->status);
        Mail::assertSent(NotificationMail::class);
    }

    public function test_the_nightly_backup_does_not_run_while_it_is_switched_off(): void
    {
        config(['backup.enabled' => false]);

        RunBackupJob::dispatchNightly();

        $this->assertSame(0, Backup::count());
    }

    public function test_the_button_still_backs_up_while_the_nightly_run_is_switched_off(): void
    {
        // The switch turns off the NIGHTLY run. A button press is explicit, and
        // the panel says the backup started — discarding it silently would make
        // that a lie.
        config(['backup.enabled' => false]);
        $user = User::factory()->create();

        (new RunBackupJob($user->id))->handle(app(BackupRunner::class));

        $this->assertSame(BackupStatus::Completed, Backup::sole()->status);
    }

    public function test_a_manual_backup_that_cannot_be_queued_is_recorded(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@multi.test']);

        // The job never reaches a queue, so nothing downstream will ever create
        // the row that failures are recorded on.
        config([
            'queue.default' => 'broken',
            'queue.connections.broken' => ['driver' => 'no-such-driver'],
        ]);

        Livewire::test(ManageBackups::class)->callTableAction('runNow');

        $backup = Backup::sole();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertNotNull($backup->error);
        Mail::assertSent(NotificationMail::class);
    }

    public function test_the_queue_cannot_redeliver_a_backup_that_is_still_running(): void
    {
        $longest = max((new RunBackupJob)->timeout, (new RestoreBackupJob(1))->timeout);

        // A reservation that expires while the job is still going gets handed
        // to a second worker, whose failure handling would mark an operation
        // failed while the first is still replacing production data.
        foreach (['redis', 'database'] as $connection) {
            $this->assertGreaterThan($longest, (int) config("queue.connections.{$connection}.retry_after"));
        }
    }

    public function test_housekeeping_that_fails_does_not_undo_the_backup(): void
    {
        Mail::fake();
        config([
            'backup.max_file_bytes' => 1024,
            'billing.notifications.team_email' => 'team@multi.test',
        ]);
        Storage::disk('local')->put('attachments/huge.bin', str_repeat('x', 2048));

        // The alert about the skipped file cannot be sent.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('mail server down'));

        $backup = $this->runBackup();

        // The archive is written and the row says so. A failure in what comes
        // after must not reach back and destroy it.
        $this->assertSame(BackupStatus::Completed, $backup->fresh()->status);
        Storage::disk('backups')->assertExists($backup->path);
    }

    public function test_a_backup_job_that_died_before_its_row_existed_leaves_a_live_backup_alone(): void
    {
        Mail::fake();

        // Somebody else's backup, genuinely under way: its worker is still
        // writing the archive right now.
        $live = Backup::create([
            'status' => BackupStatus::Running,
            'disk' => 'backups',
            'path' => 'archives/live.zip',
            'run_attempt' => 'someone-elses-run',
        ]);

        // This job never got as far as creating a row — a cache error while
        // taking the lock, say — and then the worker died.
        (new RunBackupJob)->failed(new \RuntimeException('אין גישה למטמון'));

        // Marking the live one failed would strip the protection the money jobs
        // rely on and offer its delete button, mid-write.
        $this->assertSame(BackupStatus::Running, $live->fresh()->status);

        // But the run that vanished still has to be visible.
        $recorded = Backup::query()->where('status', BackupStatus::Failed)->latest('id')->first();
        $this->assertNotNull($recorded);
        $this->assertStringContainsString('אין גישה למטמון', (string) $recorded->error);
    }

    public function test_a_legacy_backup_payload_does_not_fail_a_live_backup(): void
    {
        Mail::fake();

        // A payload enqueued before runs carried an id — in flight across the
        // deployment that added it.
        $legacy = new RunBackupJob;
        $legacy->attempt = null;

        // Somebody's backup, started a moment ago and still being written.
        $live = Backup::create([
            'status' => BackupStatus::Running,
            'disk' => 'backups',
            'path' => 'archives/live.zip',
        ]);

        $legacy->failed(new \RuntimeException('העובד נהרג'));

        // Too recent to be the work of a worker that already died: leaving it
        // alone is the only safe reading.
        $this->assertSame(BackupStatus::Running, $live->fresh()->status);

        // And the failure is still recorded — on a row of its own.
        $this->assertSame(1, Backup::query()->where('status', BackupStatus::Failed)->count());
    }

    public function test_a_long_restore_keeps_saying_it_is_still_running(): void
    {
        // The gate answers "is a restore under way" from how recently the row
        // was written to, so that a row abandoned by a dead worker cannot stop
        // the business billing for ever. A restore slower than that window must
        // keep proving it is alive — otherwise a charge goes through Cardcom
        // while the rows recording it are minutes from being replaced.
        config(['backup.operation_window_minutes' => 60]);
        Carbon::setTestNow('2026-08-01 01:00:00');

        Storage::disk('local')->put('attachments/keep.txt', 'קובץ');
        $backup = $this->runBackup();

        // Pulling a very large archive off a remote destination: three hours
        // pass before the first table is even read.
        $archives = Storage::disk('backups');
        $slow = \Mockery::mock($archives)->makePartial();
        $slow->shouldReceive('readStream')->andReturnUsing(function (string $path) use ($archives) {
            Carbon::setTestNow(now()->addHours(3));

            return $archives->readStream($path);
        });
        Storage::set('backups', $slow);

        // Asked while the restore is actually replacing things — after that it
        // writes its own completion and every answer looks alive.
        $gateDuringRestore = null;
        $local = Storage::disk('local');
        $watched = \Mockery::mock($local)->makePartial();
        $watched->shouldReceive('put')->andReturnUsing(
            function (string $path, $contents) use ($local, &$gateDuringRestore) {
                $gateDuringRestore ??= app(OperationGate::class)->isRunning();

                return $local->put($path, $contents);
            });
        Storage::set('local', $watched);

        app(BackupRestorer::class)->restore($backup);

        $this->assertTrue($gateDuringRestore, 'שחזור ארוך חדל להיראות כפעולה שרצה');
    }

    public function test_a_restore_can_be_run_from_the_console_without_a_worker(): void
    {
        // The documented procedure: stop the queue worker, then restore. With
        // it stopped the screen's button has nobody to hand the work to, so the
        // command does it here — this is the path a real restore takes.
        Queue::fake();
        Customer::factory()->create(['name' => 'לפני השחזור']);
        $backup = $this->runBackup();
        Customer::query()->delete();

        $this->artisan('backup:restore', ['id' => $backup->id, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(BackupStatus::Completed, $backup->fresh()->restore_status);
        $this->assertNotNull($backup->fresh()->restored_at);
    }

    public function test_a_restore_writes_down_every_file_before_it_overwrites_it(): void
    {
        Storage::disk('local')->put('attachments/keep.txt', 'היה בגיבוי');
        $backup = $this->runBackup();
        Storage::disk('local')->put('attachments/keep.txt', 'הועלה אחרי הגיבוי');

        // Read at the moment of the write: a worker killed here never reaches
        // the undo path, so whatever is on disk BY THEN is all another process
        // will have to work from.
        $journal = null;
        $real = Storage::disk('local');
        $watched = \Mockery::mock($real)->makePartial();
        $watched->shouldReceive('put')->andReturnUsing(function () use (&$journal): bool {
            $journal ??= @file_get_contents(storage_path('backups/restore-journal.jsonl'));

            return true;
        });
        Storage::set('local', $watched);

        app(BackupRestorer::class)->restore($backup);
        Storage::set('local', $real);

        $this->assertIsString($journal);
        $this->assertStringContainsString('attachments/keep.txt', (string) $journal);

        // And a restore that finished leaves nothing to replay.
        $this->assertFalse(app(BackupRestorer::class)->hasInterruptedFiles());
    }

    /**
     * Write the journal and staging file a killed restore would have left.
     *
     * @param  list<array{path: string, was: string|null}>  $files  what was live before it was overwritten
     */
    private function leaveInterruptedRestore(array $files, ?string $token = null, ?int $backupId = null): void
    {
        $dir = storage_path('backups');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lines = [json_encode(['journal' => $token, 'backup_id' => $backupId])];
        $blob = '';

        foreach ($files as $file) {
            $entry = ['disk' => 'local', 'path' => $file['path'], 'at' => null, 'len' => 0];

            if ($file['was'] !== null) {
                $entry['at'] = strlen($blob);
                $entry['len'] = strlen($file['was']);
                $blob .= $file['was'];
            }

            $lines[] = json_encode($entry, JSON_UNESCAPED_UNICODE);
        }

        file_put_contents($dir.'/restore-staging.blob', $blob);
        file_put_contents($dir.'/restore-journal.jsonl', implode(PHP_EOL, $lines).PHP_EOL);
    }

    private function clearInterruptedRestore(): void
    {
        @unlink(storage_path('backups/restore-journal.jsonl'));
        @unlink(storage_path('backups/restore-staging.blob'));
    }

    public function test_the_files_a_killed_restore_left_behind_can_be_put_back(): void
    {
        Storage::disk('local')->put('attachments/keep.txt', 'מהגיבוי — נדרס');

        // Exactly what a worker killed mid-write leaves: the archive's contents
        // on the live path, the original in the staging file, and a journal
        // saying where in it those bytes are.
        $this->leaveInterruptedRestore([['path' => 'attachments/keep.txt', 'was' => 'הועלה אחרי הגיבוי']]);

        try {
            $this->assertTrue(app(BackupRestorer::class)->hasInterruptedFiles());

            $this->artisan('backup:recover-files')->assertSuccessful();

            // Without this the live file stays at archive-aged contents beside a
            // database that rolled itself back — a pairing that was never true.
            $this->assertSame('הועלה אחרי הגיבוי', Storage::disk('local')->get('attachments/keep.txt'));
            $this->assertFalse(app(BackupRestorer::class)->hasInterruptedFiles());
        } finally {
            $this->clearInterruptedRestore();
        }
    }

    public function test_files_are_not_put_back_when_the_restore_actually_committed(): void
    {
        $backup = $this->runBackup();
        Storage::disk('local')->put('attachments/keep.txt', 'מהגיבוי');

        // Killed in the sliver between the commit and the cleanup: the journal
        // looks indistinguishable from a rolled-back restore, except for the
        // token — written inside the transaction, so it is there only if the
        // transaction committed.
        $this->leaveInterruptedRestore(
            [['path' => 'attachments/keep.txt', 'was' => 'מלפני השחזור']], 'token-abc', $backup->id);
        $backup->update(['restore_journal' => 'token-abc']);

        try {
            $result = app(BackupRestorer::class)->recoverInterruptedFiles();

            // The database IS the archive now. Putting the old files back would
            // be the corruption, not the cure.
            $this->assertSame(['restored' => 0, 'failed' => 0, 'pending' => 0], $result);
            $this->assertSame('מהגיבוי', Storage::disk('local')->get('attachments/keep.txt'));
            $this->assertFalse(app(BackupRestorer::class)->hasInterruptedFiles());
        } finally {
            $this->clearInterruptedRestore();
        }
    }

    public function test_a_recovery_that_cannot_read_the_commit_state_touches_nothing(): void
    {
        Storage::disk('local')->put('attachments/keep.txt', 'מהגיבוי');

        $this->leaveInterruptedRestore(
            [['path' => 'attachments/keep.txt', 'was' => 'מלפני השחזור']], 'token-xyz', 999999);

        // The one question that decides everything cannot be asked.
        $connection = config('database.default');
        config(['database.connections.broken' => [
            'driver' => 'sqlite', 'database' => '/nonexistent/multioto.sqlite', 'prefix' => '',
        ]]);
        config(['database.default' => 'broken']);

        try {
            $result = app(BackupRestorer::class)->recoverInterruptedFiles();

            // "Cannot say" is not "did not commit". Guessing wrong here pairs a
            // restored database with the files it replaced.
            $this->assertSame(1, $result['pending']);
            $this->assertSame('מהגיבוי', Storage::disk('local')->get('attachments/keep.txt'));
        } finally {
            config(['database.default' => $connection]);
            $this->clearInterruptedRestore();
        }
    }

    public function test_a_restore_refuses_to_run_over_an_unfinished_rollback(): void
    {
        $backup = $this->runBackup();

        $this->leaveInterruptedRestore([['path' => 'attachments/gone.txt', 'was' => 'הקובץ החי היחיד שנשאר']]);

        // The disk still refuses, so the earlier rollback still cannot finish.
        $stubborn = \Mockery::mock(Storage::disk('local'))->makePartial();
        $stubborn->shouldReceive('put')->andReturn(false);
        Storage::set('local', $stubborn);

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a restore must not run on top of an unfinished rollback');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('recover-files', $e->getMessage());
        } finally {
            $this->clearInterruptedRestore();
        }

        // Starting would have emptied the staging file, taking with it the one
        // copy of a file nothing else has.
        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);

        // And it does not leave the whole system looking busy on the way out.
        $this->assertFileDoesNotExist(BackupRestorer::operationMarkerPath());
    }

    public function test_a_recovery_that_cannot_write_keeps_what_it_needs_to_try_again(): void
    {
        Storage::disk('local')->put('attachments/keep.txt', 'מהגיבוי — נדרס');

        $this->leaveInterruptedRestore([['path' => 'attachments/keep.txt', 'was' => 'הועלה אחרי הגיבוי']]);

        // An IAM policy that allows writes but not this one, a disk that went
        // away: no exception, just a refusal.
        $stubborn = \Mockery::mock(Storage::disk('local'))->makePartial();
        $stubborn->shouldReceive('put')->andReturn(false);
        Storage::set('local', $stubborn);

        try {
            $result = app(BackupRestorer::class)->recoverInterruptedFiles();

            // Nothing is thrown away and nothing is marked done: the copy is
            // still the only version of that file, and reporting success would
            // mean nobody ever looks again.
            $this->assertSame(1, $result['failed']);
            $this->assertFileExists(storage_path('backups/restore-staging.blob'));

            $this->artisan('backup:recover-files')->assertFailed();
        } finally {
            $this->clearInterruptedRestore();
        }
    }

    public function test_a_committed_restore_is_never_marked_failed_by_a_dying_worker(): void
    {
        $backup = $this->runBackup();

        // The transaction landed — the token is written inside it — and the
        // worker was killed before the completion bookkeeping that follows.
        $backup->update([
            'restore_status' => BackupStatus::Running,
            'restore_attempt' => 'attempt-1',
            // The token IS the attempt id, so it says which run landed.
            'restore_journal' => 'attempt-1',
            'restored_at' => null,
        ]);

        (new RestoreBackupJob($backup->id, 'attempt-1'))->failed(new \RuntimeException('הועד נהרג'));

        // "Failed" reads as "nothing happened, try again" — and trying again
        // would delete everything accepted since this one landed.
        $this->assertSame(BackupStatus::Running, $backup->fresh()->restore_status);
    }

    public function test_a_new_claim_does_not_erase_the_proof_an_older_restore_committed(): void
    {
        $backup = $this->runBackup();
        Storage::disk('local')->put('attachments/keep.txt', 'מהגיבוי');

        // An earlier restore committed and was killed before it could close
        // its journal. Its token is the only thing saying so.
        $backup->update(['restore_journal' => 'attempt-older']);
        $this->leaveInterruptedRestore(
            [['path' => 'attachments/keep.txt', 'was' => 'מלפני השחזור']], 'attempt-older', $backup->id);

        try {
            // Somebody presses restore again. Claiming must not throw away the
            // answer the leftover journal still needs.
            $this->assertNotNull(app(RestoreClaim::class)->take($backup));

            $result = app(BackupRestorer::class)->recoverInterruptedFiles();

            $this->assertSame(0, $result['pending']);
            $this->assertSame('מהגיבוי', Storage::disk('local')->get('attachments/keep.txt'));
        } finally {
            $this->clearInterruptedRestore();
        }
    }

    public function test_a_restore_inside_its_transaction_still_looks_alive(): void
    {
        config(['backup.operation_window_minutes' => 60]);
        Carbon::setTestNow('2026-08-01 01:00:00');

        Storage::disk('local')->put('attachments/keep.txt', 'קובץ');
        $backup = $this->runBackup();

        // Loading tables and writing files can outlast both the lock's lease
        // and the gate's window — and inside the transaction the row's own
        // heartbeat is invisible to every other connection.
        $seen = null;
        $local = Storage::disk('local');
        $watched = \Mockery::mock($local)->makePartial();
        $watched->shouldReceive('put')->andReturnUsing(
            function (string $path, $contents) use ($local, &$seen) {
                // Three hours of uploading — and progress while it happens,
                // which is what the destination pulling the source stream
                // looks like from in here.
                Carbon::setTestNow(now()->addHours(3));

                $read = is_resource($contents) ? stream_get_contents($contents) : (string) $contents;

                $seen ??= app(OperationGate::class)->isRunning();

                return $local->put($path, $read);
            });
        Storage::set('local', $watched);

        app(BackupRestorer::class)->restore($backup);
        Storage::set('local', $local);

        $this->assertTrue($seen, 'שחזור באמצע הטרנזקציה חדל להיראות כפעולה שרצה');

        // And it stops saying so the moment it is over.
        $this->assertFileDoesNotExist(BackupRestorer::operationMarkerPath());
    }

    public function test_a_row_an_open_journal_depends_on_cannot_be_deleted(): void
    {
        $backup = $this->runBackup();
        $this->leaveInterruptedRestore(
            [['path' => 'attachments/keep.txt', 'was' => 'מלפני השחזור']], 'attempt-older', $backup->id);

        try {
            // Deleting it would take away the token that says whether that
            // restore committed — and "cannot say" is read as "did not", which
            // would replay old files over restored data.
            $this->assertSame('journal', app(BackupRunner::class)->deleteRecord($backup->id));
            $this->assertNotNull(Backup::find($backup->id));
            Storage::disk('backups')->assertExists($backup->path);
        } finally {
            $this->clearInterruptedRestore();
        }
    }

    public function test_a_refusal_against_an_unreachable_database_does_not_leave_billing_blocked(): void
    {
        $backup = $this->runBackup();

        // A journal whose commit state cannot be read, because the database is
        // what is down — and recording the refusal is a write to that same
        // database.
        $this->leaveInterruptedRestore(
            [['path' => 'attachments/keep.txt', 'was' => 'מלפני השחזור']], 'token-unknown', 999999);

        $connection = config('database.default');
        config(['database.connections.broken' => [
            'driver' => 'sqlite', 'database' => '/nonexistent/multioto.sqlite', 'prefix' => '',
        ]]);
        config(['database.default' => 'broken']);

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a restore must not run over an unfinished rollback');
        } catch (\Throwable) {
            // expected
        } finally {
            config(['database.default' => $connection]);
            $this->clearInterruptedRestore();
        }

        // A restore that never started must not stop the business billing for
        // the length of the whole window.
        $this->assertFileDoesNotExist(BackupRestorer::operationMarkerPath());
        $this->assertFalse(app(OperationGate::class)->isRunning());
    }

    public function test_a_restore_that_died_without_committing_can_be_tried_again(): void
    {
        $backup = $this->runBackup();

        // Started, never committed — no token — and not heard from since.
        $backup->update([
            'restore_status' => BackupStatus::Running,
            'restore_attempt' => 'attempt-dead',
            'restore_started_at' => now()->subHours(4),
        ]);
        Backup::whereKey($backup->id)->update(['updated_at' => now()->subHours(4)]);

        // Its transaction rolled itself back, so there is nothing a second
        // attempt could destroy — and without this the row would refuse every
        // future restore for ever.
        $this->assertNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));
        $this->assertNotNull(app(RestoreClaim::class)->take($backup->fresh()));
    }

    public function test_a_second_attempt_that_died_is_not_held_by_the_first_ones_token(): void
    {
        $backup = $this->runBackup();

        // This archive was restored successfully once — its token stays on the
        // row as the proof for that run's journal. A later attempt then died
        // before committing anything.
        $backup->update([
            'restore_status' => BackupStatus::Running,
            'restore_attempt' => 'attempt-second',
            'restore_journal' => 'attempt-first',
            'restore_started_at' => now()->subHours(4),
        ]);
        Backup::whereKey($backup->id)->update(['updated_at' => now()->subHours(4)]);

        // The old token says nothing about the attempt that is stuck. Reading
        // it as "committed" would strand this recovery point for ever.
        $this->assertNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));
        $this->assertNotNull(app(RestoreClaim::class)->take($backup->fresh()));
    }

    public function test_a_legacy_restore_that_died_is_not_stuck_for_ever(): void
    {
        $backup = $this->runBackup();

        // A payload from before attempts carried ids: no attempt, and so no
        // token either. Having neither is not the same as having committed.
        $backup->update([
            'restore_status' => BackupStatus::Running,
            'restore_attempt' => null,
            'restore_journal' => null,
            'restore_started_at' => now()->subHours(4),
        ]);
        Backup::whereKey($backup->id)->update(['updated_at' => now()->subHours(4)]);

        $this->assertNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));
        $this->assertNotNull(app(RestoreClaim::class)->take($backup->fresh()));
    }

    public function test_a_legacy_restore_that_committed_is_still_never_repeated(): void
    {
        $backup = $this->runBackup();

        // No attempt id, but a token: a payload from before attempts existed
        // generates its own, so whatever is there belongs to this run — and it
        // is only written when the transaction lands.
        $backup->update([
            'restore_status' => BackupStatus::Running,
            'restore_attempt' => null,
            'restore_journal' => 'generated-for-a-legacy-run',
            'restore_started_at' => now()->subHours(4),
        ]);
        Backup::whereKey($backup->id)->update(['updated_at' => now()->subHours(4)]);

        // The screen must not offer what the claim would refuse — and neither
        // may repeat a restore that already replaced production data.
        $this->assertNotNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));
        $this->assertNull(app(RestoreClaim::class)->take($backup->fresh()));
    }

    public function test_a_restore_that_committed_is_still_never_repeated(): void
    {
        $backup = $this->runBackup();

        // Same age, but this one's transaction landed.
        $backup->update([
            'restore_status' => BackupStatus::Running,
            'restore_attempt' => 'attempt-landed',
            'restore_journal' => 'attempt-landed',
            'restore_started_at' => now()->subHours(4),
        ]);
        Backup::whereKey($backup->id)->update(['updated_at' => now()->subHours(4)]);

        // Repeating it would delete everything accepted since it landed.
        $this->assertNotNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));
        $this->assertNull(app(RestoreClaim::class)->take($backup->fresh()));
    }

    public function test_a_restore_refuses_an_archive_that_declares_tables_it_should_not(): void
    {
        $backup = $this->runBackup();

        // An archive naming an excluded table would have it emptied and
        // reloaded like any other — "backups" would delete the row tracking
        // this very restore, "jobs" would resurrect work already done.
        $this->corruptArchive($backup, function (ZipArchive $zip) {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $manifest['tables']['jobs'] = 0;
            $zip->addFromString('manifest.json', json_encode($manifest));
        });

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a restore must refuse an archive declaring excluded tables');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('jobs', $e->getMessage());
        }

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
    }

    public function test_a_restore_refuses_an_archive_from_a_disk_this_install_does_not_have(): void
    {
        Storage::disk('local')->put('attachments/keep.txt', 'צרופה');
        $backup = $this->runBackup();

        // A rebuilt server configured with different disks: restoring the rows
        // that reference these files while silently installing none of them
        // would be reported as a success.
        config(['backup.files' => ['public' => []]]);

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a restore must refuse files it cannot put anywhere');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('local', $e->getMessage());
        }

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
    }

    public function test_a_backup_does_not_start_when_the_lock_expired_under_a_running_restore(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@multi.test']);

        // A console restore has no timeout, so its lock's lease can run out
        // while it is still very much alive — pulling a huge archive off a
        // remote destination. The lock is free; the restore is not over.
        $restoring = $this->runBackup();
        $restoring->update(['restore_status' => BackupStatus::Running]);

        (new RunBackupJob)->handle(app(BackupRunner::class));

        // Backing up now would read the rows from one state and the files from
        // another and call the result a good archive.
        $started = Backup::query()->where('status', BackupStatus::Failed)->latest('id')->first();
        $this->assertNotNull($started);
        $this->assertStringContainsString('רצה באותו רגע', (string) $started->error);
    }

    public function test_the_console_asks_before_restoring_over_a_live_application(): void
    {
        Queue::fake();
        $backup = $this->runBackup();

        // Stopping the queue is only half of it: a request being served right
        // now can be inside Cardcom or Linet, and its write would either be
        // deleted by the restore or land on restored data. Maintenance mode is
        // what stops new ones arriving, so being asked is the point.
        $this->artisan('backup:restore', ['id' => $backup->id])
            ->expectsConfirmation('האפליקציה אינה במצב תחזוקה — להמשיך בכל זאת?', 'no')
            ->assertFailed();

        $this->assertNull($backup->fresh()->restored_at);
    }

    public function test_the_console_takes_over_a_restore_the_queue_never_ran(): void
    {
        Queue::fake();
        Customer::factory()->create();
        $backup = $this->runBackup();

        // Claimed from the screen — and then nothing, because the worker was
        // stopped exactly as the guide says to do before restoring.
        $stale = app(RestoreClaim::class)->take($backup);
        $this->assertNotNull($stale);

        $this->artisan('backup:restore', ['id' => $backup->id, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(BackupStatus::Completed, $backup->fresh()->restore_status);

        // And the payload that was left in the queue, if it ever arrives, finds
        // itself superseded: it must not put the same snapshot back over
        // everything accepted since.
        $before = $backup->fresh()->restored_at;
        app(RestoreBackupJob::class, ['backupId' => $backup->id, 'attempt' => $stale])
            ->handle(app(BackupRestorer::class));

        $this->assertEquals($before, $backup->fresh()->restored_at);
    }

    public function test_a_backup_claimed_for_restore_cannot_be_deleted_from_a_stale_screen(): void
    {
        $backup = $this->runBackup();

        // Hiding the button only decides what is drawn. The confirmation dialog
        // can already be open when somebody else claims this very archive.
        $backup->update(['restore_status' => BackupStatus::Running]);

        $this->assertSame('busy', app(BackupRunner::class)->deleteRecord($backup->id));

        // Deleting now would take the archive out from under a restore that is
        // about to replace production data.
        $this->assertNotNull(Backup::find($backup->id));
        Storage::disk('backups')->assertExists($backup->path);
    }

    public function test_the_backup_row_stays_when_the_manual_delete_cannot_remove_the_archive(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $backup = $this->runBackup();

        // An IAM policy that allows writes but not deletes looks exactly like
        // this: no exception, just a refusal.
        $disk = \Mockery::mock(Storage::disk('backups'))->makePartial();
        $disk->shouldReceive('delete')->andReturn(false);
        Storage::set('backups', $disk);

        Livewire::test(ManageBackups::class)->callTableAction('delete', $backup);

        // Dropping the row would leave a file full of customer details at the
        // destination with nothing left to find it by.
        $this->assertNotNull(Backup::find($backup->id));
    }

    public function test_a_backup_row_survives_when_its_archive_cannot_be_deleted(): void
    {
        config(['backup.retention_days' => 1, 'backup.keep_at_least' => 1]);

        $keep = $this->runBackup();
        $old = $this->runBackup();
        Backup::whereKey($old->id)->update(['created_at' => now()->subDays(30)]);

        // Gone from the destination by other means: delete() reports false and
        // the row must stay, or the archive becomes unfindable.
        Storage::shouldReceive('disk')->andThrow(new \RuntimeException('storage down'));

        app(BackupRunner::class)->prune();

        $this->assertNotNull(Backup::find($old->id));
        $this->assertNotNull(Backup::find($keep->id));
    }

    public function test_a_restore_into_a_changed_schema_is_refused(): void
    {
        $backup = $this->runBackup();

        // As if the archive came from an older release.
        $manifest = $backup->manifest;
        $manifest['migrations'][] = '9999_01_01_000000_a_migration_this_code_does_not_have';
        $backup->update(['manifest' => $manifest]);

        $this->assertNotNull(app(BackupRestorer::class)->blockedReason($backup->fresh()));
    }

    public function test_a_restore_that_fails_says_so_on_the_backup(): void
    {
        $backup = $this->runBackup();

        // The archive is gone from the destination.
        Storage::disk('backups')->delete($backup->path);

        try {
            app(BackupRestorer::class)->restore($backup);
            $this->fail('a missing archive must not restore quietly');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->restore_status);
        $this->assertNotNull($backup->fresh()->restore_error);
    }

    public function test_an_unfinished_backup_cannot_be_restored_from(): void
    {
        $backup = Backup::create([
            'status' => BackupStatus::Running,
            'disk' => 'backups',
            'path' => 'archives/half-written.zip',
        ]);

        $this->assertNotNull(app(BackupRestorer::class)->blockedReason($backup));

        (new RestoreBackupJob($backup->id))->handle(app(BackupRestorer::class));

        // Never even attempted.
        $this->assertNull($backup->fresh()->restore_status);
    }

    /*
    | ----------------------------------------------------------------
    | Who may see any of this
    | ----------------------------------------------------------------
    */

    public function test_the_screen_is_admin_only(): void
    {
        // An archive is a file full of customer names, phone numbers and
        // payment history — it is not a screen for every team member.
        $this->actingAs(User::factory()->create(['role' => UserRole::Agent]));
        $this->assertFalse(ManageBackups::canAccess());

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->assertTrue(ManageBackups::canAccess());
    }

    public function test_the_screen_opens_and_lists_the_backups(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $backup = $this->runBackup();

        Livewire::test(ManageBackups::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$backup]);
    }

    /** Rewrite the stored archive through $mutate, to fake corruption. */
    private function corruptArchive(Backup $backup, callable $mutate): void
    {
        $local = $this->pullArchive($backup);

        $zip = new ZipArchive;
        $zip->open($local);
        $mutate($zip);
        $zip->close();

        Storage::disk($backup->disk)->put($backup->path, (string) file_get_contents($local));
    }

    /**
     * Damage one member's compressed payload in place, leaving every header and
     * the central directory intact — the archive still opens and the entry is
     * still listed; only reading its contents fails.
     */
    private function corruptPayload(Backup $backup, string $entry): void
    {
        $raw = (string) Storage::disk($backup->disk)->get($backup->path);

        // The name's first appearance is in its local file header; the payload
        // starts right after the header, the name and the extra field.
        $header = strpos($raw, $entry);
        $this->assertNotFalse($header, "the archive has no member \"{$entry}\"");

        $header -= 30;
        $nameLength = unpack('v', substr($raw, $header + 26, 2))[1];
        $extraLength = unpack('v', substr($raw, $header + 28, 2))[1];
        $payload = $header + 30 + $nameLength + $extraLength;

        $raw[$payload + 4] = chr(ord($raw[$payload + 4]) ^ 0xFF);

        Storage::disk($backup->disk)->put($backup->path, $raw);
    }

    /** Copy the stored archive to a local file the test can open. */
    private function pullArchive(Backup $backup): string
    {
        $local = tempnam(sys_get_temp_dir(), 'test-archive-');
        file_put_contents($local, Storage::disk($backup->disk)->get($backup->path));

        return $local;
    }
}
