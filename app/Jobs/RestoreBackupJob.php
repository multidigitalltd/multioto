<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Backup\BackupRestorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Put a backup back, in the background.
 *
 * Never retried and never concurrent: a restore replaces every business row,
 * and a second one starting while the first is still going is the worst thing
 * this system could do to itself. The lock is held for the whole run, and a
 * reclaimed payload finds it taken and gives up.
 */
class RestoreBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $backupId) {}

    public function handle(BackupRestorer $restorer): void
    {
        $backup = Backup::find($this->backupId);

        if (! $backup || $backup->status !== BackupStatus::Completed) {
            return;
        }

        $lock = Cache::lock(RunBackupJob::LOCK, 3600);

        if (! $lock->get()) {
            // The panel already told the operator the restore had started.
            // Returning quietly would leave that promise unkept with nothing
            // to show for it — and the job is never retried.
            $backup->update([
                'restore_status' => BackupStatus::Failed,
                'restore_error' => 'פעולת גיבוי או שחזור אחרת רצה באותו רגע — השחזור לא בוצע. נסו שוב בעוד כמה דקות.',
            ]);

            return;
        }

        try {
            $restorer->restore($backup);
        } finally {
            $lock->release();
        }
    }

    /** Leave a visible failure rather than a row stuck on "running". */
    public function failed(\Throwable $e): void
    {
        Backup::whereKey($this->backupId)
            ->where('restore_status', BackupStatus::Running)
            ->update([
                'restore_status' => BackupStatus::Failed,
                'restore_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
    }
}
