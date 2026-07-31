<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Builds one backup archive: every business row plus the uploaded files.
 *
 * The database goes in as NDJSON, one file per table, NOT as SQL. A dump of
 * INSERT statements is a dialect and an escaping problem — it has to be written
 * for the exact database it came from, and one mis-quoted value is a restore
 * that fails at 3am. Rows as JSON are the same on PostgreSQL and SQLite, they
 * stream a line at a time, and the restore is a plain insert.
 *
 * The schema is deliberately NOT in the archive. Migrations own the schema;
 * what is recorded is the migration list, so a restore can refuse an archive
 * whose shape no longer matches the code running now.
 */
class BackupArchive
{
    /** Bumped when the layout inside the archive changes incompatibly. */
    public const FORMAT = 1;

    public const MANIFEST = 'manifest.json';

    /** The list of uploaded files inside the archive (kept out of the manifest). */
    public const FILE_LIST = 'files.json';

    /**
     * Write the archive to $zipPath and return its manifest.
     *
     * @return array<string, mixed>
     */
    public function write(string $zipPath): array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("לא ניתן ליצור את קובץ הגיבוי: {$zipPath}");
        }

        // Temp files stay open inside the zip until close(), so they are
        // removed only once the archive is sealed.
        $temp = [];
        $open = true;

        try {
            // Every table AND the migration list read inside ONE snapshot. Read
            // them separately and an ordinary concurrent write lands between
            // two of them — a site exported without the customer it points at,
            // or a deployment migrating mid-run so the archive claims a shape
            // it does not have. Either way: an archive that cannot be restored.
            // Read-only; the transaction exists purely for the consistent read.
            [$tables, $migrations] = $this->consistently(fn (): array => [
                $this->addTables($zip, $temp),
                $this->migrations(),
            ]);

            [$files, $skipped] = $this->addFiles($zip, $temp);

            // The file list lives in the ARCHIVE, not the manifest: the manifest
            // is also stored on the backups row, and a list of every attachment
            // does not belong in a database column. The restore needs it to
            // notice a member that went missing.
            $zip->addFromString(self::FILE_LIST, (string) json_encode(
                $files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            $manifest = [
                'format' => self::FORMAT,
                'created_at' => now()->toIso8601String(),
                'app_version' => $this->appVersion(),
                'connection' => DB::getDefaultConnection(),
                'migrations' => $migrations,
                'tables' => $tables,
                'rows' => array_sum($tables),
                'files' => count($files),
                'skipped_files' => $skipped,
            ];

            $zip->addFromString(self::MANIFEST, (string) json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            $open = false;

            // Sealing is where a full disk shows up. Ignore it and the runner
            // uploads a truncated file and calls the backup a success — an
            // archive that only fails to open on the day it is needed.
            if ($zip->close() !== true) {
                throw new RuntimeException('לא ניתן היה לסגור את קובץ הגיבוי — ייתכן שאין מקום פנוי בשרת.');
            }
        } finally {
            if ($open) {
                $zip->close();
            }

            foreach ($temp as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        return $manifest;
    }

    /**
     * Run the export inside one consistent read of the database.
     *
     * PostgreSQL needs the isolation level raised before the first statement,
     * so the transaction is opened by hand rather than through the helper.
     * SQLite's default transaction already gives a stable read.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function consistently(callable $read): mixed
    {
        DB::beginTransaction();

        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            $result = $read();

            DB::commit();

            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Read a manifest without unpacking the whole archive.
     *
     * @return array<string, mixed>|null
     */
    public function manifestOf(string $zipPath): ?array
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $raw = $zip->getFromName(self::MANIFEST);
        $zip->close();

        $manifest = json_decode((string) $raw, true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * One NDJSON entry per backed-up table.
     *
     * Each table is streamed straight to a temp file. Building the whole table
     * as one string would throw away what the cursor gains and could exhaust
     * the worker's memory on the biggest table — and a fatal allocation error
     * can skip the failure handling entirely, leaving the run stuck on
     * "running" with no archive and no explanation.
     *
     * @param  list<string>  $temp  collects the temp files to delete after close()
     * @return array<string, int> table => row count
     */
    private function addTables(ZipArchive $zip, array &$temp): array
    {
        $counts = [];

        foreach ($this->tables() as $table) {
            $file = tempnam(sys_get_temp_dir(), 'multioto-table-');
            $temp[] = $file;

            $handle = fopen($file, 'wb');
            $rows = 0;

            try {
                foreach (DB::table($table)->cursor() as $row) {
                    $line = $this->encodeRow((array) $row)."\n";

                    // Counted only once it is all there. A short write on a
                    // full temporary volume would otherwise be sealed into an
                    // archive whose manifest claims the row — a backup that
                    // looks complete and fails on the day it is needed.
                    if (@fwrite($handle, $line) !== strlen($line)) {
                        throw new RuntimeException(
                            "כתיבת נתוני הטבלה \"{$table}\" נכשלה (ייתכן שאין מקום בדיסק) — הגיבוי הופסק."
                        );
                    }

                    $rows++;
                }
            } finally {
                fclose($handle);
            }

            $zip->addFile($file, "database/{$table}.ndjson");
            $counts[$table] = $rows;
        }

        return $counts;
    }

    /**
     * A row as one JSON line. A column holding bytes that are not valid UTF-8
     * would make json_encode fail and take the whole table with it, so such a
     * value is carried as base64 under a marker the restore reverses — a backup
     * that silently drops rows is worse than no backup.
     *
     * @param  array<string, mixed>  $row
     */
    private function encodeRow(array $row): string
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $row[$column] = ['__b64' => base64_encode($value)];
            }
        }

        return (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Copy the uploaded files in, keeping disk and path so a restore can put
     * each one back where it came from.
     *
     * Each object is streamed to a temporary file and added from there rather
     * than read into a string: addFromString() holds every payload until the
     * archive is sealed, so a normal collection of many ordinary attachments
     * could exhaust the worker even with each one under the per-file limit.
     *
     * @param  list<string>  $temp  collects the staged files to delete after close()
     * @return array{0: list<string>, 1: list<string>} [added "disk/path", skipped]
     */
    private function addFiles(ZipArchive $zip, array &$temp): array
    {
        $max = (int) config('backup.max_file_bytes');
        $added = [];
        $skipped = [];

        foreach ((array) config('backup.files', []) as $disk => $prefixes) {
            $storage = Storage::disk($disk);
            $paths = $prefixes === []
                ? $storage->allFiles()
                : collect($prefixes)->flatMap(fn (string $p): array => $storage->allFiles($p))->all();

            foreach ($paths as $path) {
                // A size we cannot read is a size we cannot check the copy
                // against, and an unchecked copy is how a truncated file ends
                // up in the archive under a valid checksum. Left out and
                // reported instead — the team is told either way.
                $expected = rescue(fn (): ?int => $storage->size($path), null, report: false);

                if ($expected === null || ($max > 0 && $expected > $max)) {
                    $skipped[] = "{$disk}:{$path}";

                    continue;
                }

                $source = rescue(fn () => $storage->readStream($path), null, report: false);

                if (! is_resource($source)) {
                    $skipped[] = "{$disk}:{$path}";

                    continue;
                }

                $staged = tempnam(sys_get_temp_dir(), 'multioto-file-');
                $temp[] = $staged;

                $out = fopen($staged, 'wb');

                try {
                    $copied = stream_copy_to_stream($source, $out);
                } finally {
                    fclose($out);
                    fclose($source);
                }

                // A short copy is not an error the stream reports: a source
                // truncated or replaced mid-read simply ends early, and the
                // archive would then hold — and a restore would install — a
                // shortened file with a perfectly valid checksum over it.
                if ($copied !== $expected) {
                    $skipped[] = "{$disk}:{$path}";

                    continue;
                }

                $zip->addFile($staged, "files/{$disk}/{$path}");
                $added[] = "{$disk}/{$path}";
            }
        }

        return [$added, $skipped];
    }

    /**
     * The tables to back up: everything the schema has, minus the runtime ones.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $exclude = (array) config('backup.exclude_tables', []);

        return collect(Schema::getTableListing())
            // Some drivers report "schema.table"; the bare name is what we use.
            ->map(fn (string $table): string => str_contains($table, '.')
                ? (string) substr(strrchr($table, '.') ?: $table, 1)
                : $table)
            ->reject(fn (string $table): bool => in_array($table, $exclude, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The applied migrations. Recorded so a restore can tell whether the
     * archive was taken against the schema the code expects today.
     *
     * @return list<string>
     */
    public function migrations(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [];
        }

        return DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
    }

    /** The release this archive was taken on, for the restore warning. */
    private function appVersion(): string
    {
        return (string) (config('changelog.releases.0.version') ?? 'unknown');
    }
}
