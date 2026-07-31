<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\OperationGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Finish the file rollback a killed restore could not.
 *
 * A restore replaces uploaded files as well as rows. The rows are inside a
 * transaction, so a worker killed mid-way — a timeout, the OOM killer, a
 * machine that went down — rolls them back on its own. The files do not: they
 * stay as the archive left them, next to a database that is not the archive's.
 *
 * The restore writes down every file it is about to overwrite, and where it
 * put the original, before touching it. This replays that journal.
 *
 *   php artisan backup:recover-files
 */
class RecoverRestoreFilesCommand extends Command
{
    protected $signature = 'backup:recover-files';

    protected $description = 'החזרת קבצים שנדרסו על ידי שחזור שנקטע באמצע, למצבם הקודם';

    public function handle(BackupRestorer $restorer, OperationGate $gate): int
    {
        // The journal exists throughout every normal restore. Replaying one
        // that a live restore is still appending to would put its early files
        // back and delete the copies it still needs, under a worker that then
        // commits a database expecting them — so this refuses while anything
        // is running, and holds the operation lock while it works.
        if ($gate->isRunning()) {
            $this->error('פעולת גיבוי או שחזור רצה כרגע — נסו שוב בסיומה.');

            return self::FAILURE;
        }

        if (! $restorer->hasInterruptedFiles()) {
            $this->info('אין שחזור שנקטע — הקבצים במצבם התקין.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(RunBackupJob::LOCK, 600);

        if (! $lock->get()) {
            $this->error('פעולת גיבוי או שחזור רצה כרגע — נסו שוב בסיומה.');

            return self::FAILURE;
        }

        try {
            $result = $restorer->recoverInterruptedFiles();
        } finally {
            $lock->release();
        }

        $this->info("{$result['restored']} קבצים הוחזרו למצבם שלפני השחזור שנקטע.");

        if ($result['failed'] > 0) {
            // Kept, not cleared: their staged copies are still the only version
            // of those files, and the journal still points at them.
            $this->error("{$result['failed']} קבצים לא הוחזרו ונשמרו לניסיון נוסף — בדקו את הרשאות הדיסק ואת יומן המערכת, והריצו שוב.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
