<?php

use App\Services\Backup\BackupRestorer;
use Illuminate\Database\Migrations\Migration;

/**
 * Create the restore journal and its staging file, once, at deployment time.
 *
 * A restore records every file it is about to overwrite, and keeps the original
 * in a staging file, so a worker killed mid-way can be undone. Both live at
 * fixed paths and are appended to and truncated in place, never recreated —
 * because PHP cannot fsync a directory entry, so a name created moments before
 * a crash may not survive it, while the record pointing at it does.
 *
 * Creating them here means the one moment that risk exists is a deployment,
 * not a restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        BackupRestorer::provision();
    }

    public function down(): void
    {
        // Nothing: the files are runtime state, and removing them could throw
        // away the record of a restore that has not finished being undone.
    }
};
