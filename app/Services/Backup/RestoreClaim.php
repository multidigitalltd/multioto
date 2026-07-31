<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Taking the right to restore from one particular archive.
 *
 * The claim is the write, not a check followed by one: two admins clicking at
 * once, or one clicking twice while the queue is slow, would otherwise start the
 * same restore twice — and the second would finish after the first and put the
 * same old snapshot back over everything accepted in between.
 *
 * Both entry points come through here — the screen, which claims and then hands
 * the work to a worker, and the console command, which claims and does the work
 * itself. Two copies of a rule this sharp would drift.
 */
class RestoreClaim
{
    /**
     * Take the claim, or return null when somebody else holds it.
     *
     * @param  bool  $takeOverUnstarted  Adopt a claim whose job never started,
     *                                   without waiting out the usual window.
     *                                   For the console command: the operator is
     *                                   standing there because the worker is
     *                                   stopped, and the wait would be spent
     *                                   waiting for a job that cannot run. Safe
     *                                   for the same reason the timed take-over
     *                                   is — the attempt id changes, so a
     *                                   payload that runs after all finds itself
     *                                   superseded and stops.
     */
    public function take(Backup $backup, bool $takeOverUnstarted = false): ?string
    {
        $attempt = (string) Str::uuid();

        $claimed = Backup::whereKey($backup->id)
            ->where(fn ($q) => $q->whereNull('restore_status')
                ->orWhere('restore_status', BackupStatus::Failed)
                // Spelled out rather than "anything but running": a restore
                // that landed and left something to repair is also completed,
                // and re-running it would delete everything accepted since.
                ->orWhere(fn ($done) => $done->where('restore_status', BackupStatus::Completed)
                    ->whereNull('restore_error'))
                ->orWhere(fn ($stale) => $this->unstarted($stale, $takeOverUnstarted)))
            ->update([
                'restore_status' => BackupStatus::Running,
                'restore_error' => null,
                'restore_attempt' => $attempt,
                'restore_queued_at' => now(),
                'restore_started_at' => null,
                // The previous attempt's completion mark, which says "this row
                // already replaced the data". Left standing it would stop the
                // failure handler from recording THIS attempt going wrong, and
                // the claim would then sit on "running" with nothing able to
                // clear it.
                'restored_at' => null,
            ]);

        return $claimed === 1 ? $attempt : null;
    }

    /**
     * Mark this attempt as the one actually doing the work.
     *
     * The same single atomic step the queued job uses: the claim must still be
     * open, must still be THIS attempt, and must not have been started already.
     * Taking it is what proves all three at once.
     */
    public function markStarted(Backup $backup, string $attempt): bool
    {
        return Backup::whereKey($backup->id)
            ->where('restore_status', BackupStatus::Running)
            ->where('restore_attempt', $attempt)
            ->whereNull('restore_started_at')
            ->update(['restore_started_at' => now()]) === 1;
    }

    /**
     * A claim the queue took and never acted on.
     *
     * @param  Builder  $query
     */
    private function unstarted($query, bool $immediately)
    {
        $query->where('restore_status', BackupStatus::Running)
            ->whereNull('restore_started_at');

        return $immediately ? $query : $query->where('restore_queued_at', '<', now()->subMinutes(
            max(1, (int) config('backup.restore_claim_minutes', 30))
        ));
    }
}
