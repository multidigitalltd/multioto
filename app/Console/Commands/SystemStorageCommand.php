<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * What is actually taking up room, and what is set to clean itself.
 *
 * Retention windows are easy to set and impossible to remember. This puts the
 * two facts side by side — how big a table is, and how long its rows live — so
 * a window that is wrong is visible as a big number next to a long one, rather
 * than discovered when a disk fills.
 *
 * A table with no window at all is listed as such. That is the row worth
 * reading: everything else at least has a ceiling.
 */
class SystemStorageCommand extends Command
{
    protected $signature = 'system:storage';

    protected $description = 'Show table sizes, their retention windows, and log-file usage';

    /**
     * Tables that accumulate: the config key holding their retention, and the
     * column that says how old a row is.
     *
     * The age column is named per table rather than assumed. Not every table
     * has `created_at` — `monitor_checks` stamps `checked_at` and `failed_jobs`
     * stamps `failed_at` — and assuming one name made the whole report die on
     * the first table instead of showing the other thirteen.
     *
     * Business records — customers, charges, invoices, tickets, subscriptions —
     * are deliberately absent: they are the product, not exhaust, and nothing
     * here should ever suggest deleting them.
     *
     * @var array<string, array{0: string|null, 1: string}>
     */
    private const TRACKED = [
        'monitor_checks' => ['billing.system.monitor_check_retention_days', 'checked_at'],
        'system_logs' => ['billing.system.log_retention_days', 'created_at'],
        'webhook_events' => ['billing.system.webhook_retention_days', 'created_at'],
        'notifications' => ['billing.system.notification_retention_days', 'created_at'],
        'notification_logs' => ['billing.system.notification_log_retention_days', 'created_at'],
        'site_changes' => ['billing.system.site_change_retention_days', 'created_at'],
        'site_events' => ['billing.system.site_event_retention_days', 'created_at'],
        'site_audits' => ['billing.system.site_audit_retention_days', 'created_at'],
        'failed_jobs' => ['billing.system.failed_job_retention_days', 'failed_at'],
        'audit_logs' => ['security.audit.retention_days', 'created_at'],
        // No window on purpose — listed so the absence is visible rather than
        // assumed. Each is either small, or something a person must decide to
        // delete.
        'pending_actions' => [null, 'created_at'],
        'dunning_events' => [null, 'created_at'],
        'ai_usage_daily' => [null, 'created_at'],
        'incidents' => [null, 'created_at'],
    ];

    public function handle(): int
    {
        $rows = [];

        foreach (self::TRACKED as $table => [$configKey, $ageColumn]) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $days = $configKey !== null ? (int) config($configKey, 0) : 0;

            $rows[] = [
                $table,
                number_format((int) DB::table($table)->count()),
                $this->size($table),
                $configKey === null ? '— ללא ניקוי' : "{$days} ימים",
                $this->waiting($table, $ageColumn, $days),
            ];
        }

        $this->info('טבלאות שמצטברות:');
        $this->table(['טבלה', 'שורות', 'נפח', 'שמירה', 'ממתין למחיקה'], $rows);

        $this->newLine();
        $this->logFiles();

        return self::SUCCESS;
    }

    /** The application's own log files — the part no database prune touches. */
    private function logFiles(): void
    {
        $dir = storage_path('logs');
        $files = File::isDirectory($dir) ? File::files($dir) : [];
        $total = 0;
        $biggest = null;

        foreach ($files as $file) {
            $total += $file->getSize();

            if ($biggest === null || $file->getSize() > $biggest->getSize()) {
                $biggest = $file;
            }
        }

        $channels = implode(', ', (array) config('logging.channels.stack.channels', []));

        $this->info('קובצי לוג:');
        $this->line('  ערוץ: '.$channels.' · רמה: '.config('logging.channels.daily.level'));
        $this->line('  '.count($files).' קבצים · '.$this->bytes($total));

        if ($biggest !== null) {
            $this->line('  הגדול ביותר: '.$biggest->getFilename().' ('.$this->bytes($biggest->getSize()).')');
        }

        // The single-file channel is the one that has no ceiling at all, so it
        // is called out rather than left for the reader to infer from a name.
        if (in_array('single', (array) config('logging.channels.stack.channels', []), true)) {
            $this->newLine();
            $this->warn('  הערוץ "single" כותב קובץ אחד שגדל ללא גבול. מומלץ LOG_STACK=daily.');
        }
    }

    /**
     * How many rows are already past their window.
     *
     * Guarded on the column existing: a report about disk usage must never be
     * the thing that stops the report. A table whose age column was renamed
     * shows "—" and everything else still prints.
     */
    private function waiting(string $table, string $ageColumn, int $days): string
    {
        if ($days <= 0) {
            return '—';
        }

        try {
            if (! DB::getSchemaBuilder()->hasColumn($table, $ageColumn)) {
                return '?';
            }

            return number_format((int) DB::table($table)
                ->where($ageColumn, '<', now()->subDays($days))
                ->count());
        } catch (\Throwable) {
            return '?';
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Postgres knows its own on-disk size; anything else reports unknown. */
    private function size(string $table): string
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return '—';
        }

        try {
            // The table name comes from the constant above, never from input.
            $bytes = (int) DB::selectOne('select pg_total_relation_size(?) as bytes', [$table])?->bytes;

            return $this->bytes($bytes);
        } catch (\Throwable) {
            return '—';
        }
    }

    private function bytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
