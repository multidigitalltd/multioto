<?php

namespace App\Services\SiteAgent\Evaluation;

use App\Enums\UserRole;
use App\Jobs\RunSiteAgentEvaluationJob;
use App\Support\Changelog;
use Closure;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** Admin-owned reports only. Real conversation work runs in an isolated child process. */
class EvaluationRuns
{
    private const ROOT = 'site-agent-evaluations';

    private const TERMINAL = ['completed', 'failed', 'canceled'];

    private const REPORT_LIMITS = [
        'כלי האתר ואתר WordPress מדומים; אין כאן בדיקה של אתר חי.',
        'הסטטוס מציין עמידה בבדיקות המוגדרות לתרחיש. לא בוצעה הערכה סמנטית אנושית.',
        'הרצה שבוטלה או נקטעה אינה משלימה תרחישים שלא הורצו.',
    ];

    private const STREAM_LIMIT = 256 * 1024 * 1024;

    public function __construct(private EvaluationCorpus $corpus) {}

    public function start(int $adminId, string $suite = 'original'): string
    {
        $this->authorize();
        abort_unless((int) Auth::id() === $adminId, 403);
        if (! array_key_exists($suite, EvaluationCorpus::SUITES)) {
            throw ValidationException::withMessages(['evaluation' => 'יש לבחור קבוצת תרחישים תקינה.']);
        }
        $payload = $this->configuration();
        if (! $payload['ai']['enabled'] || trim($payload['ai']['api_key']) === '') {
            throw ValidationException::withMessages(['evaluation' => 'יש להגדיר ולהפעיל את ספק ה-AI לפני התחלת הבדיקה.']);
        }

        return Cache::lock('site-agent-evaluation:start', 15)->block(5, function () use ($adminId, $payload, $suite): string {
            $latest = $this->latest();
            if ($latest !== null && ! in_array($latest['status'], self::TERMINAL, true)) {
                throw ValidationException::withMessages(['evaluation' => 'כבר קיימת בדיקה פעילה. אפשר להמתין או לבקש לעצור אותה.']);
            }
            $cases = $this->manifest($suite);
            $id = (string) Str::uuid();
            $now = now()->toIso8601String();
            $run = [
                'id' => $id, 'status' => 'queued', 'admin_id' => $adminId, 'suite' => $suite,
                'provider' => $payload['ai']['provider'], 'model' => $payload['ai']['model'],
                'total' => count($cases), 'completed' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0,
                'created_at' => $now, 'started_at' => null, 'updated_at' => $now, 'finished_at' => null,
                'reason' => null, 'current_case' => null, 'next_index' => 0, 'claimed_index' => null,
                'version' => Changelog::currentVersion(), 'corpus_sha256' => $this->fingerprint(),
                'configuration_digest' => $this->digest($payload), 'case_ids' => array_column($cases, 'id'),
                'deterministic_case_ids' => array_values(array_column(array_filter($cases,
                    fn (array $case): bool => ($case['expect']['model_required'] ?? true) === false), 'id')),
                'provider_requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0,
                'semantic_review' => 'not_performed',
            ];
            $this->write($id.'/run.json', $run);
            $this->write('latest.json', ['id' => $id]);
            try {
                $this->enqueue($id, 0);
            } catch (Throwable) {
                $this->terminate($run, 'failed', 'לא ניתן היה להכניס את הבדיקה לתור. לא הופעל תרחיש.');
                throw ValidationException::withMessages(['evaluation' => 'לא ניתן היה להכניס את הבדיקה לתור. לא הופעל תרחיש.']);
            }

            return $id;
        });
    }

    public function latest(): ?array
    {
        $this->authorize();
        if (! Storage::disk('local')->exists(self::ROOT.'/latest.json')) {
            return null;
        }
        $latest = $this->read('latest.json');

        return $this->get((string) ($latest['id'] ?? ''));
    }

    public function get(string $id): array
    {
        $this->authorize();

        return $this->publicSummary($this->load($id));
    }

    public function cancel(string $id): void
    {
        $this->authorize();
        $run = $this->load($id);
        if (in_array($run['status'], self::TERMINAL, true)) {
            return;
        }
        // This marker never waits for the lock held by a running inference.
        $this->write($id.'/cancel.json', ['requested_at' => now()->toIso8601String()]);
    }

    public function report(string $id): array
    {
        $this->authorize();
        $run = $this->load($id);

        return ['schema_version' => 1, 'summary' => $this->publicSummary($run),
            'cases' => iterator_to_array($this->reportCases($run, 64 * 1024 * 1024), false),
            'limits' => self::REPORT_LIMITS];
    }

    /**
     * Authorize and validate the snapshot before response headers are sent.
     * Only one immutable case is decoded at a time, including while exporting.
     * Call from a normal HTTP response: Livewire buffers and base64-encodes downloads.
     */
    public function streamReport(string $id): Closure
    {
        $this->authorize();
        $run = $this->load($id);
        $summary = $this->publicSummary($run);
        foreach ($this->reportCases($run, self::STREAM_LIMIT) as $case) {
            unset($case);
        }

        return function () use ($run, $summary): void {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
            echo '{"schema_version":1,"summary":', json_encode($summary, $flags), ',"cases":[';
            $separator = '';
            foreach ($this->reportCases($run, self::STREAM_LIMIT) as $case) {
                echo $separator, json_encode($case, $flags);
                $separator = ',';
                unset($case);
            }
            echo '],"limits":', json_encode(self::REPORT_LIMITS, $flags), '}';
        };
    }

    /** Yield accepted cases only; missing or corrupt accepted output must not disappear silently. */
    private function reportCases(array $run, int $limit): Generator
    {
        $bytes = 0;
        foreach (array_slice($run['case_ids'], 0, $run['completed']) as $caseId) {
            $relative = $this->casePath($run['id'], $caseId);
            $report = $this->read($relative, 8 * 1024 * 1024);
            $bytes += Storage::disk('local')->size(self::ROOT.'/'.$relative);
            if ($bytes > $limit) {
                throw ValidationException::withMessages(['evaluation' => 'הדוח גדול מדי להורדה אחת. יש לפנות לצוות לקבלת קובצי התרחישים.']);
            }
            yield $this->caseResult($report, $caseId, $run);
            unset($report);
        }
    }

    /** Queue entry point; no authenticated session or customer/site data is needed. */
    public function process(string $id, int $index): void
    {
        $this->validateId($id);
        $lock = Cache::lock('site-agent-evaluation:run:'.$id, 1230);
        if (! $lock->get()) {
            return;
        }
        $nextIndex = null;
        try {
            $run = $this->load($id);
            if (in_array($run['status'], self::TERMINAL, true) || $run['next_index'] !== $index
                || $run['claimed_index'] !== null) {
                return;
            }
            if ($this->marker($id, 'failure') || $this->marker($id, 'cancel')) {
                $this->terminate($run, $this->marker($id, 'failure') ? 'failed' : 'canceled',
                    $this->marker($id, 'failure') ? 'הבדיקה נקטעה ולא נוסתה שוב אוטומטית.' : 'הבדיקה נעצרה לבקשת מנהל.');

                return;
            }
            $caseId = $run['case_ids'][$index] ?? null;
            if (! is_string($caseId)) {
                $this->terminate($run, 'failed', 'רשימת התרחישים אינה תקינה.');

                return;
            }
            $payload = $this->configuration();
            if (! hash_equals($run['configuration_digest'], $this->digest($payload))
                || ! hash_equals($run['corpus_sha256'], $this->fingerprint())
                || $run['version'] !== Changelog::currentVersion()) {
                $this->terminate($run, 'failed', 'הגדרות הבוט, גרסת התוכנה או התרחישים השתנו. יש להתחיל בדיקה חדשה כדי להשוות תוצאות עקביות.');

                return;
            }
            $script = base_path('scripts/site-agent-evaluate.php');
            if (! is_file($script) || ! is_file(PHP_BINARY) || ! is_executable(PHP_BINARY)) {
                $this->terminate($run, 'failed', 'סביבת ההרצה המבודדת אינה זמינה. לא הופעל התרחיש.');

                return;
            }
            $run['status'] = 'running';
            $run['claimed_index'] = $index;
            $run['current_case'] = $caseId;
            $run['started_at'] ??= now()->toIso8601String();
            $run['updated_at'] = now()->toIso8601String();
            $this->write($id.'/run.json', $run);
            $output = Storage::disk('local')->path(self::ROOT.'/'.$this->casePath($id, $caseId));
            $this->directory(dirname($output));
            if (is_file($output) || is_link($output)) {
                $this->terminate($run, 'failed', 'כבר קיים פלט לתרחיש. הוא לא הורץ שוב כדי למנוע חיוב כפול.');

                return;
            }

            // Remove inherited integration credentials. Only the allowed AI
            // configuration crosses stdin; it never enters arguments or files.
            $environment = array_fill_keys(array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]), false);
            foreach (['PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_LIBRARY_PATH'] as $name) {
                $runtimeValue = getenv($name);
                $runtimeValue = $runtimeValue !== false ? $runtimeValue : ($_ENV[$name] ?? $_SERVER[$name] ?? null);
                if (is_string($runtimeValue) && $runtimeValue !== '') {
                    $environment[$name] = $runtimeValue;
                }
            }
            $environment['PATH'] = ($environment['PATH'] ?? null) ?: '/usr/bin:/bin';
            $result = Process::path(base_path())->env($environment)
                ->input(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                ->timeout(1100)->run([PHP_BINARY, $script, '--platform', '--live', '--case='.$caseId, '--output='.$output]);
            unset($payload);
            if (! in_array($result->exitCode(), [0, 1, 3], true) || ! is_file($output)) {
                $this->terminate($run, 'failed', 'תהליך הבדיקה לא השלים דוח תקין. הוא לא נוסה שוב אוטומטית.');

                return;
            }
            $report = $this->read($this->casePath($id, $caseId), 8 * 1024 * 1024);
            $case = $this->caseResult($report, $caseId, $run);
            $run['completed']++;
            $run[$case['status']]++;
            $run['provider_requests'] += max(0, (int) ($case['provider_requests'] ?? 0));
            $run['input_tokens'] += max(0, (int) ($case['usage']['input_tokens'] ?? 0));
            $run['output_tokens'] += max(0, (int) ($case['usage']['output_tokens'] ?? 0));
            $run['next_index'] = $index + 1;
            $run['claimed_index'] = null;
            $run['current_case'] = null;
            $run['updated_at'] = now()->toIso8601String();
            if ($this->marker($id, 'failure')) {
                $this->terminate($run, 'failed', 'הבדיקה נקטעה ולא נוסתה שוב אוטומטית.');
            } elseif ($this->marker($id, 'cancel')) {
                $this->terminate($run, 'canceled', 'הבדיקה נעצרה לבקשת מנהל לאחר השלמת התרחיש הנוכחי.');
            } elseif ($run['completed'] >= $run['total']) {
                $this->terminate($run, 'completed', null);
            } else {
                $run['status'] = 'queued';
                $this->write($id.'/run.json', $run);
                $nextIndex = $index + 1;
            }
        } catch (Throwable) {
            if (isset($run)) {
                $this->terminate($run, 'failed', 'הבדיקה נקטעה. פרטי תשתית וסודות אינם נכללים בדוח; לא בוצע ניסיון אוטומטי נוסף.');
            }
        } finally {
            $lock->release();
        }
        // A different worker may start immediately after dispatch. Release the
        // run lock first so that worker cannot acknowledge the next job as busy.
        if ($nextIndex !== null) {
            try {
                $this->enqueue($id, $nextIndex);
            } catch (Throwable) {
                $this->terminate($run, 'failed', 'לא ניתן היה להכניס את התרחיש הבא לתור. לא בוצע ניסיון נוסף.');
            }
        }
    }

    /** A timed-out worker cannot silently leave the admin UI claiming progress. */
    public function failed(string $id, int $index): void
    {
        $run = $this->load($id);
        if (! in_array($run['status'], self::TERMINAL, true) && ($run['next_index'] === $index
                || ($run['status'] === 'queued' && $run['next_index'] === $index + 1 && $run['claimed_index'] === null))) {
            $this->write($id.'/failure.json', ['index' => $index, 'at' => now()->toIso8601String()]);
        }
    }

    private function configuration(): array
    {
        $ai = ['enabled' => (bool) config('billing.ai.enabled')];
        foreach (['provider', 'model', 'base_url', 'api_key', 'effort'] as $name) {
            $value = config('billing.ai.'.$name, '');
            if (! is_string($value) && $value !== null) {
                throw new RuntimeException('Invalid evaluation AI configuration.');
            }
            $ai[$name] = (string) $value;
        }
        $assistant = [];
        foreach (['persona', 'style', 'work_rules', 'instructions', 'history_messages', 'history_hours', 'history_chars',
            'max_turns', 'budget_seconds', 'tool_result_chars', 'disabled_permissions'] as $name) {
            $assistant[$name] = config('siteagent.assistant.'.$name);
        }

        return ['ai' => $ai, 'assistant' => $assistant];
    }

    protected function manifest(string $suite = 'original'): array
    {
        return $this->corpus->cases(suite: $suite);
    }

    protected function fingerprint(): string
    {
        return $this->corpus->fingerprint();
    }

    private function digest(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function enqueue(string $id, int $index): void
    {
        $connection = (string) config('queue.default', 'database');
        Bus::dispatch((new RunSiteAgentEvaluationJob($id, $index))->onConnection(
            in_array($connection, ['sync', 'null'], true) ? 'database' : $connection,
        ));
    }

    private function authorize(): void
    {
        abort_unless(Auth::user()?->role === UserRole::Admin, 403);
    }

    private function validateId(string $id): void
    {
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id)) {
            throw ValidationException::withMessages(['evaluation' => 'מזהה בדיקה אינו תקין.']);
        }
    }

    private function casePath(string $id, string $caseId): string
    {
        $this->validateId($id);
        if (! preg_match('/^(?:[a-z]+|round2-(?:shop|content|manage))-[0-9]{3}$/D', $caseId)) {
            throw new RuntimeException('Invalid evaluation case ID.');
        }

        return $id.'/cases/'.$caseId.'.json';
    }

    private function load(string $id): array
    {
        $this->validateId($id);
        $run = $this->read($id.'/run.json');
        if (($run['id'] ?? null) !== $id) {
            throw new RuntimeException('Invalid evaluation report.');
        }

        return $run;
    }

    private function publicSummary(array $run): array
    {
        if (! in_array($run['status'], self::TERMINAL, true)) {
            if ($this->marker($run['id'], 'failure') || ($run['status'] === 'running'
                    && now()->diffInSeconds(Carbon::parse($run['updated_at']), true) > 1500)) {
                $run['status'] = 'failed';
                $run['reason'] = 'הבדיקה נקטעה או שהתהליך הפסיק לדווח. לא בוצע ניסיון אוטומטי נוסף.';
            } elseif ($this->marker($run['id'], 'cancel')) {
                $run['status'] = $run['status'] === 'running' ? 'cancel_requested' : 'canceled';
                $run['reason'] = 'התבקשה עצירה; תרחיש שכבר התחיל רשאי להסתיים.';
            }
        }
        unset($run['configuration_digest'], $run['case_ids'], $run['deterministic_case_ids'], $run['admin_id'], $run['claimed_index']);

        return $run;
    }

    private function marker(string $id, string $name): bool
    {
        return Storage::disk('local')->exists(self::ROOT.'/'.$id.'/'.$name.'.json');
    }

    private function terminate(array $run, string $status, ?string $reason): void
    {
        $run['status'] = $status;
        $run['reason'] = $reason;
        $run['finished_at'] = $run['updated_at'] = now()->toIso8601String();
        $run['current_case'] = null;
        $this->write($run['id'].'/run.json', $run);
    }

    private function caseResult(array $report, string $caseId, array $run): array
    {
        $cases = $report['cases'] ?? null;
        $case = is_array($cases) && count($cases) === 1 ? ($cases[0] ?? null) : null;
        if (($report['schema_version'] ?? null) !== 1 || ($report['mode'] ?? null) !== 'live_model_simulated_site'
            || ($report['provider'] ?? null) !== $run['provider'] || ($report['model'] ?? null) !== $run['model']
            || ($report['corpus_sha256'] ?? null) !== $run['corpus_sha256']
            || ! is_array($case) || ($case['id'] ?? null) !== $caseId
            || ! in_array($case['status'] ?? null, ['passed', 'failed', 'blocked'], true)
            || ! is_bool($case['model_executed'] ?? null)) {
            throw new RuntimeException('Invalid evaluation case report.');
        }
        if ($case['status'] !== 'blocked' && $case['model_executed'] === false) {
            // This allowlist was pinned from the server-owned corpus at run
            // creation; later corpus releases must not reinterpret old runs.
            if (! in_array($caseId, $run['deterministic_case_ids'] ?? [], true)
                || ($case['execution'] ?? null) !== 'deterministic'
                || ($case['provider_requests'] ?? null) !== 0 || ($case['provider_responses'] ?? null) !== 0) {
                throw new RuntimeException('Invalid deterministic evaluation case report.');
            }
        }
        if ($case['status'] === 'passed' && $case['model_executed'] === true
            && (! is_int($case['provider_requests'] ?? null) || $case['provider_requests'] <= 0
                || ($case['provider_responses'] ?? null) !== $case['provider_requests']
                || (isset($case['execution']) && $case['execution'] !== 'model'))) {
            throw new RuntimeException('Passed evaluation case requires successful provider evidence.');
        }
        if (($report['summary'] ?? null) !== ['total' => 1, 'passed' => (int) ($case['status'] === 'passed'),
            'failed' => (int) ($case['status'] === 'failed'), 'blocked' => (int) ($case['status'] === 'blocked')]) {
            throw new RuntimeException('Inconsistent evaluation case summary.');
        }

        return $case;
    }

    private function read(string $relative, int $limit = 262144): array
    {
        $disk = Storage::disk('local');
        $path = self::ROOT.'/'.$relative;
        if (! $disk->exists($path)) {
            throw ValidationException::withMessages(['evaluation' => 'הבדיקה או הדוח אינם זמינים.']);
        }
        if ($disk->size($path) > $limit) {
            throw new RuntimeException('Evaluation report exceeds its size limit.');
        }
        $data = json_decode($disk->get($path), true, 128, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid evaluation report.');
        }

        return $data;
    }

    private function write(string $relative, array $data): void
    {
        $path = Storage::disk('local')->path(self::ROOT.'/'.$relative);
        $this->directory(dirname($path));
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
        try {
            if (file_put_contents($temporary, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new RuntimeException('Cannot write evaluation metadata.');
            }
            chmod($temporary, 0600);
            if (! rename($temporary, $path)) {
                throw new RuntimeException('Cannot save evaluation metadata.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create private evaluation directory.');
        }
        chmod($path, 0700);
    }
}
