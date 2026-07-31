<?php

namespace App\Jobs;

use App\Models\SystemLog;
use App\Services\Backup\BackupRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scan the destination for archives this database has no row for.
 *
 * In the background because it reads every unknown archive to get its manifest,
 * and after a rebuild that can be a whole bucket. An HTTP request would hit the
 * server's own time limit half way through and leave the recovery unfinished —
 * exactly when the recovery matters most.
 */
class ImportBackupsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function handle(BackupRunner $runner): void
    {
        $found = $runner->importFromDisk();

        SystemLog::record('info', 'backup', "סריקת יעד הגיבוי הסתיימה: {$found['imported']} נוספו, ".
            "{$found['unreadable']} לא ניתנים לקריאה.");
    }

    public function failed(\Throwable $e): void
    {
        SystemLog::record('error', 'backup', 'סריקת יעד הגיבוי נכשלה: '.mb_substr($e->getMessage(), 0, 300));
    }
}
