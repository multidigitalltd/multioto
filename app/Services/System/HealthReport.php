<?php

namespace App\Services\System;

use App\Models\HealthHeartbeat;
use App\Services\Backup\BackupRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Is the machinery running?
 *
 * Nearly everything this system does happens in a queued job dispatched by the
 * scheduler — charges, dunning, invoices, notifications, backups. When either
 * stops, nothing fails: there is no exception, no failed row, no angry log
 * line. The night simply passes quietly, and it keeps passing quietly until a
 * customer asks why nobody charged them.
 *
 * So the question is asked from the outside in: two heartbeats prove the parts
 * are alive, and three counters prove work is actually getting through.
 * Everything here is cheap and local — no external API is called, because a
 * health check that depends on somebody else's uptime reports their weather,
 * not ours.
 */
class HealthReport
{
    /** Nothing wrong. */
    public const OK = 'ok';

    /** Working, but something needs a person: a backlog, failures, a stale backup. */
    public const DEGRADED = 'degraded';

    /** A moving part has stopped. Work is not getting done at all. */
    public const DOWN = 'down';

    /**
     * @return array{status: string, checks: list<array{key: string, label: string, status: string, detail: string}>}
     */
    public function collect(): array
    {
        $checks = [
            $this->database(),
            $this->heartbeat(
                HealthHeartbeat::SCHEDULER,
                'scheduler',
                'המתזמן',
                (int) config('health.scheduler_stale_minutes', 15),
                'המתזמן לא דיווח על עצמו — ייתכן ש-schedule:work אינו רץ. שום עבודה מתוזמנת לא מתבצעת.',
            ),
            $this->heartbeat(
                HealthHeartbeat::QUEUE,
                'queue',
                'עובד התור',
                (int) config('health.queue_stale_minutes', 30),
                'אף עובד תור לא ביצע עבודה — ייתכן ש-Horizon אינו רץ. עבודות נכנסות לתור ולא מתבצעות.',
            ),
            $this->backlog(),
            $this->failedJobs(),
            $this->backup(),
        ];

        return [
            'status' => $this->worst($checks),
            'checks' => $checks,
        ];
    }

    /** Just the headline, for the endpoint that must give nothing away. */
    public function status(): string
    {
        return $this->collect()['status'];
    }

    /** What is wrong right now, in Hebrew — empty when all is well. */
    public function problems(): array
    {
        return collect($this->collect()['checks'])
            ->reject(fn (array $check): bool => $check['status'] === self::OK)
            ->values()
            ->all();
    }

    /** @param list<array{status: string}> $checks */
    private function worst(array $checks): string
    {
        foreach ([self::DOWN, self::DEGRADED] as $level) {
            foreach ($checks as $check) {
                if ($check['status'] === $level) {
                    return $level;
                }
            }
        }

        return self::OK;
    }

    /**
     * The database answers at all. Everything below reads from it, so a failure
     * here is reported as the cause rather than as five confusing symptoms.
     */
    private function database(): array
    {
        try {
            DB::connection()->select('select 1');

            return $this->check('database', 'מסד הנתונים', self::OK, 'מגיב.');
        } catch (\Throwable $e) {
            return $this->check('database', 'מסד הנתונים', self::DOWN, 'אין חיבור למסד הנתונים.');
        }
    }

    private function heartbeat(string $name, string $key, string $label, int $staleMinutes, string $problem): array
    {
        $last = HealthHeartbeat::lastBeat($name);

        if ($last === null) {
            // Never reported at all: a fresh install that has not run yet, or a
            // part that has never started. Either way it is not proof of life.
            return $this->check($key, $label, self::DOWN, 'לא דיווח מעולם. '.$problem);
        }

        if ($staleMinutes > 0 && $last->lt(now()->subMinutes($staleMinutes))) {
            return $this->check(
                $key,
                $label,
                self::DOWN,
                'הדיווח האחרון: '.$last->diffForHumans().'. '.$problem,
            );
        }

        return $this->check($key, $label, self::OK, 'פעיל ('.$last->diffForHumans().').');
    }

    /**
     * How much work is waiting. Counted per queue, because one blocked queue
     * behind a healthy one is exactly the case a single number hides.
     */
    private function backlog(): array
    {
        $limit = (int) config('health.queue_backlog', 250);
        $sizes = [];

        foreach ($this->queues() as $queue) {
            $size = rescue(fn (): int => Queue::size($queue), null, report: false);

            if ($size !== null) {
                $sizes[$queue] = $size;
            }
        }

        if ($sizes === []) {
            return $this->check('backlog', 'עומס בתור', self::OK, 'לא ניתן למדוד — מדלג.');
        }

        $total = array_sum($sizes);
        $detail = collect($sizes)->map(fn (int $size, string $queue): string => "{$queue}: {$size}")->implode(' · ');

        return $this->check(
            'backlog',
            'עומס בתור',
            $limit > 0 && $total >= $limit ? self::DEGRADED : self::OK,
            $detail,
        );
    }

    /** @return list<string> */
    private function queues(): array
    {
        $connection = (string) config('queue.default');
        $default = (string) config("queue.connections.{$connection}.queue", 'default');

        return collect([$default, ...(array) config('horizon.defaults.supervisor-1.queue', [])])
            ->filter(fn ($queue): bool => is_string($queue) && $queue !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Jobs that gave up. A job that failed after its retries is work that was
     * asked for and never done — a charge, a reply, an invoice.
     */
    private function failedJobs(): array
    {
        $limit = (int) config('health.failed_jobs', 5);

        $count = rescue(
            fn (): int => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
            null,
            report: false,
        );

        if ($count === null) {
            return $this->check('failed_jobs', 'עבודות שנכשלו', self::OK, 'לא ניתן למדוד — מדלג.');
        }

        return $this->check(
            'failed_jobs',
            'עבודות שנכשלו',
            $limit > 0 && $count >= $limit ? self::DEGRADED : self::OK,
            $count === 0 ? 'אין כשלים ביממה האחרונה.' : "{$count} ביממה האחרונה.",
        );
    }

    /**
     * The same question the backup screen asks on every page load — repeated
     * here so one place answers "is everything fine" completely.
     *
     * Rows only: verifying that the archive is really in the bucket means a
     * request to S3, and this endpoint is probed every few minutes and answers
     * "the application is down" if it blocks. A slow bucket would then be
     * reported as a dead system. The screen and the nightly check still do the
     * full verification, where waiting is free.
     */
    private function backup(): array
    {
        $warning = rescue(
            fn (): ?string => app(BackupRunner::class)->staleWarning(verifyOnDisk: false),
            null,
            report: false,
        );

        return $this->check(
            'backup',
            'גיבוי',
            $warning === null ? self::OK : self::DEGRADED,
            $warning ?? 'עדכני.',
        );
    }

    private function check(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
