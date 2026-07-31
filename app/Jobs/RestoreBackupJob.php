<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\OperationGate;
use Illuminate\Contracts\Database\Query\Builder;
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
            ->where(fn ($q) => $this->matchesAttempt($q))
            ->update(['restore_started_at' => now()]);

        if ($started !== 1) {
            return;
        }

        $lock = Cache::lock(RunBackupJob::LOCK, 3600);

        // The lock's lease can run out under an operation that has none — a
        // console restore of a very large archive — and a free lock would then
        // let this one start on top of it. Its own claim is excluded, since
        // that is the row this job just took.
        if (! $lock->get() || app(OperationGate::class)->isRunning(exceptId: $backup->id)) {
            $lock->release();

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

    /**
     * The claim this payload was made for, and no other.
     *
     * Always matched, including when the payload has no attempt id of its own:
     * skipping the comparison would let one written before the ids existed take
     * a claim made with one, which is exactly the stale restore the ids are
     * here to stop.
     */
    private function matchesAttempt(Builder $query): Builder
    {
        return $this->attempt === null
            ? $query->whereNull('restore_attempt')
            : $query->where('restore_attempt', $this->attempt);
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
            // And only over the claim THIS payload was made for. A superseded
            // one that throws on its way to the claim check — a database blip
            // in find(), say — would otherwise cancel whichever attempt is
            // current, or make a running restore look reclaimable.
            ->where(fn ($q) => $this->matchesAttempt($q))
            ->update([
                'restore_status' => BackupStatus::Failed,
                'restore_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
    }
}
