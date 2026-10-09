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

    public function test_two_independent_suites_cover_only_the_752_active_scenarios(): void
    {
        $corpus = app(EvaluationCorpus::class);
        $original = array_column($corpus->cases(suite: 'original'), 'id');
        $new = array_column($corpus->cases(suite: 'round2'), 'id');
        $this->assertCount(352, $original);
        $this->assertCount(400, $new);
        $this->assertSame([], array_intersect($original, $new));
        $this->assertCount(752, $corpus->cases());
        $this->assertSame(['original' => 352, 'round2' => 400, 'all' => 752], $corpus->suiteCounts());
        $this->assertSame('round2-shop-001', $corpus->cases('round2-shop-001')[0]['id']);
        $this->expectException(InvalidArgumentException::class);
        $corpus->cases('commerce-011', 'round2');
    }

    public function test_only_the_48_passed_cases_from_the_partial_report_are_retired(): void
    {
        $corpus = app(EvaluationCorpus::class);
        $active = array_column($corpus->cases(), 'id');
        // Report 1a00a0e2-ad74-4616-b20c-e8534b38c4a1 completed 001–050;
        // 011 and 036 failed its old oracle and must remain available for rerun.
        foreach (range(1, 134) as $number) {
            $id = sprintf('commerce-%03d', $number);
            $this->assertSame($number > 50 || in_array($number, [11, 36], true), in_array($id, $active, true), $id);
        }
        $this->expectException(InvalidArgumentException::class);
        $corpus->cases('commerce-001');
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
        $this->assertCount(400, $run['case_ids']);
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
