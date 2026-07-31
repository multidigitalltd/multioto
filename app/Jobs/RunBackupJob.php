<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Backup\BackupRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Take one backup in the background — the nightly run, or the button.
 *
 * Held under a lock rather than trusted to run once. The archive can take
 * longer than the queue's reclaim window, and a second worker picking up the
 * same payload would write a second archive over the top of the first; the lock
 * makes that attempt a no-op instead. Same reason ChargeSubscriptionJob holds
 * one: the queue guarantees at-least-once, and some work must be at-most-once.
 */
class RunBackupJob implements ShouldQueue
{
    use Queueable;

    /**
     * ONE lock for backing up and restoring alike. A backup running while a
     * restore replaces the database would read its rows from one state and its
     * files from another, and call the result a good archive.
     */
    public const LOCK = 'backup:operation';

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public ?int $userId = null) {}

    /**
     * The nightly entry point, from the scheduler.
     *
     * A queue that will not accept the job throws before anything downstream
     * exists to notice, so the night would pass with no copy of the business,
     * no row and no alert — the one failure mode this whole feature is built to
     * make impossible.
     */
    public static function dispatchNightly(): void
    {
        if (! (bool) config('backup.enabled', true)) {
            return;
        }

        try {
            self::dispatch();
        } catch (\Throwable $e) {
            app(BackupRunner::class)->recordUnstarted(
                null,
                'הגיבוי הלילי לא הועבר לתור: '.mb_substr($e->getMessage(), 0, 300),
            );
        }
    }

    public function handle(BackupRunner $runner): void
    {
        // The switch governs the NIGHTLY run — that is what it is labelled as.
        // A button press is an explicit request, and discarding it silently
        // after the panel said the backup started is worse than either
        // honouring it or refusing it out loud.
        if ($this->userId === null && ! (bool) config('backup.enabled', true)) {
            return;
        }

        $lock = Cache::lock(self::LOCK, 3600);

        if (! $lock->get()) {
            // Not silence: a night that produced no copy of the business has
            // to be visible, and a manual request was already announced in the
            // panel as having started.
            $runner->recordBlocked($this->userId);

            return;
        }

        try {
            $runner->run($this->userId);
        } finally {
            $lock->release();
        }
    }

    /**
     * A worker killed on timeout dies outside the runner's own handling, so the
     * row it created would sit on "running" for ever and the team would never
     * be told. The lock means at most one run is in flight, so the row still
     * marked running is this one.
     */
    public function failed(\Throwable $e): void
    {
        $backup = Backup::query()
            ->where('status', BackupStatus::Running)
            ->latest('id')
            ->first();

        // Through the runner, so the team gets the email too — a status quietly
        // flipped in the database is not a notification.
        if ($backup !== null) {
            app(BackupRunner::class)->fail($backup, $e->getMessage());
        }
    }
}
