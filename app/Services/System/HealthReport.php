<?php

namespace App\Services\System;

use App\Models\Backup;
use App\Models\HealthHeartbeat;
use App\Providers\SettingsServiceProvider;
use App\Services\Backup\BackupRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\ConfigurationUrlParser;
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
            $this->workload(),
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
            $this->drill(),
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
            $this->askTheDatabase();

            return $this->check('database', 'מסד הנתונים', self::OK, 'מגיב.');
        } catch (\Throwable $e) {
            return $this->check('database', 'מסד הנתונים', self::DOWN, 'אין חיבור למסד הנתונים.');
        }
    }

    /**
     * Is the queue that carries the real work — charges, invoices, notifications
     * — actually moving? The private heartbeat above cannot answer that: with
     * two worker processes it goes on reporting long after the one doing the
     * work has died.
     *
     * Two windows, because the same silence means two different things:
     *
     *   quiet a while   a long job may simply be running. Worth a look, not a
     *                   phone call — an endpoint that calls a busy system dead
     *                   is one nobody trusts the next time it complains.
     *
     *   quiet far too long  no job may hold a worker that long — the longest
     *                   runs an hour and is then killed by its own timeout, at
     *                   which point the next beat lands. Silence past the
     *                   second window has no innocent explanation, so it is
     *                   reported as what it is — work has stopped — and the
     *                   monitor gets its 503.
     */
    private function workload(): array
    {
        $stopped = 'העבודה הרגילה (חיובים, חשבוניות, הודעות) לא מתבצעת — ה-worker של תור העבודה נעצר.';
        $slow = 'העבודה הרגילה לא התקדמה — ייתכן worker שנעצר, וייתכן עבודה ארוכה שרצה כרגע.';

        $down = (int) config('health.workload_down_minutes', 60);

        $check = $this->heartbeat(
            HealthHeartbeat::WORKLOAD,
            'workload',
            'תור העבודה',
            $down,
            $stopped,
        );

        // Before calling it dead, ask the one question this beat cannot answer.
        // A single worker grinding through a long batch — one monitor job per
        // site, say — leaves the beat waiting its turn behind them for as long
        // as the batch takes, and it is working the whole time. Every job it
        // FINISHES stamps a mark of its own, which is a count of work done
        // rather than a queue depth: work arriving as fast as it is finished
        // keeps the depth flat while jobs complete one after another.
        if ($check['status'] === self::DOWN) {
            $moved = HealthHeartbeat::lastBeat(HealthHeartbeat::PROGRESS);

            if ($moved !== null && $moved->gt(now()->subMinutes($down))) {
                return $this->check('workload', 'תור העבודה', self::DEGRADED, $slow);
            }

            return $check;
        }

        return $this->heartbeat(
            HealthHeartbeat::WORKLOAD,
            'workload',
            'תור העבודה',
            (int) config('health.workload_stale_minutes', 30),
            $slow,
            self::DEGRADED,
        );
    }

    /**
     * The one query, asked with a stopwatch.
     *
     * Every way a database can keep this request waiting has to be named, and
     * named where libpq reads it — in the connection string, since only the
     * connect timeout has an environment variable:
     *
     *   connect_timeout      getting connected at all.
     *   options              the server's own statement_timeout, sent in the
     *                        startup packet — the connection can be established
     *                        perfectly and the QUERY never come back.
     *   keepalives*          the peer that acknowledges the query and then goes
     *                        silent, leaving nothing outstanding for a TCP
     *                        timeout to notice.
     *
     * And one case none of those covers: a backend that is reachable, answers
     * at the network level, and simply never produces a result. Only a deadline
     * held by US can end that, so where the pgsql extension is available the
     * query is sent without blocking and abandoned when the clock runs out.
     */
    private function askTheDatabase(): void
    {
        $name = (string) config('database.default');
        $connection = (array) config("database.connections.{$name}", []);

        if (($connection['driver'] ?? null) !== 'pgsql') {
            DB::connection($name)->select('select 1');

            return;
        }

        // Through the same parser the framework uses, because DB_URL is a
        // supported way to configure all of this — read raw, the split fields
        // would still hold their defaults and the probe would cheerfully
        // report 127.0.0.1 as down while the real database is fine.
        $connection = (new ConfigurationUrlParser)->parseConfiguration($connection);
        $seconds = max(1, (int) config('health.database_probe_timeout', 2));

        $parameters = [
            'host' => $connection['host'] ?? '127.0.0.1',
            'port' => $connection['port'] ?? 5432,
            'dbname' => $connection['database'] ?? '',
            'user' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'sslmode' => $connection['sslmode'] ?? 'prefer',
            'connect_timeout' => $seconds,
            'keepalives' => 1,
            'keepalives_idle' => $seconds,
            'keepalives_interval' => $seconds,
            'keepalives_count' => 2,
            'options' => '-c statement_timeout='.($seconds * 1000),
            'application_name' => 'multioto-health',
        ];

        function_exists('pg_connect')
            ? $this->askWithDeadline($parameters, $seconds)
            : $this->askWithPdo($parameters, $seconds);
    }

    /**
     * The question with a deadline WE hold.
     *
     * Sent without blocking, then waited on by the clock rather than by the
     * server's goodwill: a backend that never answers is abandoned after the
     * timeout instead of holding the request until the web server gives up.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function askWithDeadline(array $parameters, int $seconds): void
    {
        $handle = @pg_connect($this->conninfo($parameters, ' '), PGSQL_CONNECT_FORCE_NEW);

        if ($handle === false) {
            throw new \RuntimeException('אין חיבור למסד הנתונים.');
        }

        try {
            if (@pg_send_query($handle, 'select 1') === false) {
                throw new \RuntimeException('אין חיבור למסד הנתונים.');
            }

            $deadline = microtime(true) + $seconds;

            while (pg_connection_busy($handle)) {
                if (microtime(true) >= $deadline) {
                    @pg_cancel_query($handle);

                    throw new \RuntimeException('מסד הנתונים לא ענה בזמן.');
                }

                usleep(20_000);
            }

            $result = @pg_get_result($handle);

            // A result object comes back for FAILURES too — a statement_timeout
            // that fired just before our own deadline arrives here as a result,
            // not as false. Taking that for an answer would report a database
            // that cannot run a query as perfectly healthy.
            if ($result === false || ! in_array(pg_result_status($result), [PGSQL_TUPLES_OK, PGSQL_COMMAND_OK], true)) {
                throw new \RuntimeException('מסד הנתונים לא החזיר תשובה תקינה.');
            }
        } finally {
            @pg_close($handle);
        }
    }

    /**
     * Without the pgsql extension there is no way to hold a deadline from here,
     * so the bounds in the connection string are all there is — everything
     * short of a wedged backend that answers the network and nothing else.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function askWithPdo(array $parameters, int $seconds): void
    {
        $credentials = ['user' => '', 'password' => ''];
        $dsn = array_diff_key($parameters, $credentials);

        $pdo = new \PDO('pgsql:'.$this->conninfo($dsn, ';'), (string) $parameters['user'], (string) $parameters['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => $seconds,
        ]);

        try {
            $pdo->query('select 1');
        } finally {
            $pdo = null;
        }
    }

    /**
     * A libpq connection string. Values are quoted, so a password with a space
     * in it stays one value.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function conninfo(array $parameters, string $separator): string
    {
        return collect($parameters)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->map(fn ($value, string $key): string => $key."='".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'")
            ->implode($separator);
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

    /**
     * When somebody last OPENED a backup, not merely wrote one.
     *
     * The check above says an archive exists and is recent. This one says it
     * was read through — the only evidence that the thing in the bucket is a
     * backup rather than a file of the right size. Degraded, never down: the
     * business is running fine either way, and this is a fact for a person to
     * act on rather than a reason to call the system dead.
     */
    private function drill(): array
    {
        $days = max(1, (int) config('backup.drill_stale_days', 45));

        // The report as well as the date. A drill that ran and FAILED still
        // stamps the date, and reading only that would answer "was one opened
        // recently" with a yes — for the next 45 days, right after the check
        // established that the archive is unusable. The backup check above
        // deliberately does not touch the destination, so nothing else would
        // notice.
        $latest = rescue(
            fn () => Backup::query()
                ->whereNotNull('drilled_at')
                ->orderByDesc('drilled_at')
                ->select(['drilled_at', 'drill_report'])
                ->first(),
            null,
            report: false,
        );

        $last = $latest === null ? null : Carbon::parse($latest->drilled_at);
        $problems = (array) ($latest?->drill_report['problems'] ?? []);

        // Before the automation switch is consulted, not after. Turning the
        // nightly run off does not unfind what a drill found, and somebody
        // running manual backups is precisely who presses the button — a
        // recorded failure hidden behind that switch would be the same
        // false green, in the one installation that had to ask for the check.
        if ($problems !== []) {
            return $this->check(
                'drill',
                'בדיקת שחזור',
                self::DEGRADED,
                'הבדיקה האחרונה ('.$last->diffForHumans().') מצאה '.count($problems)
                    .' בעיות בגיבוי — הוא לא ישוחזר במצבו הנוכחי.',
            );
        }

        // Nothing found, and nothing scheduled to find it: "never drilled" is
        // a fact about automation that is switched off on purpose.
        if (! config('backup.enabled')) {
            return $this->check('drill', 'בדיקת שחזור', self::OK, 'גיבוי אוטומטי כבוי — מדלג.');
        }

        if ($last === null) {
            return $this->check(
                'drill',
                'בדיקת שחזור',
                self::DEGRADED,
                'אף גיבוי לא נבדק עדיין. גיבוי שאיש לא פתח הוא תקווה, לא גיבוי.',
            );
        }

        return $this->check(
            'drill',
            'בדיקת שחזור',
            $last->lt(now()->subDays($days)) ? self::DEGRADED : self::OK,
            'נבדק לאחרונה '.$last->diffForHumans().'.',
        );
    }

    private function check(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
