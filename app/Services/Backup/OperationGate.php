<?php

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;

/**
 * One answer to "is a backup or restore under way right now", for the paths
 * whose effect cannot be taken back once it lands.
 *
 * A charge or a tax document is real the moment Cardcom or Linet accepts it. If
 * a restore replaces the row recording it a second later, the customer has been
 * charged and nothing here says so. So those paths ask first.
 *
 * The answer is deliberately bounded by age. A row left on "running" — a worker
 * that vanished, a database that went away mid-bookkeeping — must not be able
 * to stop the business billing for ever. Past the window it is treated as
 * abandoned: the failure mode of guessing wrong that way is one unguarded
 * charge, and the failure mode of guessing wrong the other way is no income at
 * all until somebody notices.
 */
class OperationGate
{
    /**
     * @param  int|null  $exceptId  a row not to count — the caller's own claim,
     *                              when it is asking whether anything ELSE is
     *                              under way before it starts destroying data
     */
    public function isRunning(?int $exceptId = null): bool
    {
        $since = now()->subMinutes(max(1, (int) config('backup.operation_window_minutes', 60)));

        return Backup::query()
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->where(fn ($q) => $q->where('status', BackupStatus::Running)
                ->orWhere('restore_status', BackupStatus::Running))
            // Recency by the row's own last touch: a run that is genuinely
            // under way was written to when it was claimed and when it began,
            // and both jobs are capped at half an hour, so anything older than
            // the window has been abandoned.
            ->where('updated_at', '>', $since)
            ->exists();
    }
}
