<?php

namespace Tests\Feature;

use App\Jobs\RunSiteAgentEvaluationJob;
use App\Models\User;
use App\Services\SiteAgent\Evaluation\EvaluationCorpus;
use App\Services\SiteAgent\Evaluation\EvaluationRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class SiteAgentEvaluationSuitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_independent_suites_cover_only_the_150_active_scenarios(): void
    {
        $corpus = app(EvaluationCorpus::class);
        $original = array_column($corpus->cases(suite: 'original'), 'id');
        $new = array_column($corpus->cases(suite: 'round2'), 'id');
        $this->assertCount(69, $original);
        $this->assertCount(81, $new);
        $this->assertSame([], array_intersect($original, $new));
        $this->assertCount(150, $corpus->cases());
        $this->assertSame(['original' => 69, 'round2' => 81, 'all' => 150], $corpus->suiteCounts());
        $this->assertSame(118, array_sum(array_map(fn (array $case): int => count($case['turns']), $corpus->cases(suite: 'original'))));
        $this->assertSame(170, array_sum(array_map(fn (array $case): int => count($case['turns']), $corpus->cases(suite: 'round2'))));
        $this->assertSame('round2-shop-011', $corpus->cases('round2-shop-011')[0]['id']);
        $this->expectException(InvalidArgumentException::class);
        $corpus->cases('commerce-066', 'round2');
    }

    public function test_the_initial_48_passed_cases_stay_retired_after_later_runs(): void
    {
        $corpus = app(EvaluationCorpus::class);
        $active = array_column($corpus->cases(), 'id');
        // Report 1a00a0e2-ad74-4616-b20c-e8534b38c4a1 completed 001–050;
        // 011 and 036 remained for that rerun and then passed the newer report.
        foreach (array_diff(range(1, 50), [11, 36]) as $number) {
            $id = sprintf('commerce-%03d', $number);
            $this->assertNotContains($id, $active);
        }
        $this->expectException(InvalidArgumentException::class);
        $corpus->cases('commerce-001');
    }

    public function test_only_the_602_recorded_passes_are_retired_and_all_other_150_cases_remain(): void
    {
        $provenance = json_decode(file_get_contents(base_path('tests/Fixtures/site-agent-retirement-report-4af73f59.json')), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('4af73f59-75b5-41a1-9074-986f8d3103f9', $provenance['source_report_id']);
        $this->assertSame('8672a75764f4533670fe2e22ed6a6a0ccb22db190634ee905e89ba0ba6f1a184', $provenance['source_report_sha256']);
        $this->assertSame(['total' => 752, 'completed' => 727, 'passed' => 602, 'failed' => 118, 'blocked' => 7], $provenance['recorded_summary']);
        $statuses = $provenance['case_statuses'];
        $this->assertCount(752, $statuses);
        foreach (['passed' => 602, 'failed' => 118, 'blocked' => 7, 'not_run' => 25] as $status => $count) {
            $this->assertCount($count, array_filter($statuses, fn (string $recorded): bool => $recorded === $status));
        }
        $expected = array_keys(array_filter($statuses, fn (string $status): bool => $status !== 'passed'));
        $this->assertSame($expected, array_column(app(EvaluationCorpus::class)->cases(), 'id'));
        // Earlier failures that later passed must also leave the paid corpus.
        $this->assertSame('passed', $statuses['commerce-011']);
        $this->assertSame('passed', $statuses['commerce-036']);
        // A historical oracle correction must not silently retire an original failure.
        $corrected = json_decode(file_get_contents(base_path('tests/Fixtures/site-agent-oracle-report-4af73f59.json')), true, 128, JSON_THROW_ON_ERROR);
        foreach ($corrected as $case) {
            $this->assertSame('failed', $statuses[$case['id']]);
            $this->assertContains($case['id'], $expected);
        }
    }

    public function test_new_suite_is_persisted_and_queued_without_any_inference(): void
    {
        Storage::fake('local');
        Cache::flush();
        Bus::fake();
        Http::preventStrayRequests();
        config(['billing.ai.enabled' => true, 'billing.ai.api_key' => 'test-key']);
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $runs = app(EvaluationRuns::class);
        $id = $runs->start($admin->id, 'round2');
        $run = json_decode(Storage::disk('local')->get('site-agent-evaluations/'.$id.'/run.json'), true);
        $this->assertSame('round2', $run['suite']);
        $this->assertCount(81, $run['case_ids']);
        $this->assertTrue(collect($run['case_ids'])->every(fn (string $case): bool => str_starts_with($case, 'round2-')));
        $this->assertSame(app(EvaluationCorpus::class)->fingerprint(), $run['corpus_sha256']);
        Bus::assertDispatched(RunSiteAgentEvaluationJob::class, fn ($job): bool => $job->runId === $id && $job->index === 0);
        Http::assertNothingSent();
    }

    public function test_unknown_suite_cannot_enqueue_a_run(): void
    {
        Storage::fake('local');
        Bus::fake();
        $admin = User::factory()->create();
        $this->actingAs($admin);
        try {
            app(EvaluationRuns::class)->start($admin->id, '../other');
            $this->fail('An unknown suite must be rejected.');
        } catch (ValidationException) {
            Bus::assertNothingDispatched();
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }
}
