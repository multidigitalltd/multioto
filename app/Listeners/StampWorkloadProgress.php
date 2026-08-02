<?php

namespace App\Listeners;

use App\Jobs\HeartbeatJob;
use App\Models\HealthHeartbeat;
use Illuminate\Queue\Events\JobProcessed;

/**
 * A mark, made by the worker itself, every time it finishes a job.
 *
 * The workload heartbeat rides the same queue as the work, so a worker grinding
 * through two hundred monitor jobs leaves that beat waiting its turn for as long
 * as the batch takes — while working the whole time. Queue DEPTH cannot tell
 * that apart from a dead worker either: work arriving as fast as it is finished
 * keeps the depth flat. Only "a job just completed" is monotone, and only the
 * worker can say it.
 *
 * Stamped at most once a minute, so a queue running flat out writes one row a
 * minute rather than one per job. The heartbeat queue is skipped — it has a beat
 * of its own, and it is precisely the one that cannot answer for the workload.
 */
class StampWorkloadProgress
{
    public function handle(JobProcessed $event): void
    {
        if ($event->job->getQueue() === HeartbeatJob::QUEUE) {
            return;
        }

        $last = HealthHeartbeat::lastBeat(HealthHeartbeat::PROGRESS);

        if ($last !== null && $last->gt(now()->subMinute())) {
            return;
        }

        HealthHeartbeat::beat(HealthHeartbeat::PROGRESS);
    }
}
