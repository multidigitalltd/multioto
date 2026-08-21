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
     * Tables that accumulate, and the config key holding their retention.
     *
     * Business records — customers, charges, invoices, tickets, subscriptions —
     * are deliberately absent: they are the product, not exhaust, and nothing
     * here should ever suggest deleting them.
     *
     * @var array<string, string|null>
     */
    private const TRACKED = [
        'monitor_checks' => 'billing.system.monitor_check_retention_days',
        'system_logs' => 'billing.system.log_retention_days',
        'webhook_events' => 'billing.system.webhook_retention_days',
        'notifications' => 'billing.system.notification_retention_days',
        'notification_logs' => 'billing.system.notification_log_retention_days',
        'site_changes' => 'billing.system.site_change_retention_days',
        'site_events' => 'billing.system.site_event_retention_days',
        'site_audits' => 'billing.system.site_audit_retention_days',
        'failed_jobs' => 'billing.system.failed_job_retention_days',
        'audit_logs' => 'security.audit.retention_days',
        // No window on purpose — listed so the absence is visible rather than
        // assumed. Each is either small, or something a person must decide to
        // delete.
        'pending_actions' => null,
        'dunning_events' => null,
        'ai_usage_daily' => null,
        'incidents' => null,
    ];

    public function handle(): int
    {
        $rows = [];

        foreach (self::TRACKED as $table => $configKey) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $count = (int) DB::table($table)->count();
            $days = $configKey !== null ? (int) config($configKey, 0) : 0;

            $rows[] = [
                $table,
                number_format($count),
                $this->size($table),
                $configKey === null ? '— ללא ניקוי' : "{$days} ימים",
                $configKey !== null && $days > 0
                    ? number_format((int) DB::table($table)->where('created_at', '<', now()->subDays($days))->count())
                    : '—',
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
