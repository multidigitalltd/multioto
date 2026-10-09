<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\RunSiteAgentEvaluationJob;
use App\Models\User;
use App\Services\SiteAgent\Evaluation\EvaluationCorpus;
use App\Services\SiteAgent\Evaluation\EvaluationRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Parent/worker orchestration only. No model or shell process is executed. */
class SiteAgentEvaluationRunsTest extends TestCase
{
    use RefreshDatabase;

    private EvaluationRuns $runs;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        Bus::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Process::fake();
        Process::preventStrayProcesses();
        config(['billing.ai.enabled' => true, 'billing.ai.provider' => 'google',
            'billing.ai.model' => 'gemini-3.1-flash-lite', 'billing.ai.api_key' => 'private-test-api-key',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com', 'queue.default' => 'sync']);
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->admin);
        $this->runs = new class(app(EvaluationCorpus::class)) extends EvaluationRuns
        {
            protected function manifest(): array
            {
                return array_map(fn (int $index): array => ['id' => sprintf('case-%03d', $index)], range(1, 400));
            }

            protected function fingerprint(): string
            {
                return str_repeat('a', 64);
            }
        };
    }

    private function start(): string
    {
        return $this->runs->start($this->admin->id);
    }

    private function fakeCase(string $status = 'passed', ?callable $during = null, ?string $caseOverride = null): void
    {
        Process::fake(function (PendingProcess $process) use ($status, $during, $caseOverride) {
            $during?->__invoke($process);
            $caseId = $caseOverride ?? substr($process->command[4], strlen('--case='));
            $output = substr($process->command[5], strlen('--output='));
            file_put_contents($output, json_encode(['schema_version' => 1, 'mode' => 'live_model_simulated_site',
                'provider' => 'google', 'model' => 'gemini-3.1-flash-lite', 'corpus_sha256' => str_repeat('a', 64),
                'summary' => ['total' => 1, 'passed' => (int) ($status === 'passed'), 'failed' => (int) ($status === 'failed'), 'blocked' => (int) ($status === 'blocked')], 'cases' => [[
                    'id' => $caseId, 'status' => $status, 'model_executed' => $status !== 'blocked',
                    'provider_requests' => $status === 'blocked' ? 0 : 2,
                    'usage' => ['input_tokens' => 123, 'output_tokens' => 45],
                    'turns' => [], 'semantic_review' => 'not_performed',
                ]]]));

            return Process::result(output: 'do not store process stdout private-test-api-key',
                errorOutput: 'do not store stderr private-test-api-key', exitCode: match ($status) {
                    'passed' => 0, 'failed' => 1, default => 3,
                });
        });
    }

    public function test_start_only_queues_and_keeps_the_key_out_of_metadata_and_job_payload(): void
    {
        $id = $this->start();
        $summary = $this->runs->get($id);
        self::assertSame('queued', $summary['status']);
        self::assertSame(400, $summary['total']);
        self::assertSame(0, $summary['completed']);
        self::assertArrayNotHasKey('configuration_digest', $summary);
        self::assertSame($id, $this->runs->latest()['id']);
        Bus::assertDispatched(RunSiteAgentEvaluationJob::class, fn ($job): bool => $job->runId === $id
            && $job->index === 0 && $job->connection === 'database' && ! str_contains(serialize($job), 'private-test-api-key'));
        Process::assertNothingRan();
        Http::assertNothingSent();
        Mail::assertNothingSent();
        foreach (Storage::disk('local')->allFiles('site-agent-evaluations') as $file) {
            self::assertStringNotContainsString('private-test-api-key', Storage::disk('local')->get($file));
        }
    }

    public static function protectedActions(): array
    {
        return [['start'], ['latest'], ['get'], ['cancel'], ['report']];
    }

    #[DataProvider('protectedActions')]
    public function test_every_public_action_requires_a_current_admin(string $action): void
    {
        $id = $this->start();
        $this->actingAs(User::factory()->create(['role' => UserRole::Agent]));
        try {
            match ($action) {
                'start' => $this->runs->start($this->admin->id),
                'latest' => $this->runs->latest(),
                'get' => $this->runs->get($id),
                'cancel' => $this->runs->cancel($id),
                'report' => $this->runs->report($id),
            };
            self::fail('Non-admin access must be denied.');
        } catch (HttpException $error) {
            self::assertSame(403, $error->getStatusCode());
        }
        Process::assertNothingRan();
    }

    public function test_missing_ai_configuration_and_duplicate_starts_never_launch_work(): void
    {
        config(['billing.ai.api_key' => '']);
        try {
            $this->start();
            self::fail('Missing key must block startup.');
        } catch (ValidationException) {
            self::assertNull($this->runs->latest());
        }
        config(['billing.ai.api_key' => 'private-test-api-key']);
        $this->start();
        try {
            $this->start();
            self::fail('A second active run must be refused.');
        } catch (ValidationException) {
            Bus::assertDispatchedTimes(RunSiteAgentEvaluationJob::class, 1);
        }
        Process::assertNothingRan();
    }

    public function test_one_case_uses_fixed_argv_secret_stdin_and_cleared_inherited_environment_then_queues_next(): void
    {
        putenv('EVALUATION_TEST_OTHER_INTEGRATION=unrelated-secret');
        $_ENV['EVALUATION_TEST_ENV_ONLY_SECRET'] = 'env-only-secret';
        $_SERVER['EVALUATION_TEST_SERVER_ONLY_SECRET'] = 'server-only-secret';
        try {
            $id = $this->start();
            $this->fakeCase(during: function (PendingProcess $process): void {
                self::assertIsArray($process->command);
                self::assertSame([PHP_BINARY, base_path('scripts/site-agent-evaluate.php'), '--platform', '--live'], array_slice($process->command, 0, 4));
                self::assertSame('--case=case-001', $process->command[4]);
                self::assertStringNotContainsString('private-test-api-key', implode(' ', $process->command));
                $payload = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('private-test-api-key', $payload['ai']['api_key']);
                self::assertSame(['enabled', 'provider', 'model', 'base_url', 'api_key', 'effort'], array_keys($payload['ai']));
                self::assertSame(['ai', 'assistant'], array_keys($payload));
                self::assertFalse($process->environment['EVALUATION_TEST_OTHER_INTEGRATION']);
                self::assertFalse($process->environment['EVALUATION_TEST_ENV_ONLY_SECRET']);
                self::assertFalse($process->environment['EVALUATION_TEST_SERVER_ONLY_SECRET']);
                foreach (['PHPRC', 'PHP_INI_SCAN_DIR', 'LD_LIBRARY_PATH'] as $name) {
                    if (getenv($name) !== false && getenv($name) !== '') {
                        self::assertSame(getenv($name), $process->environment[$name]);
                    }
                }
                self::assertSame(1100, $process->timeout);
            });
            $this->runs->process($id, 0);
            $summary = $this->runs->get($id);
            self::assertSame('queued', $summary['status']);
            self::assertSame(1, $summary['completed']);
            self::assertSame(1, $summary['passed']);
            self::assertSame(2, $summary['provider_requests']);
            self::assertSame(123, $summary['input_tokens']);
            Bus::assertDispatched(RunSiteAgentEvaluationJob::class, fn ($job): bool => $job->runId === $id && $job->index === 1);
            $report = $this->runs->report($id);
            self::assertCount(1, $report['cases']);
            self::assertStringNotContainsString('private-test-api-key', json_encode($report));
            Http::assertNothingSent();
        } finally {
            putenv('EVALUATION_TEST_OTHER_INTEGRATION');
            unset($_ENV['EVALUATION_TEST_ENV_ONLY_SECRET'], $_SERVER['EVALUATION_TEST_SERVER_ONLY_SECRET']);
        }
    }

    public function test_completed_or_claimed_case_is_never_launched_again(): void
    {
        $id = $this->start();
        $this->fakeCase(during: function () use ($id): void {
            // A duplicate delivered while the first child runs cannot acquire
            // the lock or launch a second provider request.
            $this->runs->process($id, 0);
        });
        $this->runs->process($id, 0);
        $this->runs->process($id, 0);
        Process::assertRanTimes(fn (): bool => true, 1);
        self::assertSame(1, $this->runs->get($id)['completed']);
    }

    public function test_cancellation_before_execution_does_not_launch_a_child(): void
    {
        $id = $this->start();
        $this->runs->cancel($id);
        self::assertSame('canceled', $this->runs->get($id)['status']);
        $this->runs->process($id, 0);
        Process::assertNothingRan();
        self::assertSame(0, $this->runs->get($id)['completed']);
        Bus::assertDispatchedTimes(RunSiteAgentEvaluationJob::class, 1);
    }

    public function test_cancellation_during_execution_keeps_current_result_and_stops_the_chain(): void
    {
        $id = $this->start();
        $this->fakeCase(during: function () use ($id): void {
            $this->runs->cancel($id);
            self::assertSame('cancel_requested', $this->runs->get($id)['status']);
        });
        $this->runs->process($id, 0);
        self::assertSame('canceled', $this->runs->get($id)['status']);
        self::assertSame(1, $this->runs->get($id)['completed']);
        self::assertSame(1, $this->runs->get($id)['passed']);
        Bus::assertDispatchedTimes(RunSiteAgentEvaluationJob::class, 1);
    }

    public static function changedSettings(): array
    {
        return [['billing.ai.api_key', 'different-key'], ['billing.ai.model', 'different-model'], ['siteagent.assistant.instructions', 'different-rules']];
    }

    #[DataProvider('changedSettings')]
    public function test_changed_settings_stop_the_run_before_more_provider_billing(string $key, mixed $value): void
    {
        $id = $this->start();
        config([$key => $value]);
        $this->runs->process($id, 0);
        self::assertSame('failed', $this->runs->get($id)['status']);
        self::assertSame(0, $this->runs->get($id)['completed']);
        Process::assertNothingRan();
    }

    public function test_failed_and_blocked_cases_are_counted_separately_from_passes(): void
    {
        $id = $this->start();
        $this->fakeCase('failed');
        $this->runs->process($id, 0);
        $this->fakeCase('blocked');
        $this->runs->process($id, 1);
        $summary = $this->runs->get($id);
        self::assertSame(2, $summary['completed']);
        self::assertSame(0, $summary['passed']);
        self::assertSame(1, $summary['failed']);
        self::assertSame(1, $summary['blocked']);
    }

    public function test_transport_exception_is_terminal_and_never_exposes_input_or_retries(): void
    {
        $id = $this->start();
        $attempts = 0;
        Process::fake(function () use (&$attempts) {
            $attempts++;
            throw new RuntimeException('secret transport detail private-test-api-key');
        });
        $this->runs->process($id, 0);
        $this->runs->process($id, 0);
        self::assertSame(1, $attempts);
        $summary = $this->runs->get($id);
        self::assertSame('failed', $summary['status']);
        self::assertSame(0, $summary['completed']);
        self::assertStringNotContainsString('private-test-api-key', json_encode($summary));
        Bus::assertDispatchedTimes(RunSiteAgentEvaluationJob::class, 1);
    }

    public function test_mismatched_case_report_is_rejected_and_timeout_marker_is_visible(): void
    {
        $id = $this->start();
        $this->fakeCase(caseOverride: 'case-999');
        $this->runs->process($id, 0);
        self::assertSame('failed', $this->runs->get($id)['status']);
        self::assertSame(0, $this->runs->get($id)['completed']);
        $next = $this->start();
        $this->runs->failed($next, 0);
        self::assertSame('failed', $this->runs->get($next)['status']);
        $this->runs->process($next, 0);
        self::assertSame(0, $this->runs->get($next)['completed']);
    }

    public function test_invalid_identifiers_cannot_read_or_cancel_arbitrary_files(): void
    {
        Storage::disk('local')->put('secret.json', '{"private":"value"}');
        foreach (['../../secret', 'not-a-uuid', '00000000-0000-0000-0000-000000000000'] as $id) {
            try {
                $this->runs->report($id);
                self::fail('Invalid path was accepted.');
            } catch (ValidationException) {
                self::assertSame('{"private":"value"}', Storage::disk('local')->get('secret.json'));
            }
        }
        Process::assertNothingRan();
    }

    public function test_job_has_no_automatic_retry_or_secret_properties(): void
    {
        $job = new RunSiteAgentEvaluationJob('3bd51e72-43d1-413b-9126-c1666a0b96ae', 2);
        self::assertSame(1, $job->tries);
        self::assertSame(1200, $job->timeout);
        self::assertTrue($job->failOnTimeout);
        self::assertStringNotContainsString('private-test-api-key', serialize($job));
    }

    public function test_the_next_worker_can_start_immediately_after_dispatch_without_losing_its_job(): void
    {
        $id = $this->start();
        $this->fakeCase(during: function (PendingProcess $process) use ($id): void {
            if ($process->command[4] === '--case=case-002') {
                $this->runs->cancel($id);
            }
        });
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function (RunSiteAgentEvaluationJob $job): void {
            self::assertSame(1, $job->index);
            $job->handle($this->runs);
        });
        $this->runs->process($id, 0);
        self::assertSame(2, $this->runs->get($id)['completed']);
        self::assertSame('canceled', $this->runs->get($id)['status']);
        Process::assertRanTimes(fn (): bool => true, 2);
    }

    public function test_a_child_using_a_different_corpus_cannot_be_counted_in_the_pinned_run(): void
    {
        $id = $this->start();
        Process::fake(function (PendingProcess $process) {
            file_put_contents(substr($process->command[5], strlen('--output=')), json_encode([
                'schema_version' => 1, 'mode' => 'live_model_simulated_site', 'provider' => 'google',
                'model' => 'gemini-3.1-flash-lite', 'corpus_sha256' => str_repeat('b', 64),
                'summary' => ['total' => 1, 'passed' => 1, 'failed' => 0, 'blocked' => 0],
                'cases' => [['id' => 'case-001', 'status' => 'passed', 'model_executed' => true]],
            ]));

            return Process::result();
        });
        $this->runs->process($id, 0);
        self::assertSame('failed', $this->runs->get($id)['status']);
        self::assertSame(0, $this->runs->get($id)['completed']);
        self::assertSame([], $this->runs->report($id)['cases']);
    }

    public function test_queue_failure_returns_a_safe_error_and_a_reviewable_failed_run(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue credential private-test-api-key'));
        try {
            $this->start();
            self::fail('Queue failure must not announce a successfully started run.');
        } catch (ValidationException $error) {
            self::assertStringNotContainsString('private-test-api-key', json_encode($error->errors()));
            self::assertSame('failed', $this->runs->latest()['status']);
            self::assertSame(0, $this->runs->latest()['completed']);
        }
        Process::assertNothingRan();
    }

    public function test_the_last_case_finishes_the_run_without_dispatching_case_401(): void
    {
        $id = $this->start();
        $path = 'site-agent-evaluations/'.$id.'/run.json';
        $run = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        $run['next_index'] = $run['completed'] = $run['passed'] = 399;
        Storage::disk('local')->put($path, json_encode($run));
        $this->fakeCase();
        $this->runs->process($id, 399);
        $summary = $this->runs->get($id);
        self::assertSame('completed', $summary['status']);
        self::assertSame(400, $summary['completed']);
        self::assertSame(400, $summary['passed']);
        self::assertNotNull($summary['finished_at']);
        Bus::assertDispatchedTimes(RunSiteAgentEvaluationJob::class, 1);
    }
}
