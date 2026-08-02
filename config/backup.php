<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic backups
    |--------------------------------------------------------------------------
    |
    | A nightly archive of everything the business cannot be rebuilt without:
    | every database row plus the uploaded files (ticket attachments, logos).
    | It is written to an EXTERNAL disk on purpose — a backup that lives on the
    | same server as the thing it protects is not a backup.
    |
    | The switch below governs the NIGHTLY run only. "גבה עכשיו" is an explicit
    | request and still runs — a button that quietly does nothing is worse than
    | one that is not there.
    |
    */

    'enabled' => (bool) env('BACKUP_ENABLED', true),

    /*
    | The filesystem disk backups are written to. Must be off-box (S3 or any
    | S3-compatible provider) and PRIVATE: an archive holds customer names,
    | phone numbers, addresses and invoice history.
    */
    'disk' => env('BACKUP_DISK', 's3'),

    /**
     * Allow a destination that PHP sees as a local folder.
     *
     * Off by default, because a backup on the same machine as the data is not
     * a backup: the server loss this feature exists for would take both. Turn
     * it on only when that folder is a mounted volume that lives somewhere
     * else — PHP cannot tell the difference, so this is the operator saying so.
     */
    'allow_local_destination' => (bool) env('BACKUP_ALLOW_LOCAL_DESTINATION', false),

    /** Folder inside that disk. */
    'path' => env('BACKUP_PATH', 'multioto-backups'),

    /** Time of day (server timezone) the nightly backup runs. */
    'daily_at' => env('BACKUP_DAILY_AT', '03:30'),

    /**
     * Alert the team when no backup has completed for this many hours.
     *
     * The safety net under everything else: a queue with no worker, or a
     * scheduler that stopped, produces no failed row at all, and that silence
     * is indistinguishable from a healthy night. Set to 0 to switch off.
     */
    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 36),

    /**
     * How long a restore claim may sit unstarted before it can be taken over.
     *
     * A queue that accepted the job and never ran it would otherwise leave the
     * row refusing every later attempt for ever. Taking it over is safe because
     * the attempt id changes with it — a payload that turns up late finds
     * itself superseded and stops.
     */
    /*
    | How long a backup may go without anyone opening it before the health
    | screen says so. The drill runs monthly; this is the window in which a
    | missed run stops being a coincidence.
    */
    'drill_stale_days' => (int) env('BACKUP_DRILL_STALE_DAYS', 45),

    'restore_claim_minutes' => (int) env('BACKUP_RESTORE_CLAIM_MINUTES', 30),

    /** Archives older than this are pruned after each successful run. */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /** Never prune below this many archives, however old they are. */
    'keep_at_least' => (int) env('BACKUP_KEEP_AT_LEAST', 7),

    /*
    | Runtime tables: queue payloads, sessions, caches. They describe what the
    | server was doing at the moment of the dump, not the business, and
    | restoring them would resurrect stale jobs and log everyone in to a
    | half-finished session. The schema itself is never restored — migrations
    | own that — so the migrations table is recorded for verification only.
    */
    'exclude_tables' => [
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'migrations',
        // The backup history itself. Restoring it would delete the list of
        // archives mid-restore — including the row tracking the restore in
        // progress — and leave the business unable to restore again.
        'backups',
    ],

    /*
    | Uploaded files to include, as disk => list of path prefixes. Empty list
    | means the whole disk.
    */
    'files' => array_fill_keys(array_values(array_unique([
        // Ticket attachments (private).
        'local',
        // Branding: the logo shown in customer emails and the portal.
        'public',
        // Wherever attachments actually go. Reading it from the same place the
        // store does means a deployment that moved them cannot end up with a
        // backup that quietly leaves them out — the rows referencing them are
        // archived either way, so the gap would only show at a restore.
        (string) env('ATTACHMENT_DISK', 'local'),
    ])), []),

    /**
     * How long a money job holds itself when a backup or restore is running.
     *
     * A charge or an invoice cannot be taken back once the outside world has
     * accepted it, so those jobs wait rather than run alongside a restore that
     * is about to replace the rows recording them.
     */
    'worker_hold_minutes' => (int) env('BACKUP_WORKER_HOLD_MINUTES', 5),

    /**
     * How long a run marked "running" is still believed to be running.
     *
     * A row left behind by a worker that vanished must not stop the business
     * billing for ever. Past this window it counts as abandoned — comfortably
     * beyond the 30-minute ceiling either job is allowed.
     */
    'operation_window_minutes' => (int) env('BACKUP_OPERATION_WINDOW_MINUTES', 60),

    /**
     * How many external references (Cardcom pages/transactions, Linet documents)
     * a restore compares against the archive before it stops collecting.
     *
     * A bound is needed — the comparison runs inside the restore transaction —
     * but reaching it is reported rather than hidden: the references are read
     * oldest first, so a scan that fills up never reached the newest rows, which
     * are the ones a restore is most likely to drop.
     */
    'reconcile_limit' => (int) env('BACKUP_RECONCILE_LIMIT', 5000),

    /** A single file larger than this is skipped and noted in the manifest. */
    'max_file_bytes' => (int) env('BACKUP_MAX_FILE_BYTES', 64 * 1024 * 1024),

    /*
    | Typed by the operator to confirm a restore. Restoring replaces every row
    | in the database, so it must never be one careless click.
    */
    'restore_confirmation' => 'שחזר',

];
