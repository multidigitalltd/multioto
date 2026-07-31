<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupRestorer;
use Illuminate\Console\Command;

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

    public function handle(BackupRestorer $restorer): int
    {
        if (! $restorer->hasInterruptedFiles()) {
            $this->info('אין שחזור שנקטע — הקבצים במצבם התקין.');

            return self::SUCCESS;
        }

        $count = $restorer->recoverInterruptedFiles();

        $this->info("{$count} קבצים הוחזרו למצבם שלפני השחזור שנקטע.");
        $this->line('קובץ שלא ניתן היה להחזיר נרשם ביומן המערכת — יש לבדוק שם.');

        return self::SUCCESS;
    }
}
