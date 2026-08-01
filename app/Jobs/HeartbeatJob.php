<?php

namespace App\Jobs;

use App\Models\HealthHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Proof that a queue WORKER is alive — not merely that the queue accepts work.
 *
 * The distinction is the whole point: when the worker dies, dispatching keeps
 * succeeding, nothing throws, and jobs pile up in perfect silence. Only a job
 * that actually ran can say otherwise, and this one does nothing else, so it
 * can never be the reason the answer is late.
 */
class HeartbeatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function handle(): void
    {
        HealthHeartbeat::beat(HealthHeartbeat::QUEUE);
    }
}
