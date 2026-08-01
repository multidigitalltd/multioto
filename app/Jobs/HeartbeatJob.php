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
 *
 * It runs on its OWN queue (served alongside the default one — see
 * config/horizon.php) so that "the worker stopped" is never confused with "the
 * worker is busy": behind a long backup or a large batch on the shared queue
 * the beat would wait its turn, go stale, and report a perfectly healthy system
 * as dead — at three in the morning, to whoever the monitor calls.
 */
class HeartbeatJob implements ShouldQueue
{
    use Queueable;

    /** The queue this probe rides on, kept clear of ordinary workload. */
    public const QUEUE = 'heartbeat';

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        HealthHeartbeat::beat(HealthHeartbeat::QUEUE);
    }
}
