<?php

namespace App\Services\System;

use App\Models\HealthHeartbeat;
use App\Providers\SettingsServiceProvider;
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
        $database = $this->database();

        // Everything below reads from the database. When it is refusing
        // connections the rest merely repeat the same failure, but when it is
        // silently TIMING OUT each of them waits out its own connect timeout in
        // turn — and the endpoint that exists to say "down" quickly instead
        // holds the request open until the web server gives up on it, while the
        // monitor probes again every few minutes. The cause is answered alone.
        if ($database['status'] === self::DOWN) {
            return ['status' => self::DOWN, 'checks' => [$database]];
        }

        // Only now: the settings overlay (backup window, thresholds a person
        // changed in the panel) is skipped while booting a health probe, so
        // that a dead database is answered above instead of waited on. The
        // database has just proved it answers, so reading them is safe — and
        // the checks below must use what the panel says, not only .env.
        rescue(fn () => SettingsServiceProvider::refreshFromDatabase(), report: false);

        $queue = $this->heartbeat(
            HealthHeartbeat::QUEUE,
            'queue',
            'עובד התור',
            (int) config('health.queue_stale_minutes', 30),
            'אף עובד תור לא ביצע עבודה — ייתכן ש-Horizon אינו רץ. עבודות נכנסות לתור ולא מתבצעות.',
        );

        $checks = [
            $database,
            $this->heartbeat(
                HealthHeartbeat::SCHEDULER,
                'scheduler',
                'המתזמן',
                (int) config('health.scheduler_stale_minutes', 15),
                'המתזמן לא דיווח על עצמו — ייתכן ש-schedule:work אינו רץ. שום עבודה מתוזמנת לא מתבצעת.',
            ),
            $queue,
            // Not "down", on purpose: a three-hour backup on the ordinary queue
            // delays this beat exactly as a dead worker would, and the endpoint
            // must not call a busy system dead. It is the answer to the
            // question the private heartbeat above cannot answer — whether the
            // worker doing the REAL work is still getting through it.
            $this->heartbeat(
                HealthHeartbeat::WORKLOAD,
                'workload',
                'תור העבודה',
                (int) config('health.workload_stale_minutes', 60),
                'העבודה הרגילה (חיובים, חשבוניות, הודעות) לא התקדמה — worker שנעצר, או עבודה ארוכה שתקועה.',
                self::DEGRADED,
            ),
            // Counting the backlog means talking to the queue itself, and one
            // reason nothing has been run is that the queue host stopped
            // answering — a dropped Redis connection waits out the socket
            // timeout before rescue() ever sees it, on every probe. The
            // verdict is already "down" and the depth of a queue nobody is
            // draining changes nothing, so the question is not asked.
            $queue['status'] === self::DOWN
                ? $this->check('backlog', 'עומס בתור', self::OK, 'לא נמדד — עובד התור אינו מגיב.')
                : $this->backlog(),
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

    private function heartbeat(
        string $name,
        string $key,
        string $label,
        int $staleMinutes,
        string $problem,
        string $severity = self::DOWN,
    ): array {
        $last = HealthHeartbeat::lastBeat($name);

        if ($last === null) {
            // Never reported at all: a fresh install that has not run yet, or a
            // part that has never started. Either way it is not proof of life.
            return $this->check($key, $label, $severity, 'לא דיווח מעולם. '.$problem);
        }

        if ($staleMinutes > 0 && $last->lt(now()->subMinutes($staleMinutes))) {
            return $this->check(
                $key,
                $label,
                $severity,
                'הדיווח האחרון: '.$last->diffForHumans().'. '.$problem,
            );
        }

        return $this->check($key, $label, self::OK, 'פעיל ('.$last->diffForHumans().').');
    }

    /**
     * How much work is waiting. Counted per queue, because one blocked queue
     * behind a healthy one is exactly the case a single number hides.
     *
     * Asked with a stopwatch — see probe(). A heartbeat that has not yet gone
     * stale says nothing about the queue host RIGHT NOW: it can drop a minute
     * after a perfectly good beat, and for the next half hour this is the only
     * part of the report that would still try to talk to it.
     */
    private function backlog(): array
    {
        $limit = (int) config('health.queue_backlog', 250);
        $sizes = [];
        $probe = $this->probe();

        foreach ($this->queues() as $queue) {
            $size = rescue(fn (): int => $probe->size($queue), null, report: false);

            if ($size !== null) {
                $sizes[$queue] = $size;
            }
        }

        // Not one queue answered. That is not "no information" — it is the
        // queue host refusing to talk, which means nothing can be dispatched or
        // run at all, whatever the heartbeats still say: they only report how
        // things were up to half an hour ago, and this is a live answer.
        if ($sizes === []) {
            return $this->check(
                'backlog',
                'עומס בתור',
                self::DOWN,
                'שרת התור לא ענה — לא ניתן למדוד. עבודות אינן נכנסות לתור ואינן מתבצעות.',
            );
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

    /**
     * The queue connection this endpoint is allowed to ask, with a bounded wait.
     *
     * A Redis host that stops answering — rather than refusing — holds the
     * socket open for the client's default timeout, and rescue() only sees the
     * failure once that wait is over. On the one request whose purpose is to
     * report quickly that something stopped, that is the difference between a
     * 503 naming the queue and a gateway timeout naming nothing.
     *
     * So the measurement (and only the measurement) runs over a copy of the
     * connection pointed at the short-timeout Redis profile. Workers keep the
     * ordinary one, where a blocking pop must be allowed to wait. Any other
     * driver is left exactly as configured: the database queue is already
     * covered by the database check above.
     */
    private function probe(): \Illuminate\Contracts\Queue\Queue
    {
        $name = (string) config('queue.default');
        $connection = (array) config("queue.connections.{$name}", []);

        if (($connection['driver'] ?? null) !== 'redis' || ! is_array(config('database.redis.health'))) {
            return Queue::connection($name);
        }

        config(['queue.connections.health-probe' => ['connection' => 'health'] + $connection]);

        return Queue::connection('health-probe');
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
