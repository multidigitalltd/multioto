<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Mail\NotificationMail;
use App\Models\Backup;
use App\Models\SystemLog;
use App\Support\EmailList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Puts a backup back.
 *
 * Every row of every business table is replaced, so the order of operations is
 * the whole design: refuse an archive whose schema does not match the code
 * running now, wipe children before parents and insert parents before children,
 * do it all inside one transaction so a failure leaves the database as it was,
 * and hand the sequences back afterwards so the next insert does not collide
 * with a restored id.
 */
class BackupRestorer
{
    /** Rows sent per insert — big enough to be quick, small enough for any driver. */
    private const CHUNK = 500;

    /** Bytes pulled per read when checking a member is intact. */
    private const READ_CHUNK = 262144;

    /** How many external references to gather before calling it enough. */
    private function artifactLimit(): int
    {
        return max(1, (int) config('backup.reconcile_limit', 5000));
    }

    /** When the row was last touched to say this restore is still alive. */
    private ?float $lastBeat = null;

    /** Open handle on the journal of file writes, for undoing after a kill. */
    private mixed $journal = null;

    /**
     * Identifies this run's journal. Written to the backup row INSIDE the
     * replacement transaction, so its presence there is the same fact as the
     * transaction having committed — which is what tells a later recovery
     * whether the archived files belong beside the rows that are live now.
     */
    private ?string $journalToken = null;

    private ?int $journalOwner = null;

    /**
     * Entries whose undo failed. They keep their staged copies and stay in the
     * journal: throwing away the only remaining copy of a live file because
     * putting it back did not work is the one outcome worse than the failure.
     *
     * @var list<array{disk: string, path: string, from: string|null}>
     */
    private array $unrecovered = [];

    public function __construct(private BackupArchive $archive) {}

    public function restore(Backup $backup): void
    {
        // started_at as well as running: a claim that never got as far as here
        // may be taken over later, and one that did may not.
        $backup->update([
            'restore_status' => BackupStatus::Running,
            'restore_error' => null,
            'restore_started_at' => now(),
            // A restore that is under way has not completed, whatever an
            // earlier one did — and the mark is what tells the failure handler
            // whether this attempt may be recorded as failed.
            'restored_at' => null,
        ]);

        // A restore that was killed mid-write left the live files half
        // replaced and nothing running to put them back. Doing it here as well
        // as on demand means the next attempt does not build on that mess.
        $this->recoverInterruptedFiles();

        $this->journalToken = (string) Str::uuid();
        $this->journalOwner = $backup->id;
        $this->unrecovered = [];

        $local = tempnam(sys_get_temp_dir(), 'multioto-restore-');
        $sequenceError = null;
        $previous = [];
        $staged = [];
        $committed = false;
        $discarded = [];
        $truncated = false;

        try {
            $this->download($backup, $local);

            $zip = new ZipArchive;

            if ($zip->open($local) !== true) {
                throw new RuntimeException('קובץ הגיבוי פגום או לא ניתן לפתיחה.');
            }

            try {
                $manifest = $this->archive->manifestOf($local);
                $this->verify($manifest);

                $declared = (array) ($manifest['tables'] ?? []);
                [$order, $deferred] = $this->tableOrder(array_keys($declared));

                // Checked BEFORE anything is touched: a missing member found
                // half way through would leave a table emptied, or live files
                // half overwritten with older contents, and the failure would
                // arrive too late to undo either.
                $this->assertComplete($zip, $order, $backup);
                $this->assertFilesReadable($zip, $manifest, $backup);

                // The staged originals live OUT here, not inside the callback:
                // a commit can still fail after the callback returns, and the
                // one thing that could put the files back must not have been
                // thrown away by then.
                try {
                    DB::transaction(function () use ($zip, $order, $deferred, $declared, $manifest, &$previous, &$staged, &$discarded, &$truncated): void {
                        // Shut the writers out for the duration. The panel and the
                        // queue workers keep running during a restore, and a row
                        // committed after its table was emptied would survive
                        // alongside the archived ones — a database that is neither
                        // the backup nor what was there before, reported as a
                        // success. Anything mid-write waits; anything that cannot
                        // wait fails loudly rather than half-applying.
                        $this->lockTables($order);

                        // Who pressed which backup button. The history table is
                        // not restored, but its rows point at users that ARE —
                        // and deleting those users nulls the reference on the
                        // way past, quietly turning every manual backup in the
                        // list into an automatic one.
                        $attribution = $this->rememberAttribution($order);

                        // What the outside world still knows about and this
                        // snapshot does not: a Cardcom page a customer can
                        // still pay into, a Linet document already emailed.
                        // The restore cannot recall either — it can only make
                        // sure somebody is told which ones lost their row.
                        $orphaned = $this->externalReferences($order);

                        // Children first: a parent row cannot go while something
                        // still points at it.
                        foreach (array_reverse($order) as $table) {
                            DB::table($table)->delete();
                        }

                        $pending = [];

                        foreach ($order as $table) {
                            $loaded = $this->loadTable($zip, $table, $deferred[$table] ?? [], $pending);

                            // The manifest says how many rows this table had. A
                            // truncated member would otherwise restore quietly as a
                            // shorter table — the failure nobody notices.
                            if (array_key_exists($table, $declared) && $loaded !== (int) $declared[$table]) {
                                throw new RuntimeException(
                                    "טבלה \"{$table}\" בגיבוי פגומה: {$loaded} שורות במקום {$declared[$table]}."
                                );
                            }
                        }

                        // The back-references held back to break a cycle, now that
                        // every row they point at exists.
                        $this->applyDeferred($pending);

                        $this->reapplyAttribution($attribution);

                        // Compared AFTER the archive has been loaded: what the
                        // backup puts back is not lost, and reporting it would
                        // bury the handful that genuinely are.
                        $discarded = $this->referencesNotRestored($orphaned);
                        // Strictly more: the collector deliberately fetches one
                        // reference beyond the ceiling, so "exactly the ceiling"
                        // means every reference was examined. Calling that
                        // truncated would send the team to audit Cardcom and
                        // Linet by hand over a scan that finished.
                        $truncated = count($orphaned) > $this->artifactLimit();

                        // Files INSIDE the transaction, which makes the restore
                        // all-or-nothing the useful way round: a file that cannot
                        // be written rolls the database back to what it was,
                        // instead of leaving it replaced with its attachments
                        // missing and nothing left to compare against.
                        $this->restoreFiles($zip, $manifest, $previous, $staged);

                        // The commit marker, written where the commit itself
                        // decides its fate. Killed a moment later, the journal
                        // on disk looks exactly like a rolled-back restore —
                        // and putting those files back would strip the archive
                        // files off a database that IS the archive.
                        DB::table('backups')->where('id', $this->journalOwner)
                            ->update(['restore_journal' => $this->journalToken]);
                    });

                    $committed = true;
                } catch (Throwable $e) {
                    // Rows roll back on their own; files do not.
                    $this->unrecovered = $this->putFilesBack($previous);

                    throw $e;
                } finally {
                    // Both in one place: the journal is only meaningful while
                    // the copies it points at are still there — so anything
                    // still waiting to be put back keeps both.
                    $this->discard($staged, $this->stagedStillNeeded());
                    $this->closeJournal();
                }

                // AFTER the commit, never inside it: setval() is not
                // transactional on PostgreSQL. Rewound from within a
                // transaction that then rolls back, the sequences would stay
                // pointing at the archive's lower maxima while the live rows
                // came back — and the next insert in production would collide
                // with an existing primary key, over and over. It takes its
                // own locks for the same reason the replacement did.
                //
                // A failure HERE is not a failed restore. The replacement is
                // committed and production is already at the archived snapshot;
                // calling that "failed" would invite a second attempt, and the
                // second attempt would delete everything accepted since the
                // first one landed. It is recorded on the completed restore
                // instead, as work still to be done.
                try {
                    $this->resetSequences($order);
                } catch (Throwable $e) {
                    $sequenceError = mb_substr($e->getMessage(), 0, 1500);
                }
            } finally {
                $zip->close();
            }

            $backup->update([
                'restore_status' => BackupStatus::Completed,
                'restored_at' => now(),
                'restore_error' => $sequenceError === null ? null
                    : 'השחזור הושלם, אך איפוס מוני המזהים נכשל: '.$sequenceError
                    .' — יש להריץ אותו שוב לפני שנוצרות רשומות חדשות, אחרת הן עלולות להתנגש במזהים משוחזרים. אין לשחזר שוב.',
            ]);

            $this->reportDiscardedArtifacts($backup, $discarded, $truncated);

            SystemLog::record(
                $sequenceError === null ? 'warning' : 'error',
                'backup',
                $sequenceError === null
                    ? "שוחזר גיבוי #{$backup->id} ({$backup->path})"
                    : "שוחזר גיבוי #{$backup->id}, אך איפוס המונים נכשל: ".mb_substr($sequenceError, 0, 200),
                ['backup_id' => $backup->id],
            );
        } catch (Throwable $e) {
            // Once the replacement has committed, nothing that fails afterwards
            // — not even writing this very row — makes the restore repeatable.
            // Production is already at the archived snapshot, and "failed" is
            // an invitation to run it again over everything accepted since.
            //
            // Not rethrown, either: a job that ends in an exception runs its
            // failure handler, and that handler cannot tell this apart from a
            // restore that never started — least of all when the write meant to
            // record the difference is the thing that just failed. The fault is
            // recorded and the job ends quietly rather than reopening a
            // finished restore.
            if ($committed) {
                $this->recordCommittedWithFault($backup, $e);

                return;
            }

            $backup->update([
                'restore_status' => BackupStatus::Failed,
                'restore_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            SystemLog::record('error', 'backup', 'שחזור נכשל: '.mb_substr($e->getMessage(), 0, 300), [
                'backup_id' => $backup->id,
            ]);

            throw $e;
        } finally {
            if (is_string($local) && file_exists($local)) {
                unlink($local);
            }
        }
    }

    /**
     * Why this archive cannot be restored right now, or null when it can. Used
     * by the screen to disable the button with a reason instead of letting the
     * operator find out halfway through.
     *
     * @param  bool  $adoptUnstarted  For the console command: a claim the queue
     *                                took and never acted on is not something to
     *                                wait for when the worker is stopped — which
     *                                is precisely what the restore procedure
     *                                asks the operator to do. A restore that is
     *                                actually running still blocks.
     */
    public function blockedReason(Backup $backup, bool $adoptUnstarted = false): ?string
    {
        if ($backup->status !== BackupStatus::Completed) {
            return 'הגיבוי לא הושלם — אין ממה לשחזר.';
        }

        $unstartedClaim = $backup->restore_status === BackupStatus::Running
            && $backup->restore_started_at === null;

        if ($backup->restore_status === BackupStatus::Running
            && ! $backup->restoreClaimExpired()
            && ! ($adoptUnstarted && $unstartedClaim)) {
            // A second run would finish AFTER the first and put the same old
            // snapshot back, wiping everything accepted in between. Unless the
            // claim was never taken up at all — see restoreClaimExpired().
            return 'שחזור מהגיבוי הזה כבר רץ.';
        }

        // A restore that landed but left something to repair — the sequences.
        // Running it again is the worst possible response: production is
        // already at this snapshot, so the second run would only delete what
        // has been accepted since. The row is cleared by fixing it, not by
        // repeating it.
        if ($backup->restore_status === BackupStatus::Completed && $backup->restore_error !== null) {
            return 'השחזור הזה כבר בוצע ונותרה בו פעולה לתיקון — אין להריץ אותו שוב. ראו את שגיאת השחזור.';
        }

        // A destination that cannot answer is not the same as a missing file,
        // and neither may take the screen down: this runs while the table is
        // being drawn, and a broken destination is exactly what the operator
        // came here to fix.
        $present = rescue(fn (): ?bool => $backup->existsOnDisk(), null, report: false);

        if ($present === null) {
            return 'לא ניתן להגיע ליעד האחסון כרגע — נסו שוב, או בדקו את הגדרות היעד.';
        }

        if (! $present) {
            return 'קובץ הגיבוי אינו נמצא ביעד האחסון.';
        }

        $migrations = (array) ($backup->manifest['migrations'] ?? []);

        if ($migrations !== [] && $this->migrationDrift($migrations) !== []) {
            return 'הגיבוי נלקח ממבנה בסיס נתונים אחר (גרסה שונה) — שחזור עלול להשאיר נתונים חסרים.';
        }

        return null;
    }

    /**
     * Record a restore that landed but whose tail end did not.
     *
     * Written through rescue(): the reason bookkeeping failed is often that the
     * database is unreachable, and this is bookkeeping too. Failing to say
     * "completed" leaves the row on "running", which still refuses another
     * attempt — the safe direction when nothing can be written at all.
     */
    private function recordCommittedWithFault(Backup $backup, Throwable $e): void
    {
        rescue(fn () => $backup->update([
            'restore_status' => BackupStatus::Completed,
            'restored_at' => now(),
            'restore_error' => 'השחזור הושלם והנתונים הוחלפו, אך פעולה שאחריו נכשלה: '
                .mb_substr($e->getMessage(), 0, 1500)
                .' — יש לבדוק את המונים ואת הקבצים ידנית. אין לשחזר שוב.',
        ]), report: false);

        rescue(fn () => SystemLog::record('error', 'backup',
            "שוחזר גיבוי #{$backup->id}, אך פעולה אחרי ההחלפה נכשלה: ".mb_substr($e->getMessage(), 0, 200),
            ['backup_id' => $backup->id],
        ), report: false);

        // And to the file log too, which needs no database — the one place this
        // can still be read when the database is what failed.
        Log::error("שוחזר גיבוי #{$backup->id}, אך פעולה אחרי ההחלפה נכשלה.", [
            'backup_id' => $backup->id,
            'exception' => $e->getMessage(),
        ]);
    }

    private function download(Backup $backup, string $to): void
    {
        $stream = Storage::disk($backup->disk)->readStream($backup->path);

        if ($stream === null || $stream === false) {
            throw new RuntimeException('קובץ הגיבוי אינו נמצא ביעד האחסון.');
        }

        $out = fopen($to, 'wb');

        try {
            // Copied in pieces rather than in one call, so the row can be
            // touched as it goes. Pulling a large archive off a remote
            // destination can outlast the window the operation gate believes a
            // running restore in — and a restore that has aged out of that
            // window lets a charge through, which is the one thing this whole
            // arrangement exists to prevent.
            while (! feof($stream)) {
                $copied = stream_copy_to_stream($stream, $out, self::READ_CHUNK);

                if ($copied === false) {
                    throw new RuntimeException('קריאת קובץ הגיבוי מהיעד נכשלה.');
                }

                // A read that returns nothing and still does not report the end
                // would spin here for ever. Stopping is safe: an archive that
                // came down short cannot pass the checks that follow — the zip
                // directory and every checksum in it sit at the end of the file.
                if ($copied === 0) {
                    break;
                }

                $this->beat($backup);
            }
        } finally {
            fclose($out);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Say "still here" on the backup row, at most once a minute.
     *
     * OperationGate answers "is a restore under way" from how recently the row
     * was written to, because a row abandoned by a dead worker must not stop
     * the business billing for ever. The price of that is that a long restore
     * has to keep proving it is alive. Everything from the transaction onwards
     * is covered by the table locks it takes — a charge would block on its
     * write — so what needs this is the work BEFORE it: downloading and
     * checking an archive that can be very large.
     *
     * Best-effort by design: failing to write a heartbeat must not fail a
     * restore that is otherwise fine.
     */
    private function beat(Backup $backup): void
    {
        if ($this->lastBeat !== null && $this->lastBeat > microtime(true) - 60) {
            return;
        }

        $this->lastBeat = microtime(true);

        rescue(fn () => $backup->touch(), null, report: false);
    }

    /** @param  array<string, mixed>|null  $manifest */
    private function verify(?array $manifest): void
    {
        if ($manifest === null) {
            throw new RuntimeException('לא נמצא מניפסט בקובץ הגיבוי — הקובץ אינו גיבוי תקין.');
        }

        if ((int) ($manifest['format'] ?? 0) !== BackupArchive::FORMAT) {
            throw new RuntimeException('פורמט הגיבוי אינו נתמך בגרסה הזו.');
        }

        $drift = $this->migrationDrift((array) ($manifest['migrations'] ?? []));

        if ($drift !== []) {
            // Restoring across a schema change means rows for columns that no
            // longer exist, or new columns left empty — the kind of damage that
            // only shows up later.
            throw new RuntimeException(
                'מבנה בסיס הנתונים השתנה מאז הגיבוי ('.count($drift).' שינויים) — השחזור נעצר.'
            );
        }
    }

    /**
     * Migrations that differ between the archive and the database right now.
     *
     * @param  list<string>  $recorded
     * @return list<string>
     */
    private function migrationDrift(array $recorded): array
    {
        $current = $this->archive->migrations();

        return array_values(array_merge(
            array_diff($recorded, $current),
            array_diff($current, $recorded),
        ));
    }

    /**
     * Tables sorted so every table comes after the ones it points at, plus the
     * columns that had to be held back to make that possible.
     *
     * Derived from the real foreign keys rather than a hand-kept list, so a new
     * relation cannot quietly break the restore. Some relations are genuinely
     * circular — a customer points at their default payment token and the token
     * points back at the customer — and no order satisfies both at once. Such a
     * cycle is broken by leaving the nullable side NULL on insert and filling it
     * in once everything exists, which is how the normal saved-card state gets
     * restored at all.
     *
     * @param  list<string>  $tables
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    private function tableOrder(array $tables): array
    {
        $tables = array_values(array_filter($tables, fn (string $t): bool => Schema::hasTable($t)));

        /** @var array<string, list<string>> $deferred table => held-back columns */
        $deferred = [];
        $ordered = [];
        $remaining = $tables;

        while ($remaining !== []) {
            $deps = $this->dependencies($remaining, $tables, $deferred);

            $ready = array_values(array_filter(
                $remaining,
                fn (string $t): bool => array_diff($deps[$t], $ordered) === [],
            ));

            if ($ready === []) {
                // Nothing can go next: a cycle. Break it on a nullable side.
                if ($this->breakCycle($remaining, $deferred)) {
                    continue;
                }

                // Every edge in the cycle is NOT NULL — nothing can be held
                // back. Append and let the insert fail loudly rather than loop.
                $ordered = array_merge($ordered, $remaining);
                break;
            }

            $ordered = array_merge($ordered, $ready);
            $remaining = array_values(array_diff($remaining, $ready));
        }

        return [$ordered, $deferred];
    }

    /**
     * table => the tables it points at, ignoring columns already held back.
     *
     * @param  list<string>  $remaining
     * @param  list<string>  $all
     * @param  array<string, list<string>>  $deferred
     * @return array<string, list<string>>
     */
    private function dependencies(array $remaining, array $all, array $deferred): array
    {
        $deps = [];

        foreach ($remaining as $table) {
            $held = $deferred[$table] ?? [];

            $deps[$table] = collect(Schema::getForeignKeys($table))
                ->reject(fn (array $fk): bool => array_diff($fk['columns'], $held) === [])
                ->pluck('foreign_table')
                ->map(fn ($t): string => (string) $t)
                ->filter(fn (string $t): bool => $t !== $table && in_array($t, $all, true))
                ->unique()
                ->values()
                ->all();
        }

        return $deps;
    }

    /**
     * Hold back one nullable foreign key inside the stuck set, so the ordering
     * can proceed. Returns false when no edge can be held back.
     *
     * @param  list<string>  $remaining
     * @param  array<string, list<string>>  $deferred
     */
    private function breakCycle(array $remaining, array &$deferred): bool
    {
        foreach ($remaining as $table) {
            $nullable = $this->nullableColumns($table);

            foreach (Schema::getForeignKeys($table) as $fk) {
                if (! in_array((string) $fk['foreign_table'], $remaining, true)) {
                    continue;
                }

                $columns = array_values($fk['columns']);
                $alreadyHeld = array_diff($columns, $deferred[$table] ?? []) === [];

                if ($alreadyHeld || array_diff($columns, $nullable) !== []) {
                    continue; // Already held back, or NOT NULL and unholdable.
                }

                $deferred[$table] = array_values(array_unique(
                    array_merge($deferred[$table] ?? [], $columns)
                ));

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function nullableColumns(string $table): array
    {
        return collect(Schema::getColumns($table))
            ->filter(fn (array $column): bool => (bool) ($column['nullable'] ?? false))
            ->pluck('name')
            ->map(fn ($n): string => (string) $n)
            ->values()
            ->all();
    }

    /**
     * Every table the manifest declares must actually be in the archive, and
     * readable, before a single row is deleted.
     *
     * @param  list<string>  $tables
     */
    private function assertComplete(ZipArchive $zip, array $tables, Backup $backup): void
    {
        foreach ($tables as $table) {
            // Checking a large archive is long work in itself, and it happens
            // before the transaction's locks are there to hold the writers off.
            $this->beat($backup);

            // Read to the end, not just opened. A bit flip inside a row can
            // leave the line structure intact, so the row count would match and
            // the restore would commit quietly altered business data — the
            // checksum is the only thing that notices.
            $this->assertMemberIntact(
                $zip,
                "database/{$table}.ndjson",
                "חסרים נתוני הטבלה \"{$table}\"",
                "נתוני הטבלה \"{$table}\" פגומים",
                $backup,
            );
        }
    }

    /**
     * Every external reference the system currently holds — a Cardcom page or
     * transaction, a Linet document.
     *
     * Collected BEFORE the tables are replaced so it can be compared with what
     * the archive puts back: the ones that do not come back are the ones the
     * outside world still knows about and this system no longer does.
     *
     * @param  list<string>  $order
     * @return list<array{table: string, column: string, value: string, label: string}>
     */
    private function externalReferences(array $order): array
    {
        $refs = [];
        // One past the ceiling, so "how many were found" can tell a scan that
        // finished on the last allowed reference from one that had more to read.
        $limit = $this->artifactLimit() + 1;

        $collect = function (string $table, array $columns, callable $label) use (&$refs, $order, $limit): void {
            if (! in_array($table, $order, true) || count($refs) >= $limit) {
                return;
            }

            DB::table($table)
                ->where(function ($q) use ($columns) {
                    foreach ($columns as $column) {
                        $q->orWhereNotNull($column);
                    }
                })
                ->orderBy('id')
                ->select(array_merge(['id'], $columns))
                ->chunkById(self::CHUNK, function ($rows) use (&$refs, $table, $columns, $label, $limit): bool {
                    foreach ($rows as $row) {
                        foreach ($columns as $column) {
                            if (blank($row->{$column})) {
                                continue;
                            }

                            // Checked per reference, not per chunk: a ceiling
                            // that only applies between chunks is not a ceiling,
                            // and the report says how many were compared.
                            if (count($refs) >= $limit) {
                                return false;
                            }

                            $refs[] = [
                                'table' => $table,
                                'column' => $column,
                                'value' => (string) $row->{$column},
                                'label' => $label($row, $column),
                            ];
                        }
                    }

                    // Bounded, but never silently: reaching the ceiling travels
                    // with the report.
                    return count($refs) < $limit;
                });
        };

        $collect('charges', ['cardcom_low_profile_id', 'cardcom_transaction_id', 'proforma_document_id'],
            fn ($row, $column): string => match ($column) {
                'cardcom_low_profile_id' => "חיוב #{$row->id}: עמוד תשלום {$row->cardcom_low_profile_id}",
                'cardcom_transaction_id' => "חיוב #{$row->id}: עסקה בקארדקום {$row->cardcom_transaction_id}",
                default => "חיוב #{$row->id}: חשבונית עסקה {$row->proforma_document_id}",
            });

        $collect('invoices', ['linet_document_id'],
            fn ($row): string => "חשבונית #{$row->id}: מסמך לינט {$row->linet_document_id}");

        return $refs;
    }

    /**
     * Of those references, the ones the archive did not bring back.
     *
     * Without this the report would name every payment and document the backup
     * restores perfectly well — noise that buries the handful that matter, and
     * an alert nobody reads is the same as no alert.
     *
     * @param  list<array{table: string, column: string, value: string, label: string}>  $refs
     * @return list<string>
     */
    private function referencesNotRestored(array $refs): array
    {
        $missing = [];

        foreach (collect($refs)->groupBy(fn (array $ref): string => $ref['table'].'|'.$ref['column']) as $key => $group) {
            [$table, $column] = explode('|', (string) $key);

            foreach ($group->chunk(self::CHUNK) as $chunk) {
                $values = $chunk->pluck('value')->all();
                $restored = DB::table($table)->whereIn($column, $values)->pluck($column)->all();

                foreach ($chunk as $ref) {
                    if (! in_array($ref['value'], $restored, true)) {
                        $missing[] = $ref['label'];
                    }
                }
            }
        }

        return $missing;
    }

    /**
     * Tell the team what the restore took away from them.
     *
     * The whole list is kept on the backup row — the one table a restore does
     * not replace, so it is still there afterwards to work through. The email
     * carries a sample and the count, because an email is where someone finds
     * out, not where they reconcile.
     *
     * A scan that hit its ceiling is reported even when it found nothing. The
     * references are collected by ascending id, so a system with more than the
     * ceiling's worth of older references — all of them safely in the archive —
     * fills the quota before it ever reaches the newest rows, which are exactly
     * the ones a restore is likely to drop. "Found none" and "never looked" are
     * not the same answer, and only one of them means everything is accounted
     * for.
     *
     * @param  list<string>  $discarded
     */
    private function reportDiscardedArtifacts(Backup $backup, array $discarded, bool $truncated): void
    {
        if ($discarded === [] && ! $truncated) {
            return;
        }

        $count = count($discarded);
        $ceiling = $this->artifactLimit();
        $note = $truncated
            ? " הבדיקה נעצרה בתקרה של {$ceiling} רשומות ולא הגיעה לסופן, ולכן ייתכן שיש נוספות שלא נבדקו."
            : '';

        $backup->update(['restore_report' => [
            'at' => now()->toIso8601String(),
            'count' => $count,
            'truncated' => $truncated,
            'items' => $discarded,
        ]]);

        $headline = $count > 0
            ? "{$count} חיובים/מסמכים חיצוניים אינם קיימים בגיבוי."
            : 'לא נמצאו חיובים/מסמכים חסרים בטווח שנבדק.';

        SystemLog::record('warning', 'backup', "שחזור גיבוי #{$backup->id}: {$headline}{$note}", [
            'backup_id' => $backup->id,
        ]);

        $to = EmailList::parse(config('billing.notifications.team_email'));

        if ($to === []) {
            return;
        }

        $body = 'השחזור החזיר את המערכת לתמונת המצב של הגיבוי. ';

        if ($count > 0) {
            $sample = implode("\n", array_slice($discarded, 0, 20));

            $body .= "{$count} רשומות היו קיימות לפני השחזור ואינן בגיבוי, ולכן אין להן יותר שורה במערכת — "
                ."אבל קארדקום ולינט עדיין מכירים אותן.{$note}\n\nלדוגמה:\n{$sample}\n\n"
                .'הרשימה המלאה שמורה על שורת הגיבוי במסך "גיבוי ושחזור" — כפתור "רשימת ההתאמות". ';
        } else {
            $body .= "בטווח שנבדק לא נמצאו רשומות חסרות, אבל הבדיקה לא הושלמה:{$note}\n\n"
                .'הרשומות נסרקות מהישנות לחדשות, ולכן דווקא החיובים והמסמכים האחרונים — אלה שסביר שאינם בגיבוי — '
                ."הם שלא נבדקו.\n\n";
        }

        rescue(fn () => Mail::to($to)->send(new NotificationMail(
            'שוחזר גיבוי — יש לבדוק חיובים ומסמכים מול קארדקום ולינט',
            $body.'יש להשוות מול הדוחות בקארדקום ובלינט לפני שממשיכים לגבות.',
        )), report: false);
    }

    /**
     * Who pressed the button, remembered across the restore.
     *
     * The backup history is deliberately not restored — putting it back would
     * delete the list of archives while running from one of them — but its rows
     * reference users, and those ARE restored. Deleting the live users nulls
     * every reference on the way past, and re-inserting the archived users does
     * not reconnect them: the manual backups in the list would all become
     * "automatic" the moment somebody restored.
     *
     * @param  list<string>  $order  the tables being restored
     * @return array<int, int> backup id => user id
     */
    private function rememberAttribution(array $order): array
    {
        if (! in_array('users', $order, true) || in_array('backups', $order, true)) {
            return [];
        }

        return DB::table('backups')->whereNotNull('user_id')->pluck('user_id', 'id')->all();
    }

    /**
     * @param  array<int, int>  $attribution  backup id => user id
     */
    private function reapplyAttribution(array $attribution): void
    {
        if ($attribution === []) {
            return;
        }

        // Only for users the archive actually brought back — a backup taken by
        // somebody who did not exist yet when the archive was made has nobody
        // to point at, and inventing one would be worse than saying nothing.
        $restored = DB::table('users')->whereIn('id', array_unique(array_values($attribution)))->pluck('id')->all();

        foreach ($attribution as $backupId => $userId) {
            if (in_array($userId, $restored)) {
                DB::table('backups')->where('id', $backupId)->update(['user_id' => $userId]);
            }
        }
    }

    /**
     * One archive member, present and readable from beginning to end.
     *
     * The read is what verifies the checksum: a damaged payload still has a
     * valid directory entry, so opening it proves nothing. And the check runs
     * past the last byte on purpose — the checksum is verified on the read that
     * follows the data, so stopping at feof() skips the one read that reports
     * the damage.
     */
    private function assertMemberIntact(ZipArchive $zip, string $member, string $missing, string $damaged, Backup $backup): void
    {
        $stream = $zip->getStream($member);

        if ($stream === false) {
            throw new RuntimeException("קובץ הגיבוי פגום: {$missing}.");
        }

        try {
            while (true) {
                $chunk = @fread($stream, self::READ_CHUNK);

                if ($chunk === false) {
                    throw new RuntimeException("קובץ הגיבוי פגום: {$damaged}.");
                }

                if ($chunk === '') {
                    break;
                }

                // Inside the loop, not once per member: a single table's data
                // can be the biggest thing in the archive, and a scan that
                // takes longer than the gate's window would let a charge
                // through while it reads.
                $this->beat($backup);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Every declared file must be present and readable before the first live
     * file is overwritten.
     *
     * File writes cannot be rolled back the way rows can, so this is where the
     * damage is prevented rather than undone: once every member is known good,
     * the only thing left that can fail mid-way is the storage itself.
     *
     * Each member is READ to the end, not merely opened. A corrupt payload or a
     * bad CRC still has a valid directory entry, so getStream() succeeds and the
     * failure would surface later — with earlier live files already overwritten.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function assertFilesReadable(ZipArchive $zip, array $manifest, Backup $backup): void
    {
        foreach ($this->declaredFiles($zip, $manifest) as $entry) {
            $this->beat($backup);

            $this->assertMemberIntact(
                $zip,
                'files/'.$entry,
                "לא ניתן לקרוא את \"{$entry}\"",
                "תוכן פגום ב\"{$entry}\"",
                $backup,
            );
        }
    }

    /**
     * Stream one table's rows out of the archive and insert them; returns how
     * many went in, so it can be checked against the manifest.
     *
     * @param  list<string>  $deferred  columns held back to break a cycle
     * @param  array<string, array<int|string, array<string, mixed>>>  $pending  collects those values
     */
    private function loadTable(ZipArchive $zip, string $table, array $deferred, array &$pending): int
    {
        $stream = $zip->getStream("database/{$table}.ndjson");

        if ($stream === false) {
            throw new RuntimeException("קובץ הגיבוי פגום: לא ניתן לקרוא את נתוני הטבלה \"{$table}\".");
        }

        $buffer = [];
        $loaded = 0;

        try {
            while (($line = fgets($stream)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);

                if (! is_array($row)) {
                    throw new RuntimeException("קובץ הגיבוי פגום: שורה לא תקינה בטבלה \"{$table}\".");
                }

                $row = $this->decodeRow($row);
                $row = $this->holdBack($table, $row, $deferred, $pending);

                $buffer[] = $row;
                $loaded++;

                if (count($buffer) >= self::CHUNK) {
                    DB::table($table)->insert($buffer);
                    $buffer = [];
                }
            }
        } finally {
            fclose($stream);
        }

        if ($buffer !== []) {
            DB::table($table)->insert($buffer);
        }

        return $loaded;
    }

    /**
     * Null the held-back columns on the way in and remember their real values
     * against the row's id, to be written once the cycle's other side exists.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $deferred
     * @param  array<string, array<int|string, array<string, mixed>>>  $pending
     * @return array<string, mixed>
     */
    private function holdBack(string $table, array $row, array $deferred, array &$pending): array
    {
        if ($deferred === [] || ! array_key_exists('id', $row)) {
            return $row;
        }

        $held = [];

        foreach ($deferred as $column) {
            if (($row[$column] ?? null) !== null) {
                $held[$column] = $row[$column];
                $row[$column] = null;
            }
        }

        if ($held !== []) {
            $pending[$table][$row['id']] = $held;
        }

        return $row;
    }

    /**
     * @param  array<string, array<int|string, array<string, mixed>>>  $pending
     */
    private function applyDeferred(array $pending): void
    {
        foreach ($pending as $table => $rows) {
            foreach ($rows as $id => $values) {
                DB::table($table)->where('id', $id)->update($values);
            }
        }
    }

    /**
     * Undo the base64 marker BackupArchive writes for non-UTF-8 values.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function decodeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_array($value) && array_key_exists('__b64', $value)) {
                $row[$column] = base64_decode((string) $value['__b64'], true) ?: null;
            }
        }

        return $row;
    }

    /**
     * Take an exclusive lock on every table being replaced, so nothing can be
     * written to them while the restore runs.
     *
     * PostgreSQL only: SQLite already serialises writers for the length of a
     * write transaction. The bounded wait matters — without it a restore could
     * hang for ever behind one long-running query, holding the operation lock
     * and telling nobody.
     *
     * @param  list<string>  $tables
     */
    private function lockTables(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql' || $tables === []) {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '30s'");

        $names = collect($tables)
            ->map(fn (string $table): string => DB::getQueryGrammar()->wrapTable($table))
            ->implode(', ');

        DB::statement("LOCK TABLE {$names} IN ACCESS EXCLUSIVE MODE");
    }

    /**
     * Hand the id sequences back after inserting explicit ids. PostgreSQL keeps
     * its own counter, so without this the next insert would collide with a row
     * that was just restored. SQLite derives the next id from the data itself
     * and needs nothing.
     *
     * The counter is only ever moved FORWARD. MAX(id) alone is not safe here:
     * an insert that has already taken a sequence value but not yet committed
     * is invisible to it, so a plain reset could hand that id out a second time
     * once the row lands. The sequence's own last value does see it — taking
     * the greater of the two can lose an id, never reuse one.
     *
     * @param  list<string>  $tables
     */
    protected function resetSequences(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Its own short transaction, purely to hold the table locks: without
        // them a writer could take a sequence value between the read and the
        // setval, and that reserved id would be handed out twice. setval itself
        // is unaffected by the transaction — it does not roll back.
        DB::transaction(function () use ($tables): void {
            $this->lockTables($tables);

            $this->rewindSequences($tables);
        });
    }

    /**
     * The setval half of the reset, run with the tables already locked.
     *
     * @param  list<string>  $tables
     */
    private function rewindSequences(array $tables): void
    {
        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, 'id')) {
                continue;
            }

            // Resolve the sequence FIRST. Not every id is a counter —
            // notifications.id is a UUID — and asking setval about a table that
            // has no sequence, or coalescing a uuid with an integer, throws.
            // That would fail every restore on PostgreSQL at the very last
            // step, with the database already replaced.
            $sequence = DB::scalar('SELECT pg_get_serial_sequence(?, ?)', [$table, 'id']);

            if (! is_string($sequence) || $sequence === '') {
                continue;
            }

            // Bindings only — the table name comes from the schema listing, not
            // from anything a user typed.
            DB::statement(
                'SELECT setval(?, GREATEST('
                .'COALESCE((SELECT MAX(id) FROM '.DB::getQueryGrammar()->wrapTable($table).'), 1), '
                .'COALESCE(pg_sequence_last_value(?::regclass), 1)'
                .'), true)',
                [$sequence, $sequence],
            );
        }
    }

    /**
     * Put the uploaded files back on the disks they came from.
     *
     * The upload disks are configured with throw => false, so a failed write
     * returns false rather than raising. Ignoring that would report a completed
     * restore whose attachments and logo never came back — with the database
     * already replaced, so there is nothing to compare against and notice.
     *
     * Files uploaded AFTER the backup are deliberately left where they are.
     * Deleting them would be tidier and was tried, but it cannot be made safe:
     * SignupController writes its signature to disk BEFORE the insert that a
     * restore blocks on, so no database lock can protect a file that exists
     * before the database is ever touched — the cleanup would delete a
     * signature whose customer row is committed moments later. Making it safe
     * needs every upload path in the app to coordinate with the restore, which
     * is a far larger change than the problem deserves. An orphaned file is
     * unreachable (nothing links to it) and costs disk; a deleted one is gone.
     */
    private function restoreFiles(ZipArchive $zip, array $manifest, array &$previous, array &$temp): void
    {
        $failed = [];

        $this->writeFiles($zip, $manifest, $previous, $temp, $failed);

        if ($failed !== []) {
            throw new RuntimeException(
                'הנתונים שוחזרו אך '.count($failed).' קבצים לא הוחזרו (חסרים בגיבוי או בעיית הרשאות): '
                .implode(', ', array_slice($failed, 0, 5))
            );
        }
    }

    /**
     * The write half. Undoing it belongs to the caller, which is the only place
     * that knows whether the database commit went through.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<array{disk: string, path: string, from: string|null}>  $previous
     * @param  list<string>  $temp
     * @param  list<string>  $failed
     */
    private function writeFiles(ZipArchive $zip, array $manifest, array &$previous, array &$temp, array &$failed): void
    {
        // Driven by the list the backup WROTE, not by whatever members happen
        // to still be in the archive. Walking the members can only ever see
        // what survived — a file that went missing is invisible that way, and
        // the restore would report success without it.
        foreach ($this->declaredFiles($zip, $manifest) as $entry) {
            $slash = strpos($entry, '/');

            if ($slash === false) {
                $failed[] = $entry;

                continue;
            }

            $disk = substr($entry, 0, $slash);
            $path = substr($entry, $slash + 1);

            // Only disks this installation backs up, and only paths that stay
            // inside them — an archive is a file like any other, and a crafted
            // one must not be able to write outside the disk root.
            if ($path === '' || ! array_key_exists($disk, (array) config('backup.files', []))) {
                continue;
            }

            if (in_array('..', explode('/', $path), true) || str_starts_with($path, '/')) {
                continue;
            }

            $stream = $zip->getStream("files/{$disk}/{$path}");

            if ($stream === false) {
                $failed[] = "{$disk}:{$path}";

                continue;
            }

            // What is there now, kept aside first. A write that fails half way
            // through the list would otherwise leave the live database (rolled
            // back with the transaction) beside files from the archive — a
            // pairing that was never true at any point in time.
            $record = $this->setAside($disk, $path, $temp);
            $previous[] = $record;

            // Written to disk BEFORE the file is overwritten. A worker killed
            // on timeout or by the OOM killer never reaches the undo path, and
            // the database rolls itself back — leaving the old rows beside
            // files from the archive, with nothing recording which ones. The
            // journal is what another process reads to finish the undo.
            $this->journal($record);

            try {
                if (Storage::disk($disk)->put($path, $stream) === false) {
                    $failed[] = "{$disk}:{$path}";
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($failed !== []) {
                return;
            }
        }
    }

    /**
     * Copy what is currently at a path somewhere safe, so it can be put back.
     *
     * A path with nothing at it is recorded too, as null: undoing means
     * deleting the file the restore created, not leaving it behind.
     *
     * @param  list<string>  $temp
     * @return array{disk: string, path: string, from: string|null}
     */
    private function setAside(string $disk, string $path, array &$temp): array
    {
        $storage = Storage::disk($disk);

        // "No" and "cannot say" are different answers. A disk that refuses the
        // check while accepting writes would otherwise have its file recorded
        // as absent — and undoing would then delete it.
        $present = rescue(fn (): ?bool => $storage->exists($path), null, report: false);

        if ($present === null) {
            throw new RuntimeException(
                "לא ניתן לבדוק אם הקובץ \"{$disk}:{$path}\" קיים — השחזור הופסק כדי לא לדרוס אותו בלי אפשרות חזרה."
            );
        }

        if (! $present) {
            return ['disk' => $disk, 'path' => $path, 'from' => null];
        }

        // Staged under storage/, not in the system temp directory: these
        // copies are the only way back if the worker is killed mid-write, and
        // /tmp is exactly where a reboot or a tmp reaper would take them.
        $copy = tempnam($this->stagingDir(), 'prev-');
        $temp[] = $copy;

        $source = rescue(fn () => $storage->readStream($path), null, report: false);

        // A file that is there but cannot be read must stop the restore, not be
        // recorded as absent: recorded that way, undoing would DELETE it — the
        // live file gone for good while the database rolls back to match it.
        if (! is_resource($source)) {
            throw new RuntimeException(
                "לא ניתן לקרוא את הקובץ הקיים \"{$disk}:{$path}\" — השחזור הופסק כדי לא לדרוס אותו בלי אפשרות חזרה."
            );
        }

        // Required, not optional: without it there is nothing to check the copy
        // against, and an unchecked copy is exactly what the undo path would
        // later write over the live file.
        $expected = rescue(fn (): ?int => $storage->size($path), null, report: false);

        if ($expected === null) {
            throw new RuntimeException(
                "לא ניתן לקבוע את גודל הקובץ הקיים \"{$disk}:{$path}\" — השחזור הופסק כדי לא לדרוס אותו בלי עותק שלם."
            );
        }

        $out = fopen($copy, 'wb');

        try {
            $copied = stream_copy_to_stream($source, $out);
        } finally {
            fclose($out);
            fclose($source);
        }

        // A dropped connection ends the read early and returns a byte count,
        // not an error. Accepting a short copy here would mean undoing the
        // restore by overwriting the live file with a truncated version of
        // itself — corruption dressed up as a rollback.
        if ($copied !== $expected) {
            throw new RuntimeException(
                "העתקת הקובץ הקיים \"{$disk}:{$path}\" לשמירה זמנית נכשלה — השחזור הופסק."
            );
        }

        return ['disk' => $disk, 'path' => $path, 'from' => $copy];
    }

    /** Where staged originals live: on the storage volume, not in /tmp. */
    private function stagingDir(): string
    {
        $dir = storage_path('backups/staged');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function journalPath(): string
    {
        return storage_path('backups/restore-journal.jsonl');
    }

    /**
     * Record one file write, durably, before it happens.
     *
     * @param  array{disk: string, path: string, from: string|null}  $record
     */
    private function journal(array $record): void
    {
        if (! is_resource($this->journal)) {
            $this->stagingDir();
            $this->journal = fopen($this->journalPath(), 'ab');

            if (! is_resource($this->journal)) {
                throw new RuntimeException('לא ניתן לכתוב את יומן השחזור — השחזור הופסק לפני שנגע בקבצים.');
            }

            // Whose journal this is, and under which token, so a recovery can
            // ask the database whether that run's transaction committed.
            $this->write(['journal' => $this->journalToken, 'backup_id' => $this->journalOwner]);
        }

        $this->write($record);
    }

    /** @param  array<string, mixed>  $record */
    private function write(array $record): void
    {
        fwrite($this->journal, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        fflush($this->journal);
    }

    /**
     * The journal has done its job once the writes are settled either way —
     * unless something could not be put back, in which case what is left is
     * the whole record of it and stays on disk to be retried.
     */
    private function closeJournal(): void
    {
        if (is_resource($this->journal)) {
            fclose($this->journal);
        }

        $this->journal = null;

        if ($this->unrecovered === []) {
            if (file_exists($this->journalPath())) {
                @unlink($this->journalPath());
            }

            return;
        }

        $this->rewriteJournal($this->unrecovered);

        SystemLog::record('error', 'backup', count($this->unrecovered)
            .' קבצים לא הוחזרו למצבם הקודם ונשמרו להחזרה חוזרת — הריצו php artisan backup:recover-files.');
    }

    /**
     * Leave exactly these entries in the journal, with a header of their own.
     *
     * The token deliberately does NOT come along: a retry must decide by the
     * same question the first attempt did, and the run that wrote these is
     * over.
     *
     * @param  list<array{disk: string, path: string, from: string|null}>  $entries
     */
    private function rewriteJournal(array $entries): void
    {
        $this->stagingDir();

        $lines = [json_encode(['journal' => null, 'backup_id' => $this->journalOwner],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];

        foreach ($entries as $entry) {
            $lines[] = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        file_put_contents($this->journalPath(), implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * Staged copies that must survive: they are the only remaining version of
     * a live file whose undo has not worked yet.
     *
     * @return list<string>
     */
    private function stagedStillNeeded(): array
    {
        return array_values(array_filter(array_column($this->unrecovered, 'from')));
    }

    /**
     * Finish the undo a killed restore could not.
     *
     * Returns how many files were put back and how many still could not be —
     * the second number matters, because those keep their staged copies and
     * stay in the journal to be retried. Throwing the last remaining copy of a
     * live file away because putting it back failed would turn a bad moment
     * into permanent loss.
     *
     * A journal whose run COMMITTED is not replayed. The token it carries was
     * written to the backup row inside the replacement transaction, so finding
     * it there says the archived rows are live — and the archived files belong
     * beside them. Putting the old files back then would be the corruption,
     * not the cure.
     *
     * @return array{restored: int, failed: int}
     */
    public function recoverInterruptedFiles(): array
    {
        $path = $this->journalPath();

        if (! file_exists($path)) {
            return ['restored' => 0, 'failed' => 0];
        }

        $header = null;
        $entries = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = json_decode($line, true);

            if (! is_array($record)) {
                continue;
            }

            if (array_key_exists('journal', $record)) {
                $header = $record;

                continue;
            }

            if (isset($record['disk'], $record['path']) && array_key_exists('from', $record)) {
                $entries[] = [
                    'disk' => (string) $record['disk'],
                    'path' => (string) $record['path'],
                    'from' => $record['from'] === null ? null : (string) $record['from'],
                ];
            }
        }

        $this->journalOwner = isset($header['backup_id']) ? (int) $header['backup_id'] : null;

        if ($this->committedRun($header)) {
            // The database IS the archive. The files from the archive are the
            // right ones; only the copies of what they replaced are stale.
            $this->discard(array_values(array_filter(array_column($entries, 'from'))));
            @unlink($path);

            SystemLog::record('warning', 'backup',
                'שחזור שנקטע הושלם בבסיס הנתונים — הקבצים מהגיבוי נשארו במקומם, והעותקים הזמניים נמחקו.');

            return ['restored' => 0, 'failed' => 0];
        }

        $failed = $this->putFilesBack($entries);
        $restored = count($entries) - count($failed);

        $this->discard(
            array_values(array_filter(array_column($entries, 'from'))),
            array_values(array_filter(array_column($failed, 'from'))),
        );

        $this->unrecovered = $failed;

        if ($failed === []) {
            @unlink($path);
        } else {
            $this->rewriteJournal($failed);
        }

        SystemLog::record($failed === [] ? 'warning' : 'error', 'backup',
            "שחזור שנקטע השאיר קבצים מהגיבוי במקום הקיימים — {$restored} הוחזרו, "
            .count($failed).' נותרו להחזרה חוזרת.');

        return ['restored' => $restored, 'failed' => count($failed)];
    }

    /**
     * Did the run that wrote this journal commit its database transaction?
     *
     * @param  array<string, mixed>|null  $header
     */
    private function committedRun(?array $header): bool
    {
        $token = $header['journal'] ?? null;
        $owner = $header['backup_id'] ?? null;

        if (! is_string($token) || $token === '' || $owner === null) {
            return false;
        }

        // rescue(): a database that cannot answer is not a database that said
        // "committed", and the safe reading of silence here is to keep the
        // originals rather than discard them.
        return (bool) rescue(
            fn (): bool => Backup::whereKey((int) $owner)->where('restore_journal', $token)->exists(),
            false,
            report: false,
        );
    }

    /**
     * Is there an unfinished file rollback waiting, with nobody working on it?
     *
     * The journal exists throughout every normal restore, so its presence
     * alone says nothing. Only once no operation is running does it mean what
     * the screen would be claiming it means.
     */
    public function hasInterruptedFiles(): bool
    {
        return file_exists($this->journalPath()) && ! app(OperationGate::class)->isRunning();
    }

    /**
     * Undo the file writes of a restore that could not finish.
     *
     * Best effort by necessity — the storage that just refused a write may
     * refuse this too — so every failure is logged rather than thrown: the
     * restore is failing anyway, and the reason it failed is the more useful
     * one to surface.
     *
     * Returns the entries whose undo did NOT work, so the caller can keep
     * their staged copies and try again later.
     *
     * @param  list<array{disk: string, path: string, from: string|null}>  $previous
     * @return list<array{disk: string, path: string, from: string|null}>
     */
    private function putFilesBack(array $previous): array
    {
        $failed = [];

        foreach (array_reverse($previous) as $file) {
            try {
                if ($file['from'] === null) {
                    // These disks are configured not to throw, so the refusal
                    // arrives as a return value or not at all.
                    $undone = Storage::disk($file['disk'])->delete($file['path']);
                } else {
                    $handle = fopen($file['from'], 'rb');

                    try {
                        $undone = Storage::disk($file['disk'])->put($file['path'], $handle);
                    } finally {
                        fclose($handle);
                    }
                }

                if (! $undone) {
                    $failed[] = $file;

                    SystemLog::record('error', 'backup',
                        "הקובץ {$file['disk']}:{$file['path']} לא הוחזר למצבו הקודם — הוא נשאר בגרסה מהגיבוי.");
                }
            } catch (Throwable $e) {
                $failed[] = $file;

                SystemLog::record('error', 'backup', "החזרת הקובץ {$file['disk']}:{$file['path']} למצבו הקודם נכשלה: "
                    .mb_substr($e->getMessage(), 0, 200));
            }
        }

        return $failed;
    }

    /**
     * @param  list<string>  $temp
     * @param  list<string>  $keep  staged copies that are still the only version
     *                              of a live file and must not be deleted
     */
    private function discard(array $temp, array $keep = []): void
    {
        foreach ($temp as $file) {
            if (! in_array($file, $keep, true) && file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * The files the backup recorded.
     *
     * No fallback to "whatever members are still here": that can only see what
     * survived, so a list lost along with an attachment would look like an
     * archive that simply had fewer files. Every archive of this format carries
     * the list, and the count is checked against the manifest.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string> "disk/path"
     */
    private function declaredFiles(ZipArchive $zip, array $manifest): array
    {
        $raw = $zip->getFromName(BackupArchive::FILE_LIST);
        $list = $raw === false ? null : json_decode((string) $raw, true);

        if (! is_array($list)) {
            throw new RuntimeException('קובץ הגיבוי פגום: חסרה רשימת הקבצים.');
        }

        $list = array_values(array_filter($list, 'is_string'));
        $expected = (int) ($manifest['files'] ?? count($list));

        if (count($list) !== $expected) {
            throw new RuntimeException(
                'קובץ הגיבוי פגום: רשימת הקבצים חלקית ('.count($list)." מתוך {$expected})."
            );
        }

        return $list;
    }
}
