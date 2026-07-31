<?php

namespace App\Jobs\Concerns;

use App\Enums\BackupStatus;
use App\Models\Backup;

/**
 * Holds a job that has an IRREVERSIBLE OUTSIDE EFFECT while a backup or restore
 * is under way.
 *
 * A restore replaces every row. A job that is between its database write and
 * its call to the outside world when that happens gets its row deleted while
 * the outside effect still lands: the customer is charged and no charge exists,
 * or an invoice is issued against a charge that is no longer there.
 *
 * This closes the case where the job has not started yet — the common one, and
 * the whole of it if the queue is paused for the restore, as the screen and the
 * deployment guide both say to do. It cannot recall a job already past this
 * point; nothing inside the application can, which is why the advice to pause
 * the workers is written where the operator will read it.
 */
trait WaitsForRestore
{
    /** Re-queue this job for later and return true, when a restore is running. */
    protected function heldForBackupOperation(): bool
    {
        $busy = Backup::query()
            ->where(fn ($q) => $q->where('status', BackupStatus::Running)
                ->orWhere('restore_status', BackupStatus::Running))
            ->exists();

        if (! $busy) {
            return false;
        }

        // Re-dispatched rather than released: these jobs are deliberately not
        // retried, and releasing would spend the single attempt they have.
        static::dispatch(...$this->backupWaitDispatchArgs())
            ->delay(now()->addMinutes((int) config('backup.worker_hold_minutes', 5)));

        return true;
    }

    /**
     * Constructor arguments used to re-queue this job.
     *
     * @return array<int, mixed>
     */
    protected function backupWaitDispatchArgs(): array
    {
        return [];
    }
}
