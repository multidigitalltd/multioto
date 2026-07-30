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
    */

    'enabled' => (bool) env('BACKUP_ENABLED', true),

    /*
    | The filesystem disk backups are written to. Must be off-box (S3 or any
    | S3-compatible provider) and PRIVATE: an archive holds customer names,
    | phone numbers, addresses and invoice history.
    */
    'disk' => env('BACKUP_DISK', 's3'),

    /** Folder inside that disk. */
    'path' => env('BACKUP_PATH', 'multioto-backups'),

    /** Time of day (server timezone) the nightly backup runs. */
    'daily_at' => env('BACKUP_DAILY_AT', '03:30'),

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
    'files' => [
        // Ticket attachments (private).
        'local' => [],
        // Branding: the logo shown in customer emails and the portal.
        'public' => [],
    ],

    /** A single file larger than this is skipped and noted in the manifest. */
    'max_file_bytes' => (int) env('BACKUP_MAX_FILE_BYTES', 64 * 1024 * 1024),

    /*
    | Typed by the operator to confirm a restore. Restoring replaces every row
    | in the database, so it must never be one careless click.
    */
    'restore_confirmation' => 'שחזר',

];
