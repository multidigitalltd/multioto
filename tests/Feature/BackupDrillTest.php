<?php

namespace Tests\Feature;

use App\Jobs\DrillBackupJob;
use App\Mail\NotificationMail;
use App\Models\Backup;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SystemLog;
use App\Services\Backup\BackupDrill;
use App\Services\Backup\BackupRunner;
use App\Services\System\HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Present is not the same as filled.
     *
     * A default rescues a column the row leaves out; it does nothing for one the
     * row explicitly empties, and NOT NULL refuses it either way.
     */
    public function test_a_null_in_a_column_that_cannot_hold_one_is_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('database/customers.ndjson', "{\"id\":1,\"name\":null}\n{\"id\":2,\"name\":null}\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('ערך ריק', implode(' ', $report['problems']));
    }

    /**
     * A marker that arrives as nothing.
     *
     * A value the backup could not write as UTF-8 travels as a {"__b64": …}
     * marker, and the restore turns an unreadable one into NULL on the way in.
     * The line reads perfectly; the column refuses what it becomes.
     */
    public function test_an_unreadable_base64_marker_in_a_required_column_is_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/customers.ndjson',
            "{\"id\":1,\"name\":{\"__b64\":\"\"}}\n{\"id\":2,\"name\":{\"__b64\":\"\"}}\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('ערך ריק', implode(' ', $report['problems']));
    }

    /**
     * Two shapes in one member, each fine on its own.
     *
     * The restore batches rows into a single insert whose column list comes from
     * the first of them while every tuple keeps its own values — so the second
     * shape does not quietly get its defaults, it makes the statement invalid.
     */
    public function test_rows_with_different_shapes_in_one_table_are_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/customers.ndjson',
            "{\"id\":1,\"name\":\"א\",\"email\":\"a@example.com\"}\n{\"id\":2,\"name\":\"ב\"}\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('מבנה שונה', implode(' ', $report['problems']));
    }

    /**
     * The same primary key twice.
     *
     * Every row is valid on its own; the restore gets as far as the insert and
     * the key refuses the second one — half way through, with the tables
     * already emptied.
     */
    public function test_a_repeated_primary_key_is_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/customers.ndjson',
            "{\"id\":1,\"name\":\"א\"}\n{\"id\":1,\"name\":\"ב\"}\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('כפולים', implode(' ', $report['problems']));
    }

    /**
     * A key column the rows leave out is not a key that cannot be checked.
     *
     * charges.attempt_number defaults to 1 and belongs to the unique key on
     * (subscription_id, period_start, attempt_number), so two rows that both
     * omit it are handed the same 1 — and collide.
     */
    public function test_a_duplicate_key_completed_by_a_default_is_reported(): void
    {
        $backup = $this->backup();

        $row = '{"subscription_id":5,"amount_agorot":100,"total_agorot":100,'
            .'"period_start":"2026-01-01","period_end":"2026-01-31"';

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('database/charges.ndjson', $row.',"id":1}'."\n".$row.',"id":2}'."\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('כפולים', implode(' ', $report['problems']));
    }

    /**
     * A row whose parent is not in the archive.
     *
     * The insert meets the constraint and stops there — with every table
     * already emptied, which is the moment a restore is least recoverable.
     */
    public function test_a_reference_to_a_row_the_archive_does_not_contain_is_reported(): void
    {
        Site::factory()->for(Customer::factory())->create();

        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/sites.ndjson',
            json_encode([
                'id' => 1,
                'customer_id' => 999,
                'domain' => 'example.com',
                'status' => 'active',
            ], JSON_UNESCAPED_UNICODE)."\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('customer_id', implode(' ', $report['problems']));
        $this->assertStringContainsString('999', implode(' ', $report['problems']));
    }

    /** A real archive full of real relations must come back silent. */
    public function test_an_archive_with_relations_passes_the_drill(): void
    {
        Site::factory()->count(3)->for(Customer::factory())->create();

        $report = app(BackupDrill::class)->run($this->backup());

        $this->assertSame([], $report['problems']);
    }

    /** A word in a column that holds numbers is refused by the database, not by the JSON. */
    public function test_a_value_of_the_wrong_type_is_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/charges.ndjson',
            '{"id":1,"subscription_id":5,"amount_agorot":"oops","total_agorot":100,'
                .'"period_start":"2026-01-01","period_end":"2026-01-31"}'."\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('amount_agorot', implode(' ', $report['problems']));
    }

    /** 1 and "01" are one value to the column and two strings to everything else. */
    public function test_a_key_repeated_in_another_spelling_is_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/customers.ndjson',
            "{\"id\":1,\"name\":\"א\"}\n{\"id\":\"01\",\"name\":\"ב\"}\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('כפולים', implode(' ', $report['problems']));
    }

    /** A whole-number column refuses "1.5", which every decimal column takes. */
    public function test_a_fraction_in_a_whole_number_column_is_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/charges.ndjson',
            '{"id":1,"subscription_id":5,"amount_agorot":"1.5","total_agorot":100,'
                .'"period_start":"2026-01-01","period_end":"2026-01-31"}'."\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('amount_agorot', implode(' ', $report['problems']));
    }

    /** The same key written two ways is still the same key. */
    public function test_a_key_repeated_as_a_base64_marker_is_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/customers.ndjson',
            "{\"id\":1,\"name\":\"א\"}\n{\"id\":{\"__b64\":\"MQ==\"},\"name\":\"ב\"}\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('כפולים', implode(' ', $report['problems']));
    }

    /**
     * A JSON column refuses anything that is not a document.
     *
     * PostgreSQL stores these as json and rejects a bare word; SQLite stores
     * the same column as text and reports it as text, so the classification
     * finds nothing there. The table below is therefore declared with a raw
     * json type — otherwise this check would ship without ever having run.
     */
    public function test_a_value_that_is_not_json_in_a_json_column_is_reported(): void
    {
        DB::statement('CREATE TABLE json_probe (id integer primary key, payload json)');

        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $manifest['tables']['json_probe'] = 1;
        $zip->addFromString('manifest.json', (string) json_encode($manifest));
        $zip->addFromString('database/json_probe.ndjson', '{"id":1,"payload":"not-json"}'."\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('payload', implode(' ', $report['problems']));
    }

    /** A date column takes a moment in time, and "not-a-date" is not one. */
    public function test_a_value_that_is_not_a_date_in_a_date_column_is_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/charges.ndjson',
            '{"id":1,"subscription_id":5,"amount_agorot":100,"total_agorot":100,'
                .'"period_start":"not-a-date","period_end":"2026-01-31"}'."\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('period_start', implode(' ', $report['problems']));
    }

    /**
     * The thirty-first of February.
     *
     * PHP rolls it forward to the third of March and calls it a date; the
     * database refuses the row. The drill has to answer the database's
     * question, not PHP's.
     */
    public function test_a_calendar_date_that_does_not_exist_is_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/charges.ndjson',
            '{"id":1,"subscription_id":5,"amount_agorot":100,"total_agorot":100,'
                .'"period_start":"2026-02-31","period_end":"2026-01-31"}'."\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('period_start', implode(' ', $report['problems']));
    }

    /**
     * A boolean column takes true/false, 0/1, t/f — and not a word.
     *
     * Declared here with a raw boolean type for the same reason as the JSON
     * table: SQLite stores booleans as tinyint, so on this driver they are
     * checked as integers and the boolean branch would never run.
     */
    public function test_a_value_that_is_not_a_boolean_in_a_boolean_column_is_reported(): void
    {
        DB::statement('CREATE TABLE bool_probe (id integer primary key, flag boolean)');

        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $manifest['tables']['bool_probe'] = 1;
        $zip->addFromString('manifest.json', (string) json_encode($manifest));
        $zip->addFromString('database/bool_probe.ndjson', '{"id":1,"flag":"oops"}'."\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('flag', implode(' ', $report['problems']));
    }

    /** Every spelling the database itself accepts must pass. */
    public function test_the_spellings_a_boolean_column_accepts_are_not_faulted(): void
    {
        DB::statement('CREATE TABLE bool_probe (id integer primary key, flag boolean)');

        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $manifest['tables']['bool_probe'] = 4;
        $zip->addFromString('manifest.json', (string) json_encode($manifest));
        $zip->addFromString('database/bool_probe.ndjson',
            '{"id":1,"flag":true}'."\n".'{"id":2,"flag":0}'."\n"
            .'{"id":3,"flag":"t"}'."\n".'{"id":4,"flag":"false"}'."\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringNotContainsString('flag', implode(' ', $report['problems']));
    }

    /**
     * A uuid column takes a uuid.
     *
     * notifications.id is one in production; SQLite keeps it as char(36), so
     * this table declares the type outright — otherwise the branch would never
     * run here. Both spellings the database accepts are covered, because
     * rejecting a valid archive is the failure that matters.
     */
    public function test_a_value_that_is_not_a_uuid_in_a_uuid_column_is_reported(): void
    {
        DB::statement('CREATE TABLE uuid_probe (id integer primary key, ref uuid)');

        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $manifest['tables']['uuid_probe'] = 3;
        $zip->addFromString('manifest.json', (string) json_encode($manifest));
        $zip->addFromString('database/uuid_probe.ndjson',
            '{"id":1,"ref":"0198f1a2-3b4c-4d5e-8f90-1a2b3c4d5e6f"}'."\n"
            .'{"id":2,"ref":"0198f1a23b4c4d5e8f901a2b3c4d5e6f"}'."\n"
            .'{"id":3,"ref":"oops"}'."\n");
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);
        $problems = implode(' ', $report['problems']);

        $this->assertStringContainsString('ref', $problems);
        // One line, for the one bad row — the two valid spellings above must
        // not have contributed to it.
        $this->assertSame(1, substr_count($problems, 'סוג העמודה'));
    }

    /** The marker is not the value: this one decodes to the word "oops". */
    public function test_a_base64_marker_holding_the_wrong_type_is_reported(): void
    {
        $backup = $this->backup();

        $path = Storage::disk('backups')->path($backup->path);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString(
            'database/charges.ndjson',
            '{"id":1,"subscription_id":5,"amount_agorot":{"__b64":"b29wcw=="},"total_agorot":100,'
                .'"period_start":"2026-01-01","period_end":"2026-01-31"}'."\n",
        );
        $zip->close();

        $report = app(BackupDrill::class)->run($backup);

        $this->assertStringContainsString('amount_agorot', implode(' ', $report['problems']));
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

    /**
     * A drill that ran and failed still stamps the date.
     *
     * Reading only the date would answer "was one opened recently" with a yes —
     * for the next 45 days, right after the check established that the archive
     * is unusable. The backup check beside it deliberately never touches the
     * destination, so nothing else on the screen would notice.
     */
    public function test_health_stays_degraded_after_a_failed_drill(): void
    {
        $backup = $this->backup();
        Storage::disk('backups')->delete($backup->path);

        (new DrillBackupJob)->handle(app(BackupDrill::class));

        $drill = collect(app(HealthReport::class)->collect()['checks'])->firstWhere('key', 'drill');

        $this->assertSame(HealthReport::DEGRADED, $drill['status']);
        $this->assertStringContainsString('בעיות', $drill['detail']);
    }

    /**
     * Turning the nightly run off does not unfind what a drill found — and
     * somebody running manual backups is precisely who presses the button.
     */
    public function test_health_reports_a_failed_manual_drill_with_automation_off(): void
    {
        $backup = $this->backup();
        Storage::disk('backups')->delete($backup->path);

        (new DrillBackupJob(manual: true))->handle(app(BackupDrill::class));
        config(['backup.enabled' => false]);

        $drill = collect(app(HealthReport::class)->collect()['checks'])->firstWhere('key', 'drill');

        $this->assertSame(HealthReport::DEGRADED, $drill['status']);
    }

    /**
     * An archive found in the bucket by the import has no finished_at when the
     * destination cannot say when it was written. PostgreSQL sorts NULLs first
     * in a descending order, so one such row would win for ever and the drill
     * would re-read it every month while the newest archive went unopened.
     */
    public function test_an_undated_archive_does_not_displace_the_newest_one(): void
    {
        $newest = $this->backup();

        Backup::query()->create([
            'status' => $newest->status,
            'disk' => $newest->disk,
            'path' => 'archives/imported.zip',
            'size_bytes' => $newest->size_bytes,
            'finished_at' => null,
        ]);

        $this->assertSame($newest->id, app(BackupDrill::class)->latest()?->id);
    }

    public function test_a_drill_older_than_the_window_is_reported(): void
    {
        $backup = $this->backup();
        $backup->forceFill(['drilled_at' => now()->subDays(90), 'drill_report' => ['problems' => []]])->save();

        $drill = collect(app(HealthReport::class)->collect()['checks'])->firstWhere('key', 'drill');

        $this->assertSame(HealthReport::DEGRADED, $drill['status']);
    }
}
