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

    public function __construct(public int $backupId, public ?string $attempt = null) {}

    public function handle(BackupRestorer $restorer): void
    {
        $backup = Backup::find($this->backupId);

        if (! $backup || $backup->status !== BackupStatus::Completed) {
            return;
        }

        // The claim the panel made before dispatching must still be open. The
        // queue delivers at least once, so a worker that finished the restore
        // and died before acknowledging its payload gets handed the same job
        // again — and running it a second time would put the old snapshot back
        // over everything accepted since the first one finished.
        if ($backup->restore_status !== BackupStatus::Running) {
            return;
        }

        // And it must be THIS attempt. A claim abandoned because the queue
        // swallowed its payload can be taken over by a later one; if the
        // original message then turns up after all, this is what stops it from
        // restoring a second time on top of the newer attempt.
        if ($this->attempt !== null && $backup->restore_attempt !== $this->attempt) {
            return;
        }

        // Marks the difference between "queued and never picked up", which may
        // be taken over, and "running", which may not.
        $backup->update(['restore_started_at' => now()]);

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
            // Never over a restore that already replaced the data. "Failed"
            // reads as "nothing happened, try again", and trying again would
            // delete everything accepted since it landed.
            ->whereNull('restored_at')
            ->update([
                'restore_status' => BackupStatus::Failed,
                'restore_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
    }
}
