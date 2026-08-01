<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The last time a moving part of the system was known to be alive.
 *
 * @see database/migrations/..._create_health_heartbeats_table.php for why
 */
class HealthHeartbeat extends Model
{
    /** The scheduler process itself (php artisan schedule:work / cron). */
    public const SCHEDULER = 'scheduler';

    /** A queue worker — stamped by a job, so it proves work is being RUN. */
    public const QUEUE = 'queue';

    /**
     * The worker serving the ORDINARY queue, where charges, invoices and
     * notifications live. Stamped separately because the beat above rides a
     * queue of its own: with two worker processes, that one can go on
     * reporting cheerfully while the process that does the actual work is
     * dead — and nothing else would notice until a customer did.
     */
    public const WORKLOAD = 'queue-workload';

    public $timestamps = false;

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['name', 'beat_at'];

    protected function casts(): array
    {
        return ['beat_at' => 'datetime'];
    }

    /**
     * Record that this part is alive right now.
     *
     * Best-effort by design: a heartbeat that throws would take down the thing
     * it is only meant to observe — a scheduler tick, or a job that has real
     * work to do.
     */
    public static function beat(string $name): void
    {
        rescue(
            fn () => static::query()->updateOrInsert(['name' => $name], ['beat_at' => now()]),
            report: false,
        );
    }

    /** When that part was last alive, or null if it never reported at all. */
    public static function lastBeat(string $name): ?Carbon
    {
        return rescue(
            fn (): ?Carbon => static::query()->whereKey($name)->first()?->beat_at,
            null,
            report: false,
        );
    }
}
