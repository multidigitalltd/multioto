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

    /**
     * Declared with a default rather than promoted: a payload serialized before
     * this property existed is rebuilt without calling the constructor, and a
     * promoted property would be left uninitialized — reading it would throw,
     * and the failure handler would then take that out on whichever claim
     * happens to be current.
     */
    public ?string $attempt = null;

    public function __construct(public int $backupId, ?string $attempt = null)
    {
        $this->attempt = $attempt;
    }

    public function handle(BackupRestorer $restorer): void
    {
        $backup = Backup::find($this->backupId);

        if (! $backup || $backup->status !== BackupStatus::Completed) {
            return;
        }

        // One atomic step, not a check and then a write: between the two, a
        // claim can expire, be taken over, and the replacement restore can even
        // finish — and this job would still start on top of it, putting the same
        // snapshot back over everything accepted since. The claim the panel made
        // must still be open, must still be THIS attempt, and must not have been
        // started already; taking it is what proves all three at once.
        $started = Backup::whereKey($backup->id)
            ->where('restore_status', BackupStatus::Running)
            ->whereNull('restore_started_at')
            // Always matched, including when this payload has no attempt id of
            // its own: skipping the comparison would let a payload from before
            // the ids existed take a claim made with one, which is exactly the
            // stale restore the ids are here to stop.
            ->where(fn ($q) => $this->attempt === null
                ? $q->whereNull('restore_attempt')
                : $q->where('restore_attempt', $this->attempt))
            ->update(['restore_started_at' => now()]);

        if ($started !== 1) {
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
