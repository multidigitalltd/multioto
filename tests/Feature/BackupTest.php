<?php

namespace Tests\Feature;

use App\Enums\BackupStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ManageBackups;
use App\Jobs\RestoreBackupJob;
use App\Jobs\RunBackupJob;
use App\Mail\NotificationMail;
use App\Models\Backup;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Site;
use App\Models\User;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\BackupRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
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
        Backup::create(['status' => BackupStatus::Running, 'disk' => 'backups', 'path' => 'archives/x.zip']);

        (new RunBackupJob)->failed(new \RuntimeException('worker timed out'));

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

        Backup::create(['status' => BackupStatus::Running, 'disk' => 'backups', 'path' => 'archives/x.zip']);

        (new RunBackupJob)->failed(new \RuntimeException('worker timed out'));

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

    /** Copy the stored archive to a local file the test can open. */
    private function pullArchive(Backup $backup): string
    {
        $local = tempnam(sys_get_temp_dir(), 'test-archive-');
        file_put_contents($local, Storage::disk($backup->disk)->get($backup->path));

        return $local;
    }
}
