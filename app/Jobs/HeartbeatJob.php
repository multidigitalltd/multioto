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
 * TWO beats, because one queue cannot answer for the other:
 *
 *   HealthHeartbeat::QUEUE     rides a queue of its OWN, so that "the worker
 *                              stopped" is never confused with "the worker is
 *                              busy" — behind a long backup the beat would
 *                              wait its turn, go stale, and report a healthy
 *                              system as dead at three in the morning.
 *
 *   HealthHeartbeat::WORKLOAD  rides the ORDINARY queue, where charges and
 *                              invoices are. Under a plain two-process setup
 *                              the first beat proves only its own worker; this
 *                              one is what says the work itself is moving. It
 *                              is deliberately judged on a long window and
 *                              never as "down", because a genuinely long job
 *                              delays it too.
 */
class HeartbeatJob implements ShouldQueue
{
    use Queueable;

    /** The queue the isolated probe rides on, kept clear of ordinary workload. */
    public const QUEUE = 'heartbeat';

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public string $beat = HealthHeartbeat::QUEUE)
    {
        // The workload beat has to queue where the real work queues — that is
        // the whole point of it. Everything else stays on the private queue.
        if ($beat !== HealthHeartbeat::WORKLOAD) {
            $this->onQueue(self::QUEUE);
        }
    }

    public function handle(): void
    {
        HealthHeartbeat::beat($this->beat);
    }
}
