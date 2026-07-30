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

        try {
            $tables = $this->addTables($zip);
            [$files, $skipped] = $this->addFiles($zip);

            $manifest = [
                'format' => self::FORMAT,
                'created_at' => now()->toIso8601String(),
                'app_version' => $this->appVersion(),
                'connection' => DB::getDefaultConnection(),
                'migrations' => $this->migrations(),
                'tables' => $tables,
                'rows' => array_sum($tables),
                'files' => $files,
                'skipped_files' => $skipped,
            ];

            $zip->addFromString(self::MANIFEST, (string) json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        } finally {
            $zip->close();
        }

        return $manifest;
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
     * @return array<string, int> table => row count
     */
    private function addTables(ZipArchive $zip): array
    {
        $counts = [];

        foreach ($this->tables() as $table) {
            $lines = '';
            $rows = 0;

            foreach (DB::table($table)->cursor() as $row) {
                $lines .= $this->encodeRow((array) $row)."\n";
                $rows++;
            }

            $zip->addFromString("database/{$table}.ndjson", $lines);
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
     * @return array{0: int, 1: list<string>} [count, skipped]
     */
    private function addFiles(ZipArchive $zip): array
    {
        $max = (int) config('backup.max_file_bytes');
        $count = 0;
        $skipped = [];

        foreach ((array) config('backup.files', []) as $disk => $prefixes) {
            $storage = Storage::disk($disk);
            $paths = $prefixes === []
                ? $storage->allFiles()
                : collect($prefixes)->flatMap(fn (string $p): array => $storage->allFiles($p))->all();

            foreach ($paths as $path) {
                if ($max > 0 && $storage->size($path) > $max) {
                    $skipped[] = "{$disk}:{$path}";

                    continue;
                }

                $contents = $storage->get($path);

                if ($contents === null) {
                    $skipped[] = "{$disk}:{$path}";

                    continue;
                }

                $zip->addFromString("files/{$disk}/{$path}", $contents);
                $count++;
            }
        }

        return [$count, $skipped];
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
