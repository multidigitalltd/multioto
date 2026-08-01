<?php

use App\Jobs\HeartbeatJob;

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Only the supervisor definition is set here; every other Horizon setting
    | (paths, trimming, metrics, memory limit) keeps the package default.
    |
    | Two queues, deliberately:
    |
    |   default    — all the real work: charges, invoices, notifications, backups.
    |   heartbeat  — nothing but HeartbeatJob, the proof that a worker is alive.
    |
    | They are separated because a heartbeat queued BEHIND a long backup or a
    | large batch would go stale while the system is perfectly healthy, and
    | /health would then tell an external monitor the worker had stopped. The
    | probe must never queue behind the thing it is measuring.
    |
    | A plain worker instead of Horizon must therefore serve both:
    |   php artisan queue:work --queue=default,heartbeat
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default', HeartbeatJob::QUEUE],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            // At least one process per queue: with a single one the balancer
            // has nothing to give the heartbeat queue while the default queue
            // is working, which is the very starvation this split prevents.
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
        ],
    ],

];
