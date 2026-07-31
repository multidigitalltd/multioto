<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\OperationGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

    /**
     * Which run this payload is. Declared with a default rather than promoted:
     * a payload serialized before the property existed is rebuilt without
     * calling the constructor, and a promoted property would be left
     * uninitialized — reading it in the failure handler would throw.
     */
    public ?string $attempt = null;

    public function __construct(public ?int $userId = null)
    {
        // At dispatch, so it travels in the payload and the handler that runs
        // after a killed worker is looking for the same id the run wrote.
        $this->attempt = (string) Str::uuid();
    }

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

        // The lock has a lease, and a restore run from the console has no
        // timeout at all: a very large archive can still be coming down when
        // the lease runs out, leaving the lock free while the restore is very
        // much alive. The rows are the backstop — a running restore keeps
        // touching its own — so both are asked, and neither alone decides.
        if (! $lock->get() || app(OperationGate::class)->isRunning()) {
            $lock->release();

            // Not silence: a night that produced no copy of the business has
            // to be visible, and a manual request was already announced in the
            // panel as having started.
            $runner->recordBlocked($this->userId);

            return;
        }

        try {
            $runner->run($this->userId, $this->attempt);
        } finally {
            $lock->release();
        }
    }

    /**
     * A worker killed on timeout dies outside the runner's own handling, so the
     * row it created would sit on "running" for ever and the team would never
     * be told.
     *
     * Found by this job's own attempt id, never by "the latest running row":
     * a job that fails BEFORE the row exists — a cache error while taking the
     * lock, say — would otherwise mark somebody else's live backup as failed,
     * removing its protection from the money jobs and offering its delete
     * button while its worker is still writing the archive.
     */
    public function failed(\Throwable $e): void
    {
        if ($this->attempt !== null) {
            $backup = Backup::query()->where('run_attempt', $this->attempt)->latest('id')->first();

            // A run that finished is not reopened by whatever failed after it.
            if ($backup !== null && $backup->status !== BackupStatus::Running) {
                return;
            }
        } else {
            // A payload written before runs carried an id — only in flight
            // across a deployment. There is no way to tell its row from anyone
            // else's, so only a row too old to be the work of a living worker
            // is attributed to it: a backup that is genuinely under way was
            // written to when it started, and neither job may run past half an
            // hour.
            $backup = Backup::query()
                ->whereNull('run_attempt')
                ->where('status', BackupStatus::Running)
                ->where('updated_at', '<', now()->subSeconds($this->timeout))
                ->latest('id')
                ->first();
        }

        // No row of ours: this run never got as far as creating one. It still
        // has to be visible — a request that vanishes without a trace is
        // indistinguishable from a night nobody looked at — but it gets a row
        // of its own rather than taking somebody else's.
        if ($backup === null) {
            app(BackupRunner::class)->recordUnstarted($this->userId, 'הגיבוי נכשל לפני שהתחיל: '.$e->getMessage());

            return;
        }

        // Through the runner, so the team gets the email too — a status quietly
        // flipped in the database is not a notification.
        app(BackupRunner::class)->fail($backup, $e->getMessage());
    }
}
