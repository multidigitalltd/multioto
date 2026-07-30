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

    public function __construct(private BackupArchive $archive) {}

    public function restore(Backup $backup): void
    {
        $backup->update(['restore_status' => BackupStatus::Running, 'restore_error' => null]);

        $local = tempnam(sys_get_temp_dir(), 'multioto-restore-');

        try {
            $this->download($backup, $local);

            $zip = new ZipArchive;

            if ($zip->open($local) !== true) {
                throw new RuntimeException('קובץ הגיבוי פגום או לא ניתן לפתיחה.');
            }

            try {
                $manifest = $this->archive->manifestOf($local);
                $this->verify($manifest);

                $order = $this->tableOrder(array_keys($manifest['tables'] ?? []));

                DB::transaction(function () use ($zip, $order): void {
                    // Children first: a parent row cannot go while something
                    // still points at it.
                    foreach (array_reverse($order) as $table) {
                        DB::table($table)->delete();
                    }

                    foreach ($order as $table) {
                        $this->loadTable($zip, $table);
                    }
                });

                $this->resetSequences($order);
                $this->restoreFiles($zip);
            } finally {
                $zip->close();
            }

            $backup->update([
                'restore_status' => BackupStatus::Completed,
                'restored_at' => now(),
            ]);

            SystemLog::record('warning', 'backup', "שוחזר גיבוי #{$backup->id} ({$backup->path})", [
                'backup_id' => $backup->id,
            ]);
        } catch (Throwable $e) {
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

        if (! $backup->existsOnDisk()) {
            return 'קובץ הגיבוי אינו נמצא ביעד האחסון.';
        }

        $migrations = (array) ($backup->manifest['migrations'] ?? []);

        if ($migrations !== [] && $this->migrationDrift($migrations) !== []) {
            return 'הגיבוי נלקח ממבנה בסיס נתונים אחר (גרסה שונה) — שחזור עלול להשאיר נתונים חסרים.';
        }

        return null;
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
     * Tables sorted so every table comes after the ones it points at.
     *
     * Derived from the real foreign keys rather than a hand-kept list, so a new
     * relation cannot quietly break the restore. A cycle (or a self-reference)
     * cannot be ordered, so those tables are appended and left to the row order
     * inside the file.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function tableOrder(array $tables): array
    {
        $tables = array_values(array_filter($tables, fn (string $t): bool => Schema::hasTable($t)));
        $deps = [];

        foreach ($tables as $table) {
            $deps[$table] = collect(Schema::getForeignKeys($table))
                ->pluck('foreign_table')
                ->map(fn ($t): string => (string) $t)
                ->filter(fn (string $t): bool => $t !== $table && in_array($t, $tables, true))
                ->unique()
                ->all();
        }

        $ordered = [];
        $remaining = $tables;

        while ($remaining !== []) {
            $ready = array_values(array_filter(
                $remaining,
                fn (string $t): bool => array_diff($deps[$t], $ordered) === [],
            ));

            if ($ready === []) {
                // A cycle: nothing left can be placed cleanly. Append the rest
                // rather than loop forever.
                $ordered = array_merge($ordered, $remaining);
                break;
            }

            $ordered = array_merge($ordered, $ready);
            $remaining = array_values(array_diff($remaining, $ready));
        }

        return $ordered;
    }

    /** Stream one table's rows out of the archive and insert them. */
    private function loadTable(ZipArchive $zip, string $table): void
    {
        $stream = $zip->getStream("database/{$table}.ndjson");

        if ($stream === false) {
            return; // Not in this archive — nothing to put back.
        }

        $buffer = [];

        try {
            while (($line = fgets($stream)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);

                if (! is_array($row)) {
                    continue;
                }

                $buffer[] = $this->decodeRow($row);

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
     * Hand the id sequences back after inserting explicit ids. PostgreSQL keeps
     * its own counter, so without this the next insert would collide with a row
     * that was just restored. SQLite derives the next id from the data itself
     * and needs nothing.
     *
     * @param  list<string>  $tables
     */
    private function resetSequences(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            if (! Schema::hasColumn($table, 'id')) {
                continue;
            }

            // Bindings only — the table name comes from the schema listing, not
            // from anything a user typed.
            DB::statement(
                'SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX(id) FROM '
                .DB::getQueryGrammar()->wrapTable($table).'), 1), true)',
                [$table, 'id'],
            );
        }
    }

    /** Put the uploaded files back on the disks they came from. */
    private function restoreFiles(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (! str_starts_with($name, 'files/')) {
                continue;
            }

            // files/<disk>/<path...>
            $rest = substr($name, strlen('files/'));
            $slash = strpos($rest, '/');

            if ($slash === false) {
                continue;
            }

            $disk = substr($rest, 0, $slash);
            $path = substr($rest, $slash + 1);

            // Only disks this installation backs up, and only paths that stay
            // inside them — an archive is a file like any other, and a crafted
            // one must not be able to write outside the disk root.
            if ($path === '' || ! array_key_exists($disk, (array) config('backup.files', []))) {
                continue;
            }

            if (in_array('..', explode('/', $path), true) || str_starts_with($path, '/')) {
                continue;
            }

            $stream = $zip->getStream($name);

            if ($stream === false) {
                continue;
            }

            try {
                Storage::disk($disk)->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
}
