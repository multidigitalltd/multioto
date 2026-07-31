<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Enums\ChargeStatus;
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

    /** The same, for the recovery command's on-disk marker. */
    private ?float $lastMarker = null;

    /** Open handle on the journal of file writes, for undoing after a kill. */
    private mixed $journal = null;

    /** Open handle on the staging file the originals are appended to. */
    private mixed $stage = null;

    /** How far into the staging file the next copy goes. */
    private int $stageOffset = 0;

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
     * @var list<array{disk: string, path: string, at: int|null, len: int}>
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
            // The previous run's reconciliation list belongs to the previous
            // run. Left standing, a restore that loses nothing would still show
            // the charges an earlier one lost, as if they were its own.
            'restore_report' => null,
        ]);

        // Held from here to the very end — before the recovery pass, not after
        // it. Putting a previous restore's files back is itself long work on
        // live files, and without the marker its own heartbeats would have
        // nothing to refresh: the row would age out mid-rollback and another
        // operation could start alongside it.
        $this->markOperationActive();

        // A restore that was killed mid-write left the live files half
        // replaced and nothing running to put them back. Doing it here as well
        // as on demand means the next attempt does not build on that mess.
        try {
            $pending = $this->recoverInterruptedFiles()['pending'];
        } catch (Throwable $e) {
            $this->clearOperationMarker();

            throw $e;
        }

        // Not a warning to carry on past: the copies still waiting are the only
        // version of those live files, and the first write of this restore
        // would take their journal with it.
        if ($pending > 0) {
            // The marker comes down FIRST. The commonest reason a recovery
            // reports pending is that the database could not be asked whether
            // the interrupted run committed — and recording this refusal is a
            // write to that same database. Left standing, the marker would
            // stop billing for the length of the whole window over a restore
            // that never started.
            $this->clearOperationMarker();

            rescue(fn () => $backup->update([
                'restore_status' => BackupStatus::Failed,
                'restore_error' => "שחזור קודם שנקטע השאיר {$pending} קבצים שלא הוחזרו. "
                    .'יש להריץ php artisan backup:recover-files עד שיסתיים בהצלחה, ורק אז לשחזר.',
            ]), report: false);

            throw new RuntimeException(
                "שחזור קודם שנקטע השאיר {$pending} קבצים שלא הוחזרו — הריצו php artisan backup:recover-files תחילה."
            );
        }

        // The attempt id doubles as the commit token: one value per attempt,
        // so a token found on the row says WHICH run committed, and an older
        // one can be left standing as the proof its own journal needs.
        $this->journalToken = $backup->restore_attempt ?: (string) Str::uuid();
        $this->journalOwner = $backup->id;
        $this->unrecovered = [];

        $local = tempnam(sys_get_temp_dir(), 'multioto-restore-');
        $sequenceError = null;
        $previous = [];
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
                    DB::transaction(function () use ($zip, $order, $deferred, $declared, $manifest, &$previous, &$discarded, &$truncated): void {
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
                        // The collector reads one reference past the ceiling so
                        // "finished" can be told from "ran out". That extra one
                        // is for counting only: comparing it too would let the
                        // report name more records than the ceiling it says it
                        // stopped at.
                        $discarded = $this->referencesNotRestored(
                            array_slice($orphaned, 0, $this->artifactLimit())
                        );
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
                        $this->restoreFiles($zip, $manifest, $previous);

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
                    // Nothing is deleted either way — the journal says what is
                    // settled, and the staging file is emptied only when the
                    // next restore starts with nothing outstanding. A failure
                    // here therefore costs a repeated undo, never the ability
                    // to do one.
                    if ($this->unrecovered === []) {
                        $this->closeJournal();
                    }
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
            $this->clearOperationMarker();

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
            // A run that stopped without committing: nothing was replaced, so
            // there is nothing a second attempt could destroy — and without
            // this the row would refuse every future restore for ever.
            && ! ($backup->restoreAbandoned() && ! app(OperationGate::class)->isRunning($backup->id))
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
        if ($this->lastBeat !== null && $this->lastBeat > now()->getTimestamp() - 60) {
            return;
        }

        $this->lastBeat = (float) now()->getTimestamp();

        $this->touchOperationMarker();

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

        // Only the declared tables are emptied and reloaded, so a manifest
        // missing one leaves that table's live rows standing beside a database
        // restored around them — and reports success. An archive declaring no
        // tables at all would replace nothing whatsoever.
        $expected = $this->archive->tables();
        $declared = array_keys((array) ($manifest['tables'] ?? []));

        $missing = array_values(array_diff($expected, $declared));

        if ($missing !== []) {
            throw new RuntimeException(
                'הגיבוי אינו כולל את כל הטבלאות ('.implode(', ', array_slice($missing, 0, 5))
                .(count($missing) > 5 ? ', ...' : '').') — השחזור נעצר כדי לא להשאיר נתונים ישנים לצד משוחזרים.'
            );
        }

        // And nothing beyond them. A manifest naming an excluded table — the
        // backup history itself, the queue, the sessions — would have that
        // table emptied and reloaded too: restoring "backups" would delete the
        // row tracking this very restore before its commit token is written,
        // and restoring "jobs" would bring back work that was already done.
        $extra = array_values(array_diff($declared, $expected));

        if ($extra !== []) {
            throw new RuntimeException(
                'הגיבוי כולל טבלאות שאינן אמורות להיות בו ('.implode(', ', array_slice($extra, 0, 5))
                .(count($extra) > 5 ? ', ...' : '').') — השחזור נעצר.'
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

        $collect = function (string $table, array $columns, callable $label, ?callable $only = null) use (&$refs, $order, $limit): void {
            if (! in_array($table, $order, true) || count($refs) >= $limit) {
                return;
            }

            DB::table($table)
                ->where(function ($q) use ($columns) {
                    foreach ($columns as $column) {
                        $q->orWhereNotNull($column);
                    }
                })
                ->when($only !== null, fn ($q) => $only($q))
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

        // Charges with no reference at all, which Cardcom may still know by the
        // id we sent it. A saved-card charge goes out as "manual-{id}", and a
        // worker killed between the call and the write leaves the row pending
        // and empty — while the money may well have moved. Restoring the row
        // away takes the id that ChargeReconciler would have used to find out,
        // so these belong in the report as much as the ones that name a
        // transaction.
        $collect('charges', ['id'],
            fn ($row): string => "חיוב #{$row->id}: ייתכן שנשלח לקארדקום כ-manual-{$row->id} ותשובתו לא נרשמה",
            fn ($q) => $q->where('status', ChargeStatus::Pending->value)
                ->whereNull('subscription_id')
                ->whereNull('cardcom_transaction_id')
                ->whereNull('cardcom_low_profile_id'));

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

                // Compared as strings on both sides. The collector stores every
                // value as one, while a numeric column comes back as an int —
                // and a strict comparison of "7" with 7 would report a charge as
                // discarded that the archive put back perfectly well.
                $restored = DB::table($table)->whereIn($column, $values)->pluck($column)
                    ->map(fn ($value): string => (string) $value)
                    ->all();

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
        // Inside the transaction the row's heartbeat is invisible to everyone
        // else, so the on-disk marker is what says this is still running.
        $this->touchOperationMarker();

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
                    $this->touchOperationMarker();
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
    private function restoreFiles(ZipArchive $zip, array $manifest, array &$previous): void
    {
        $failed = [];

        $this->writeFiles($zip, $manifest, $previous, $failed);

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
     * @param  list<array{disk: string, path: string, at: int|null, len: int}>  $previous
     * @param  list<string>  $failed
     */
    private function writeFiles(ZipArchive $zip, array $manifest, array &$previous, array &$failed): void
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
            //
            // Refused, not skipped: an installation configured with different
            // disks — a different ATTACHMENT_DISK after a rebuild — would
            // otherwise restore the rows that reference these files and none
            // of the files, and call it a success. The operator can fix the
            // configuration and run it again; what they cannot fix is a
            // restore that already said it worked.
            if ($path === '' || ! array_key_exists($disk, (array) config('backup.files', []))) {
                throw new RuntimeException(
                    "הגיבוי מכיל קבצים מיעד אחסון \"{$disk}\" שאינו מוגדר בהתקנה הזו — "
                    .'יש להגדיר את אותם יעדי אחסון (ATTACHMENT_DISK ו-backup.files) ואז לשחזר שוב.'
                );
            }

            if (in_array('..', explode('/', $path), true) || str_starts_with($path, '/')) {
                throw new RuntimeException(
                    "קובץ הגיבוי מכיל נתיב לא חוקי (\"{$path}\") — הארכיון אינו תקין והשחזור הופסק."
                );
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
            $record = $this->setAside($disk, $path);
            $previous[] = $record;

            // Written to disk BEFORE the file is overwritten. A worker killed
            // on timeout or by the OOM killer never reaches the undo path, and
            // the database rolls itself back — leaving the old rows beside
            // files from the archive, with nothing recording which ones. The
            // journal is what another process reads to finish the undo.
            $this->journal($record);

            // Per file, and per chunk of a file: writing one large attachment
            // to a slow destination is a single call with nothing of ours
            // inside it, and the transaction hides the row's own heartbeat.
            $this->touchOperationMarker();
            HeartbeatFilter::attach($stream, fn () => $this->touchOperationMarker());

            try {
                if (Storage::disk($disk)->put($path, $stream) === false
                    || ! $this->settle($disk, $path)) {
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
     * Keep what is currently at a path, so it can be put back.
     *
     * The copy goes into ONE long-lived staging file, appended to, and the
     * journal records where in it the bytes are. Nothing new is named: PHP
     * cannot sync a directory entry, so a freshly created file can be complete
     * on disk and still lose the name that points at it when the power goes —
     * leaving a durable record of a copy nothing can find. Appending to a file
     * that already exists needs no directory entry at all.
     *
     * A path with nothing at it is recorded too, as a null offset: undoing then
     * means deleting the file the restore created, not putting anything back.
     *
     * @return array{disk: string, path: string, at: int|null, len: int}
     */
    private function setAside(string $disk, string $path): array
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
            return ['disk' => $disk, 'path' => $path, 'at' => null, 'len' => 0];
        }

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
            fclose($source);

            throw new RuntimeException(
                "לא ניתן לקבוע את גודל הקובץ הקיים \"{$disk}:{$path}\" — השחזור הופסק כדי לא לדרוס אותו בלי עותק שלם."
            );
        }

        $stage = $this->stageHandle();
        $offset = $this->stageOffset;

        try {
            $copied = stream_copy_to_stream($source, $stage);
        } finally {
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

        // All the way to the disk before the live file is touched: a record
        // that survives a power cut is no help if the bytes it points at were
        // still in the operating system's cache.
        if (! @fflush($stage) || (function_exists('fsync') && ! @fsync($stage))) {
            throw new RuntimeException(
                "לא ניתן להבטיח שמירה של עותק הקובץ \"{$disk}:{$path}\" — השחזור הופסק לפני שדרס אותו."
            );
        }

        $this->stageOffset += $copied;

        return ['disk' => $disk, 'path' => $path, 'at' => $offset, 'len' => $copied];
    }

    /**
     * Push a file just written to a LOCAL destination all the way to the disk.
     *
     * A successful put() means the operating system has it, not that the disk
     * does. If the database commits and the machine then loses power, recovery
     * reads the committed token, deliberately refuses to put the original back
     * — and the live file is the old one, or half of the new one. Remote
     * destinations answer for their own durability; there is nothing here to
     * sync and path() does not name a file on this machine.
     *
     * What this CANNOT do is persist the directory entry of a file the restore
     * creates for the first time: PHP cannot open a directory as a stream, so
     * there is no handle to fsync, and shelling out is not allowed here. The
     * contents are made durable; the name is left to the filesystem, which on
     * the journalling filesystems this runs on records it with the same
     * transaction. It is the one gap in the chain, and it is written down
     * rather than papered over.
     */
    private function settle(string $disk, string $path): bool
    {
        $absolute = rescue(fn (): ?string => Storage::disk($disk)->path($path), null, report: false);

        if (! is_string($absolute) || ! is_file($absolute)) {
            return true;
        }

        if (! function_exists('fsync')) {
            return true;
        }

        $handle = @fopen($absolute, 'rb');

        if (! is_resource($handle)) {
            return false;
        }

        try {
            return (bool) @fsync($handle);
        } finally {
            fclose($handle);
        }
    }

    /** Where the copies live: one file, appended to, never renamed. */
    private function stagePath(): string
    {
        return storage_path('backups/restore-staging.blob');
    }

    private function journalPath(): string
    {
        return storage_path('backups/restore-journal.jsonl');
    }

    /**
     * Make sure the journal and the staging file exist, before anything needs
     * them.
     *
     * Called from the migration, so a deployment creates them while nothing is
     * at stake — and again on the way into a restore, for an installation that
     * has not migrated since this was added. PHP cannot sync a directory entry,
     * so the only thing that can be done about a name is to create it long
     * before the moment it has to survive.
     */
    public static function provision(): void
    {
        $dir = storage_path('backups');

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("לא ניתן ליצור את התיקייה {$dir} — יומן השחזור לא יוכל להישמר.");
        }

        foreach ([$dir.'/restore-journal.jsonl', $dir.'/restore-staging.blob'] as $path) {
            $handle = @fopen($path, 'cb');

            // Loudly, not quietly: these two names have to exist before a
            // restore needs them, and an installation that could not create
            // them should find out at deployment rather than mid-restore.
            if (! is_resource($handle)) {
                throw new RuntimeException("לא ניתן ליצור את {$path} — יומן השחזור לא יוכל להישמר.");
            }

            $ok = @fflush($handle) && (! function_exists('fsync') || @fsync($handle));

            fclose($handle);

            if (! $ok || ! is_file($path)) {
                throw new RuntimeException("לא ניתן לשמור את {$path} — יומן השחזור לא יוכל להישמר.");
            }
        }
    }

    private function directory(): string
    {
        $dir = storage_path('backups');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Open the pair for this run, emptied and ready.
     *
     * Truncating rather than deleting and recreating, for the same reason the
     * staging file is appended to: the names must not have to be created
     * again. A restore only ever gets here with nothing left pending — it
     * refuses to start otherwise — so there is nothing to lose by emptying.
     */
    private function openJournal(): void
    {
        // Provisioned rather than created here: the first restore on a fresh
        // installation would otherwise be the one creating these names, and a
        // name PHP cannot fsync is exactly what this design exists to avoid.
        // Deployment runs migrate, and the migration provisions them.
        self::provision();

        $this->journal = fopen($this->journalPath(), 'c+b');
        $this->stage = fopen($this->stagePath(), 'c+b');

        if (! is_resource($this->journal) || ! is_resource($this->stage)) {
            throw new RuntimeException('לא ניתן לפתוח את יומן השחזור — השחזור הופסק לפני שנגע בקבצים.');
        }

        foreach ([$this->journal, $this->stage] as $handle) {
            if (! ftruncate($handle, 0) || ! rewind($handle)) {
                throw new RuntimeException('לא ניתן לאפס את יומן השחזור — השחזור הופסק לפני שנגע בקבצים.');
            }
        }

        $this->stageOffset = 0;

        // Whose journal this is, and under which token, so a recovery can ask
        // the database whether that run's transaction committed.
        $this->write(['journal' => $this->journalToken, 'backup_id' => $this->journalOwner]);
    }

    private function stageHandle(): mixed
    {
        if (! is_resource($this->stage)) {
            $this->openJournal();
        }

        return $this->stage;
    }

    /**
     * Record one file write, durably, before it happens.
     *
     * @param  array{disk: string, path: string, at: int|null, len: int}  $record
     */
    private function journal(array $record): void
    {
        if (! is_resource($this->journal)) {
            $this->openJournal();
        }

        $this->write($record);
    }

    /**
     * One journal line, all the way to the disk.
     *
     * A short write on a full volume, or a flush that fails, would leave a file
     * about to be overwritten missing from the journal — and a file missing
     * from the journal is a file no recovery can put back. Better to stop here,
     * where nothing has been overwritten yet.
     *
     * @param  array<string, mixed>  $record
     */
    private function write(array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;

        // Silenced deliberately: the checks are the handling, and the framework
        // would otherwise promote PHP's warning to an exception that says less.
        $written = @fwrite($this->journal, $line);

        if ($written !== strlen($line) || ! @fflush($this->journal)) {
            throw new RuntimeException('לא ניתן לכתוב את יומן השחזור (ייתכן שאין מקום בדיסק) — השחזור הופסק לפני שנגע בקבצים.');
        }

        // The flush hands it to the operating system; this hands it to the
        // disk. A machine that loses power mid-restore is exactly the case the
        // journal exists for.
        if (function_exists('fsync') && ! @fsync($this->journal)) {
            throw new RuntimeException('לא ניתן להבטיח את כתיבת יומן השחזור לדיסק — השחזור הופסק לפני שנגע בקבצים.');
        }
    }

    /**
     * Append a record to the journal without caring whether it lands.
     *
     * Used for the marks that only save work — "this one is back", "this run is
     * over". Losing one costs a repeated undo, which is harmless: putting a
     * file back twice puts back the same bytes.
     *
     * @param  array<string, mixed>  $record
     */
    private function note(array $record): void
    {
        rescue(function () use ($record): void {
            if (! is_resource($this->journal)) {
                $this->journal = fopen($this->journalPath(), 'ab');
            }

            if (is_resource($this->journal)) {
                $this->write($record);
            }
        }, report: false);
    }

    /** Nothing left to undo — said in the journal, not by deleting it. */
    private function closeJournal(): void
    {
        $this->note(['closed' => true]);

        if (is_resource($this->journal)) {
            fclose($this->journal);
        }

        if (is_resource($this->stage)) {
            fclose($this->stage);
        }

        $this->journal = null;
        $this->stage = null;
    }

    /**
     * Finish the undo a killed restore could not.
     *
     * Nothing is ever deleted here — not the journal, not the copies — so a
     * failure at any point costs a repeated attempt and never the ability to
     * try. Entries already put back are marked as such; a mark that does not
     * survive only means doing that one again, and writing the same bytes over
     * the same path twice changes nothing.
     *
     * A journal whose run COMMITTED is not replayed. The token it carries was
     * written to the backup row inside the replacement transaction, so finding
     * it there says the archived rows are live — and the archived files belong
     * beside them. Putting the old files back then would be the corruption.
     *
     * @return array{restored: int, failed: int, pending: int}
     */
    public function recoverInterruptedFiles(): array
    {
        [$header, $entries, $recovered, $closed] = $this->readJournal();

        if ($entries === [] || $closed) {
            return ['restored' => 0, 'failed' => 0, 'pending' => 0];
        }

        $this->journalOwner = isset($header['backup_id']) ? (int) $header['backup_id'] : null;
        $committed = $this->committedRun($header);

        // Cannot say. Everything stays exactly as it is — the files, the copies
        // and the journal — until something can answer.
        if ($committed === null) {
            $why = 'לא ניתן לברר אם השחזור שנקטע הספיק להסתיים בבסיס הנתונים — הקבצים לא נגעו בהם. '
                .'יש לבדוק את החיבור לבסיס הנתונים ולהריץ שוב php artisan backup:recover-files.';

            rescue(fn () => SystemLog::record('error', 'backup', $why), report: false);
            Log::error($why);

            return ['restored' => 0, 'failed' => 0, 'pending' => count($entries)];
        }

        if ($committed) {
            // The database IS the archive. The files from the archive are the
            // right ones, and the copies of what they replaced are stale.
            $this->note(['closed' => true]);

            rescue(fn () => SystemLog::record('warning', 'backup',
                'שחזור שנקטע הושלם בבסיס הנתונים — הקבצים מהגיבוי נשארו במקומם.'), report: false);

            return ['restored' => 0, 'failed' => 0, 'pending' => 0];
        }

        $outstanding = array_values(array_filter(
            $entries,
            fn (array $entry): bool => ! in_array($this->entryKey($entry), $recovered, true),
        ));

        $failed = $this->putFilesBack($outstanding);
        $restored = count($outstanding) - count($failed);

        if ($failed === []) {
            $this->closeJournal();
        }

        SystemLog::record($failed === [] ? 'warning' : 'error', 'backup',
            "שחזור שנקטע השאיר קבצים מהגיבוי במקום הקיימים — {$restored} הוחזרו, "
            .count($failed).' נותרו להחזרה חוזרת.');

        return ['restored' => $restored, 'failed' => count($failed), 'pending' => count($failed)];
    }

    /**
     * The journal as four answers: its header, its entries, the keys already
     * put back, and whether the run is over.
     *
     * @return array{0: array<string, mixed>|null, 1: list<array{disk: string, path: string, at: int|null, len: int}>, 2: list<string>, 3: bool}
     */
    private function readJournal(): array
    {
        if (! file_exists($this->journalPath())) {
            return [null, [], [], true];
        }

        $header = null;
        $entries = [];
        $recovered = [];
        $closed = false;

        foreach (file($this->journalPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $record = json_decode($line, true);

            if (! is_array($record)) {
                continue;
            }

            if (array_key_exists('journal', $record)) {
                $header = $record;
            } elseif (isset($record['closed'])) {
                $closed = true;
            } elseif (isset($record['recovered'])) {
                $recovered[] = (string) $record['recovered'];
            } elseif (isset($record['disk'], $record['path']) && array_key_exists('at', $record)) {
                $entries[] = [
                    'disk' => (string) $record['disk'],
                    'path' => (string) $record['path'],
                    'at' => $record['at'] === null ? null : (int) $record['at'],
                    'len' => (int) ($record['len'] ?? 0),
                ];
            }
        }

        return [$header, $entries, $recovered, $closed];
    }

    /** @param  array{disk: string, path: string, at: int|null, len: int}  $entry */
    private function entryKey(array $entry): string
    {
        // The offset is part of the identity. One path can legitimately be
        // journalled twice — overlapping prefixes in backup.files list it
        // twice — and collapsing both to one key would let the later record's
        // "recovered" mark hide the earlier one, which is the copy holding the
        // true pre-restore contents.
        return $entry['disk'].'|'.$entry['path'].'|'.($entry['at'] ?? 'none');
    }

    /**
     * Undo the file writes of a restore that could not finish.
     *
     * Best effort by necessity — the storage that just refused a write may
     * refuse this too — so every failure is logged rather than thrown, and the
     * entries that failed are returned so the caller can say how many are
     * still waiting.
     *
     * @param  list<array{disk: string, path: string, at: int|null, len: int}>  $previous
     * @return list<array{disk: string, path: string, at: int|null, len: int}>
     */
    private function putFilesBack(array $previous): array
    {
        $failed = [];

        foreach (array_reverse($previous) as $file) {
            $this->touchOperationMarker();

            try {
                if ($file['at'] === null) {
                    // These disks are configured not to throw, so the refusal
                    // arrives as a return value or not at all.
                    $undone = Storage::disk($file['disk'])->delete($file['path']);
                } else {
                    $undone = $this->putSliceBack($file);
                }

                // Pushed to the disk BEFORE it is called done. The mark is
                // fsynced; if the bytes it refers to are not, a power cut
                // leaves a journal telling the next recovery to skip a file
                // that never actually came back.
                if ($undone && $file['at'] !== null) {
                    $undone = $this->settle($file['disk'], $file['path']);
                }

                if (! $undone) {
                    $failed[] = $file;

                    SystemLog::record('error', 'backup',
                        "הקובץ {$file['disk']}:{$file['path']} לא הוחזר למצבו הקודם — הוא נשאר בגרסה מהגיבוי.");

                    continue;
                }

                // Only a note: losing it costs one repeated undo, and putting
                // the same bytes back twice changes nothing.
                $this->note(['recovered' => $this->entryKey($file)]);
            } catch (Throwable $e) {
                $failed[] = $file;

                SystemLog::record('error', 'backup', "החזרת הקובץ {$file['disk']}:{$file['path']} למצבו הקודם נכשלה: "
                    .mb_substr($e->getMessage(), 0, 200));
            }
        }

        return $failed;
    }

    /**
     * Write one staged slice back over the live path.
     *
     * @param  array{disk: string, path: string, at: int|null, len: int}  $file
     */
    private function putSliceBack(array $file): bool
    {
        $stage = @fopen($this->stagePath(), 'rb');

        if (! is_resource($stage)) {
            return false;
        }

        // Copied out of the staging file rather than handed to the disk as a
        // stream: what is stored there is one run's worth of files end to end,
        // and an uploader reading to the end would write all of them into one.
        $slice = fopen('php://temp', 'w+b');

        try {
            if (fseek($stage, (int) $file['at']) !== 0) {
                return false;
            }

            if (stream_copy_to_stream($stage, $slice, (int) $file['len']) !== (int) $file['len']) {
                return false;
            }

            rewind($slice);

            // The same heartbeat the download uses: one large file on a slow
            // destination is a single call with nothing of ours inside it.
            HeartbeatFilter::attach($slice, fn () => $this->touchOperationMarker());

            return (bool) Storage::disk($file['disk'])->put($file['path'], $slice);
        } finally {
            fclose($stage);
            fclose($slice);
        }
    }

    /**
     * Did the run that wrote this journal commit its database transaction?
     *
     * Null means the database could not be asked. That is NOT a "no": if the
     * run did commit, replaying on a guess would pair the archived rows with
     * the files they replaced — the very corruption the token exists to stop.
     *
     * @param  array<string, mixed>|null  $header
     */
    private function committedRun(?array $header): ?bool
    {
        $token = $header['journal'] ?? null;
        $owner = $header['backup_id'] ?? null;

        // No token at all: written by a run that never reached its commit
        // marker. Nothing to be unsure about.
        if (! is_string($token) || $token === '' || $owner === null) {
            return false;
        }

        return rescue(
            fn (): ?bool => Backup::whereKey((int) $owner)->where('restore_journal', $token)->exists(),
            null,
            report: false,
        );
    }

    /**
     * The backup row an open journal is answered by, if there is one.
     *
     * That row carries the token which says whether the interrupted run
     * committed. Delete it and the question becomes unanswerable — and an
     * unanswerable question is read as "rolled back", which would put the
     * pre-restore files back beside a database that is the archive. So the row
     * cannot be deleted while its journal is open.
     */
    public function openJournalOwner(): ?int
    {
        [$header, $entries, , $closed] = $this->readJournal();

        if ($closed || $entries === [] || ! isset($header['backup_id'])) {
            return null;
        }

        return (int) $header['backup_id'];
    }

    /**
     * Is there an unfinished file rollback waiting, with nobody working on it?
     *
     * The journal exists throughout every normal restore, so its presence
     * alone says nothing — only entries that are neither put back nor closed,
     * at a moment when no operation is running, mean what the screen would be
     * claiming they mean.
     */
    public function hasInterruptedFiles(): bool
    {
        [, $entries, $recovered, $closed] = $this->readJournal();

        if ($closed || $entries === []) {
            return false;
        }

        $outstanding = array_filter(
            $entries,
            fn (array $entry): bool => ! in_array($this->entryKey($entry), $recovered, true),
        );

        return $outstanding !== [] && ! app(OperationGate::class)->isRunning();
    }

    /**
     * "I am still here", on disk.
     *
     * Two things need this. The recovery command replaces live files without a
     * backup row of its own, so the gate has nothing to read unless this is
     * here. And a restore inside its transaction cannot say it is alive
     * through the database at all: its own writes are invisible to everyone
     * else until it commits, and loading tables or writing large files can
     * outlast both the lock's lease and the window the gate believes a running
     * operation in.
     *
     * Bounded by the same window as everything else: left behind by a killed
     * process it stops meaning anything, rather than blocking the business for
     * ever.
     */
    public static function operationMarkerPath(): string
    {
        return storage_path('backups/operation-active');
    }

    public function markOperationActive(): void
    {
        $this->directory();

        // Stamped from the application's clock, which is what the gate reads
        // it against — two clocks would drift apart exactly where the answer
        // matters.
        touch(self::operationMarkerPath(), now()->getTimestamp());
    }

    public function clearOperationMarker(): void
    {
        if (file_exists(self::operationMarkerPath())) {
            @unlink(self::operationMarkerPath());
        }
    }

    /**
     * Say "still here", if somebody is listening.
     *
     * Only when the marker exists: inside a restore the backup row is already
     * saying the same thing, and this must not invent an operation of its own.
     * Throttled because it is called per chunk of every file.
     */
    private function touchOperationMarker(): void
    {
        // Throttled by the application's clock, the same one the gate reads
        // the marker against.
        if ($this->lastMarker !== null && $this->lastMarker > now()->getTimestamp() - 20) {
            return;
        }

        $this->lastMarker = (float) now()->getTimestamp();

        if (file_exists(self::operationMarkerPath())) {
            touch(self::operationMarkerPath(), now()->getTimestamp());
            clearstatcache(true, self::operationMarkerPath());
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
