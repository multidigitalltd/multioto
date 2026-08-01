<?php

return [

    /*
    | ----------------------------------------------------------------
    | The health endpoint
    | ----------------------------------------------------------------
    |
    | GET /health answers in two levels. Without a token it says only "ok" or
    | "down" (HTTP 200 / 503) — enough for any external uptime monitor to
    | alarm, and it gives away nothing. With ?token=<HEALTH_TOKEN> it also
    | lists which check failed, for a person looking into it.
    |
    | Point an external monitor at it. That is the whole point: the scheduler
    | cannot report that the scheduler has stopped.
    */
    'token' => env('HEALTH_TOKEN'),

    /*
    | ----------------------------------------------------------------
    | When a moving part counts as stopped
    | ----------------------------------------------------------------
    |
    | The scheduler stamps its heartbeat every minute, and every five minutes
    | it queues a job that stamps a second one when a WORKER runs it — so a
    | queue that accepts jobs but has nobody running them is caught too. The
    | windows are deliberately several times the interval, so an ordinary
    | restart or a slow minute is not an alarm.
    */
    'scheduler_stale_minutes' => (int) env('HEALTH_SCHEDULER_STALE_MINUTES', 15),
    'queue_stale_minutes' => (int) env('HEALTH_QUEUE_STALE_MINUTES', 30),

    /*
    | A backlog this deep, or this many jobs that gave up in the last day,
    | means work is not getting through even if both heartbeats are fine.
    | Reported as "degraded": worth looking at, not worth a 3am phone call.
    */
    'queue_backlog' => (int) env('HEALTH_QUEUE_BACKLOG', 250),
    'failed_jobs' => (int) env('HEALTH_FAILED_JOBS', 5),

    /*
    | ----------------------------------------------------------------
    | The daily money-integrity check
    | ----------------------------------------------------------------
    |
    | Every rule here is one of the invariants the business depends on: a
    | charge that took money must have an invoice, an invoice must belong to a
    | charge that actually succeeded, the two must agree on the amount, and a
    | subscription whose date has passed must have been charged. Nothing is
    | fixed automatically — the report says what looks wrong, a person decides.
    */
    'money' => [
        // Grace for the async invoice job before "no invoice yet" is a finding.
        'invoice_grace_minutes' => (int) env('HEALTH_INVOICE_GRACE_MINUTES', 120),

        // A charge left "pending" this long was neither confirmed nor failed.
        'pending_charge_hours' => (int) env('HEALTH_PENDING_CHARGE_HOURS', 24),

        // The dispatcher runs every fifteen minutes; past this, it is stuck.
        'overdue_charge_hours' => (int) env('HEALTH_OVERDUE_CHARGE_HOURS', 6),

        // How far back to look. Older anomalies were already reported (and
        // either handled or accepted) — repeating them forever trains people
        // to ignore the report.
        'window_days' => (int) env('HEALTH_MONEY_WINDOW_DAYS', 14),

        // Most rows named in the EMAIL; the rest are counted. The log keeps
        // the full list — that is the copy the mail points at, and the one
        // that survives when there is nobody to mail.
        'max_examples' => 10,
        'log_max_rows' => 500,
    ],

];
