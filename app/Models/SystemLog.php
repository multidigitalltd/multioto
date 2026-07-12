<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * A single in-panel system-log entry, shown on the "מערכת ועדכונים" screen.
 * Recording never throws — logging must not break the operation that logged.
 * Only created_at is tracked (append-only); old rows are pruned by the scheduler.
 */
class SystemLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['level', 'source', 'message', 'context', 'created_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Append a log entry. Best-effort: swallows any failure (missing table
     * during boot/tests, DB hiccup) so it can be called from anywhere safely.
     *
     * @param  array<string, mixed>  $context
     */
    public static function record(string $level, string $source, string $message, array $context = []): void
    {
        try {
            if (! Schema::hasTable('system_logs')) {
                return;
            }

            static::create([
                'level' => $level,
                'source' => $source,
                'message' => Str::limit($message, 490),
                'context' => $context ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Never let logging break the caller.
        }
    }

    /** Delete entries older than the given number of days. Returns rows removed. */
    public static function prune(int $days): int
    {
        return static::query()->where('created_at', '<', now()->subDays($days))->delete();
    }
}
