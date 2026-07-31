<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\SystemLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    public function __construct(private BackupArchive $archive) {}

    public function restore(Backup $backup): void
    {
        $backup->update(['restore_status' => BackupStatus::Running, 'restore_error' => null]);

        $local = tempnam(sys_get_temp_dir(), 'multioto-restore-');
        $sequenceError = null;
        $previous = [];
        $staged = [];
        $committed = false;

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
                $this->assertComplete($zip, $order);
                $this->assertFilesReadable($zip, $manifest);

                // The staged originals live OUT here, not inside the callback:
                // a commit can still fail after the callback returns, and the
                // one thing that could put the files back must not have been
                // thrown away by then.
                try {
                    DB::transaction(function () use ($zip, $order, $deferred, $declared, $manifest, &$previous, &$staged): void {
                        // Shut the writers out for the duration. The panel and the
                        // queue workers keep running during a restore, and a row
                        // committed after its table was emptied would survive
                        // alongside the archived ones — a database that is neither
                        // the backup nor what was there before, reported as a
                        // success. Anything mid-write waits; anything that cannot
                        // wait fails loudly rather than half-applying.
                        $this->lockTables($order);

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

                        // Files INSIDE the transaction, which makes the restore
                        // all-or-nothing the useful way round: a file that cannot
                        // be written rolls the database back to what it was,
                        // instead of leaving it replaced with its attachments
                        // missing and nothing left to compare against.
                        $this->restoreFiles($zip, $manifest, $previous, $staged);
                    });

                    $committed = true;
                } catch (Throwable $e) {
                    // Rows roll back on their own; files do not.
                    $this->putFilesBack($previous);

                    throw $e;
                } finally {
                    $this->discard($staged);
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
            if ($committed) {
                $this->recordCommittedWithFault($backup, $e);

                throw $e;
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
     */
    public function blockedReason(Backup $backup): ?string
    {
        if ($backup->status !== BackupStatus::Completed) {
            return 'הגיבוי לא הושלם — אין ממה לשחזר.';
        }

        if ($backup->restore_status === BackupStatus::Running) {
            // A second run would finish AFTER the first and put the same old
            // snapshot back, wiping everything accepted in between.
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
    }

    private function download(Backup $backup, string $to): void
    {
        $stream = Storage::disk($backup->disk)->readStream($backup->path);

        if ($stream === null || $stream === false) {
            throw new RuntimeException('קובץ הגיבוי אינו נמצא ביעד האחסון.');
        }

        $out = fopen($to, 'wb');

        try {
            stream_copy_to_stream($stream, $out);
        } finally {
            fclose($out);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }
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
    private function assertComplete(ZipArchive $zip, array $tables): void
    {
        foreach ($tables as $table) {
            $stream = $zip->getStream("database/{$table}.ndjson");

            if ($stream === false) {
                throw new RuntimeException("קובץ הגיבוי פגום: חסרים נתוני הטבלה \"{$table}\".");
            }

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
    private function assertFilesReadable(ZipArchive $zip, array $manifest): void
    {
        foreach ($this->declaredFiles($zip, $manifest) as $entry) {
            $stream = $zip->getStream('files/'.$entry);

            if ($stream === false) {
                throw new RuntimeException("קובץ הגיבוי פגום: לא ניתן לקרוא את \"{$entry}\".");
            }

            try {
                // Read until the stream says it has nothing left — NOT until
                // feof(). The checksum is verified on the read that follows the
                // last byte, so stopping at feof() would skip the one read that
                // reports a damaged payload.
                while (true) {
                    $chunk = @fread($stream, self::READ_CHUNK);

                    if ($chunk === false) {
                        throw new RuntimeException("קובץ הגיבוי פגום: תוכן פגום ב\"{$entry}\".");
                    }

                    if ($chunk === '') {
                        break;
                    }
                }
            } finally {
                fclose($stream);
            }
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
            $previous[] = $this->setAside($disk, $path, $temp);

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

        $copy = tempnam(sys_get_temp_dir(), 'multioto-prev-');
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

        $expected = rescue(fn (): ?int => $storage->size($path), null, report: false);

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
        if ($copied === false || ($expected !== null && $copied !== $expected)) {
            throw new RuntimeException(
                "העתקת הקובץ הקיים \"{$disk}:{$path}\" לשמירה זמנית נכשלה — השחזור הופסק."
            );
        }

        return ['disk' => $disk, 'path' => $path, 'from' => $copy];
    }

    /**
     * Undo the file writes of a restore that could not finish.
     *
     * Best effort by necessity — the storage that just refused a write may
     * refuse this too — so every failure is logged rather than thrown: the
     * restore is failing anyway, and the reason it failed is the more useful
     * one to surface.
     *
     * @param  list<array{disk: string, path: string, from: string|null}>  $previous
     */
    private function putFilesBack(array $previous): void
    {
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
                    SystemLog::record('error', 'backup',
                        "הקובץ {$file['disk']}:{$file['path']} לא הוחזר למצבו הקודם — הוא נשאר בגרסה מהגיבוי.");
                }
            } catch (Throwable $e) {
                SystemLog::record('error', 'backup', "החזרת הקובץ {$file['disk']}:{$file['path']} למצבו הקודם נכשלה: "
                    .mb_substr($e->getMessage(), 0, 200));
            }
        }
    }

    /** @param  list<string>  $temp */
    private function discard(array $temp): void
    {
        foreach ($temp as $file) {
            if (file_exists($file)) {
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
