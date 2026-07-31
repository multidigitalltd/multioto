<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One row per archive at a destination.
 *
 * Two administrators scanning the destination at the same moment could both
 * find the same ZIP unlisted and both adopt it. Deleting either row would then
 * take the file out from under the other, which would go on presenting itself
 * as a restorable backup.
 *
 * Partial, because rows that never got as far as writing an archive share the
 * empty path — a run refused by the lock or by the queue is recorded so the
 * failure is visible, and there can be any number of those.
 */
return new class extends Migration
{
    private const INDEX = 'backups_disk_path_unique';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX." ON backups (disk, path) WHERE path <> ''");
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX '.self::INDEX);
    }
};
