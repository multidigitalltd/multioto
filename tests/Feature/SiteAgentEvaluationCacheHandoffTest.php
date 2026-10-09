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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process as ChildProcess;
use Tests\TestCase;

/** Cache bridge contracts only; no live provider is contacted. */
class SiteAgentEvaluationCacheHandoffTest extends TestCase
{
    use RefreshDatabase;

    private EvaluationRuns $runs;

    private User $admin;

    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        Bus::fake();
        Http::preventStrayRequests();
        Process::fake();
        Process::preventStrayProcesses();
        config([
            'billing.ai.enabled' => true, 'billing.ai.provider' => 'google',
            'billing.ai.model' => 'gemini-3.1-flash-lite', 'billing.ai.api_key' => 'private-cache-bridge-key',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com', 'queue.default' => 'sync',
        ]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->admin);
        $this->runs = new class(app(EvaluationCorpus::class)) extends EvaluationRuns
        {
            protected function manifest(string $suite = 'original'): array
            {
                return [['id' => 'case-001'], ['id' => 'case-002']];
            }

            protected function fingerprint(): string
            {
                return str_repeat('a', 64);
            }
        };
    }

    private function snapshot(): array
    {
        $prefix = 'site-agent:gemini-context:v1:';
        $entry = $prefix.'entry:'.str_repeat('b', 64);
        $status = $prefix.'status:'.str_repeat('c', 64);
        $expiry = time() + 3600;

        return ['schema' => 1, 'entries' => [
            $entry => ['value' => ['key' => $entry, 'name' => 'cachedContents/private-evaluation-handle',
                'expires_at' => $expiry, 'configuration' => $status], 'expires_at' => $expiry],
            $status => ['value' => ['state' => 'active', 'expires_at' => $expiry, 'reason' => null], 'expires_at' => $expiry],
        ]];
    }

    private function fakeChild(array $state, array $overrides = []): void
    {
        Process::fake(function (PendingProcess $process) use ($state, $overrides) {
            $input = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
            $this->inputs[] = $input;
            $caseId = substr($process->command[4], strlen('--case='));
            $output = substr($process->command[5], strlen('--output='));
            $case = array_replace([
                'id' => $caseId, 'status' => 'passed', 'model_executed' => true, 'execution' => 'model',
                'provider_requests' => 2, 'provider_responses' => 2, 'cache_reference_retries' => 0,
                'cache_management_requests' => 1, 'cache_management_responses' => 1,
                'cache_status' => ['state' => 'active', 'reason' => null],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 20, 'cached_input_tokens' => 100, 'cache_hit_requests' => 2],
                'turns' => [], 'semantic_review' => 'not_performed',
            ], $overrides);
            file_put_contents($output, json_encode([
                'schema_version' => 1, 'mode' => 'live_model_simulated_site',
                'provider' => $input['ai']['provider'], 'model' => $input['ai']['model'],
                'corpus_sha256' => str_repeat('a', 64), '_cache_state' => $state,
                'summary' => ['total' => 1, 'passed' => (int) ($case['status'] === 'passed'),
                    'failed' => (int) ($case['status'] === 'failed'), 'blocked' => (int) ($case['status'] === 'blocked')],
                'cases' => [$case],
            ], JSON_THROW_ON_ERROR));

            return Process::result(exitCode: match ($case['status']) {
                'passed' => 0, 'failed' => 1, default => 3,
            });
        });
    }

    private function completeRun(): string
    {
        $id = $this->runs->start($this->admin->id);
        $this->runs->process($id, 0);
        $this->runs->process($id, 1);
        $this->assertSame('completed', $this->runs->get($id)['status']);

        return $id;
    }

    public function test_private_cache_handoff_is_reused_between_cases_and_new_runs_but_never_exported(): void
    {
        $snapshot = $this->snapshot();
        $this->fakeChild($snapshot);
        $first = $this->completeRun();
        $this->assertSame(['schema' => 1, 'entries' => []], $this->inputs[0]['evaluation_cache']['state']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $this->inputs[0]['evaluation_cache']['salt']);
        $this->assertSame($snapshot, $this->inputs[1]['evaluation_cache']['state']);
        $this->assertSame($this->inputs[0]['evaluation_cache']['salt'], $this->inputs[1]['evaluation_cache']['salt']);

        $second = $this->completeRun();
        $this->assertSame($this->inputs[1]['evaluation_cache'], $this->inputs[2]['evaluation_cache']);
        $this->assertSame($this->inputs[2]['evaluation_cache'], $this->inputs[3]['evaluation_cache']);
        $this->assertNotSame($first, $second);
        $summary = $this->runs->get($second);
        $this->assertSame(200, $summary['cached_input_tokens']);
        $this->assertSame(40, $summary['uncached_input_tokens']);

        ob_start();
        ($this->runs->streamReport($second))();
        $streamed = ob_get_clean();
        $serialized = json_encode($this->runs->report($second)).json_encode($summary).$streamed;
        foreach (['_cache_state', 'cachedContents', 'private-cache-bridge-key', '"salt"', $this->inputs[0]['evaluation_cache']['salt']] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
        $cacheFiles = Storage::disk('local')->allFiles('site-agent-evaluations/provider-cache');
        $this->assertCount(1, $cacheFiles);
        $this->assertSame(0600, fileperms(Storage::disk('local')->path($cacheFiles[0])) & 0777);
        Process::assertRanTimes(fn () => true, 4);
        Http::assertNothingSent();
    }

    public static function changedConfiguration(): array
    {
        return [
            'credential' => ['billing.ai.api_key', 'another-private-key'],
            'model' => ['billing.ai.model', 'gemini-another-model'],
            'instructions' => ['siteagent.assistant.instructions', 'Changed stable instructions.'],
        ];
    }

    #[DataProvider('changedConfiguration')]
    public function test_new_configuration_has_separate_private_provider_cache(string $setting, string $value): void
    {
        $this->fakeChild($this->snapshot());
        $this->completeRun();
        config([$setting => $value]);
        $this->completeRun();
        $this->assertNotSame($this->inputs[0]['evaluation_cache']['salt'], $this->inputs[2]['evaluation_cache']['salt']);
        $this->assertSame(['schema' => 1, 'entries' => []], $this->inputs[2]['evaluation_cache']['state']);
        $this->assertCount(2, Storage::disk('local')->allFiles('site-agent-evaluations/provider-cache'));
    }

    public function test_cache_failure_stops_bulk_run_after_current_result_without_enqueuing_next_case(): void
    {
        $this->fakeChild(['schema' => 1, 'entries' => []], [
            'status' => 'blocked', 'model_executed' => false, 'execution' => 'not_executed',
            'provider_requests' => 0, 'provider_responses' => 0, 'cache_management_responses' => 0,
            'cache_status' => ['state' => 'fallback', 'reason' => 'model_unsupported'],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ]);
        $id = $this->runs->start($this->admin->id);
        $this->runs->process($id, 0);
        $this->runs->process($id, 1);
        $summary = $this->runs->get($id);
        $this->assertSame('failed', $summary['status']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(0, $summary['provider_requests']);
        $this->assertSame('fallback', $summary['cache_status']['state']);
        Process::assertRanTimes(fn () => true, 1);
        Bus::assertDispatchedTimes(RunSiteAgentEvaluationJob::class, 1);
    }

    public static function impossibleRetryEvidence(): array
    {
        return [
            'no actual model response' => [['provider_requests' => 1, 'provider_responses' => 0, 'cache_reference_retries' => 1]],
            'too many recovered failures' => [['provider_requests' => 100, 'provider_responses' => 1, 'cache_reference_retries' => 99]],
        ];
    }

    #[DataProvider('impossibleRetryEvidence')]
    public function test_cache_retry_counter_cannot_substitute_for_actual_successful_model_evidence(array $overrides): void
    {
        $this->fakeChild($this->snapshot(), $overrides);
        $id = $this->runs->start($this->admin->id);
        $this->runs->process($id, 0);
        $this->assertSame('failed', $this->runs->get($id)['status']);
        $this->assertSame(0, $this->runs->get($id)['passed']);
        $this->assertSame(0, $this->runs->get($id)['completed']);
    }

    public function test_provider_without_explicit_cache_cannot_claim_recovered_gemini_retries(): void
    {
        config(['billing.ai.provider' => 'openai']);
        $this->fakeChild($this->snapshot(), ['provider_requests' => 2, 'provider_responses' => 1, 'cache_reference_retries' => 1]);
        $id = $this->runs->start($this->admin->id);
        $this->runs->process($id, 0);
        $this->assertSame('failed', $this->runs->get($id)['status']);
        $this->assertSame(0, $this->runs->get($id)['passed']);
        $this->assertArrayNotHasKey('evaluation_cache', $this->inputs[0]);
    }

    public function test_malformed_child_cache_state_stops_instead_of_repeatedly_recreating_prefixes(): void
    {
        $snapshot = $this->snapshot();
        $key = array_key_first($snapshot['entries']);
        $snapshot['entries'][$key]['value']['prompt'] = 'must-not-be-persisted-as-cache-metadata';
        $this->fakeChild($snapshot);
        $id = $this->runs->start($this->admin->id);
        $this->runs->process($id, 0);
        $summary = $this->runs->get($id);
        $this->assertSame('failed', $summary['status']);
        $this->assertSame(0, $summary['completed']);
        $cacheFiles = Storage::disk('local')->allFiles('site-agent-evaluations/provider-cache');
        $this->assertCount(1, $cacheFiles);
        $this->assertStringNotContainsString('must-not-be-persisted', Storage::disk('local')->get($cacheFiles[0]));
        Bus::assertDispatchedTimes(RunSiteAgentEvaluationJob::class, 1);
    }

    public function test_launcher_round_trips_private_cache_metadata_without_inherited_storage_or_secrets(): void
    {
        $directory = sys_get_temp_dir().'/evaluation-cache-handoff-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $output = $directory.'/report.json';
        $sentinel = $directory.'/database.sqlite';
        file_put_contents($sentinel, 'Do not open this database.');
        $before = hash_file('sha256', $sentinel);
        $snapshot = $this->snapshot();
        $salt = str_repeat('d', 64);
        try {
            $process = new ChildProcess([
                PHP_BINARY, base_path('scripts/site-agent-evaluate.php'), '--platform', '--preflight',
                '--case='.app(EvaluationCorpus::class)->cases()[0]['id'], '--output='.$output,
            ], base_path(), [
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $sentinel, 'DB_URL' => 'sqlite:'.$sentinel,
                'CACHE_STORE' => 'redis', 'AI_API_KEY' => 'inherited-handoff-secret',
            ], json_encode([
                'ai' => ['enabled' => true, 'provider' => 'google', 'model' => 'gemini-3.1-flash-lite',
                    'api_key' => 'stdin-handoff-secret'], 'assistant' => [],
                'evaluation_cache' => ['salt' => $salt, 'state' => $snapshot],
            ], JSON_THROW_ON_ERROR), 60);
            $process->run();
            $this->assertSame(3, $process->getExitCode(), $process->getErrorOutput());
            $serialized = file_get_contents($output);
            $report = json_decode($serialized, true, 128, JSON_THROW_ON_ERROR);
            $this->assertSame(['total' => 1, 'passed' => 0, 'failed' => 0, 'blocked' => 1], $report['summary']);
            $this->assertSame(0, $report['provider_requests']);
            $this->assertSame($snapshot, $report['_cache_state']);
            $this->assertSame($before, hash_file('sha256', $sentinel));
            foreach (['inherited-handoff-secret', 'stdin-handoff-secret', $salt, $sentinel] as $private) {
                $this->assertStringNotContainsString($private, $serialized.$process->getOutput().$process->getErrorOutput());
            }
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
