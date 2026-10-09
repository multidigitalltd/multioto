<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SiteAgent\Evaluation\EvaluationRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/** Private report export: HTTP authorization, provenance and bounded memory. No AI runs. */
class SiteAgentEvaluationDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_admin_download_preserves_unicode_results_and_hides_private_metadata(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->seedReport(2);
        $response = $this->get($this->url($id))->assertOk()->assertStreamed()
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename=site-agent-evaluation-'.$id.'.json')
            ->assertHeader('X-Accel-Buffering', 'no');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $json = $response->streamedContent();
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame(['case-001', 'case-002'], array_column($report['cases'], 'id'));
        $this->assertSame('המחיר נבדק.', $report['cases'][0]['turns'][0]['reply']);
        $this->assertSame(2, $report['summary']['completed']);
        $this->assertArrayNotHasKey('configuration_digest', $report['summary']);
        $this->assertArrayNotHasKey('admin_id', $report['summary']);
        $this->assertStringNotContainsString('private-configuration-digest', $json);
        $this->assertStringNotContainsString('unaccepted-case', $json);
    }

    public function test_guest_and_non_admin_cannot_download_even_with_a_valid_report_id(): void
    {
        $id = $this->seedReport(1);
        $this->get($this->url($id))->assertRedirect();
        $this->actingAs(User::factory()->agent()->create());
        $this->get($this->url($id))->assertForbidden();
    }

    public function test_admin_must_complete_enabled_two_factor_before_exporting(): void
    {
        $id = $this->seedReport(1);
        $this->actingAs(User::factory()->withTwoFactor()->create());
        $this->get($this->url($id))->assertRedirect(route('two-factor.challenge'));
        $this->withSession(['two_factor.confirmed' => true])->get($this->url($id))->assertOk()->assertStreamed();
    }

    public function test_a_forged_case_is_rejected_during_preflight_before_returning_a_stream(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->seedReport(2);
        $path = 'site-agent-evaluations/'.$id.'/cases/case-002.json';
        $report = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        $report['corpus_sha256'] = str_repeat('b', 64);
        Storage::disk('local')->put($path, json_encode($report, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid evaluation case report.');
        app(EvaluationRuns::class)->streamReport($id);
    }

    public function test_missing_accepted_output_is_an_error_instead_of_an_incomplete_successful_download(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->seedReport(2);
        Storage::disk('local')->delete('site-agent-evaluations/'.$id.'/cases/case-002.json');

        $this->expectException(ValidationException::class);
        app(EvaluationRuns::class)->streamReport($id);
    }

    public function test_streaming_800_cases_larger_than_64_megabytes_keeps_memory_bounded(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->seedReport(800, 96 * 1024);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $stream = app(EvaluationRuns::class)->streamReport($id);
        $bytes = 0;
        $cases = 0;
        ob_start(function (string $chunk) use (&$bytes, &$cases): string {
            $bytes += strlen($chunk);
            $cases += substr_count($chunk, '"id":"case-');

            return '';
        }, 4096);
        try {
            $stream();
        } finally {
            ob_end_flush();
        }
        $this->assertSame(800, $cases);
        $this->assertGreaterThan(64 * 1024 * 1024, $bytes);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    private function url(string $id): string
    {
        return route('site-agent.evaluation.download', ['run' => $id]);
    }

    private function seedReport(int $count, int $paddingBytes = 0): string
    {
        $id = (string) Str::uuid();
        $ids = array_map(fn (int $n): string => sprintf('case-%03d', $n), range(1, $count));
        $run = [
            'id' => $id, 'status' => 'completed', 'admin_id' => 1, 'suite' => 'all',
            'provider' => 'google', 'model' => 'gemini-3.1-flash-lite',
            'total' => $count, 'completed' => $count, 'passed' => $count, 'failed' => 0, 'blocked' => 0,
            'case_ids' => [...$ids, 'unaccepted-case'], 'corpus_sha256' => str_repeat('a', 64),
            'configuration_digest' => 'private-configuration-digest', 'claimed_index' => null,
        ];
        Storage::disk('local')->put('site-agent-evaluations/'.$id.'/run.json', json_encode($run, JSON_THROW_ON_ERROR));
        foreach ($ids as $caseId) {
            $case = [
                'id' => $caseId, 'status' => 'passed', 'model_executed' => true,
                'provider_requests' => 1, 'provider_responses' => 1,
                'turns' => [['reply' => 'המחיר נבדק.']], 'padding' => str_repeat('x', $paddingBytes),
            ];
            $report = [
                'schema_version' => 1, 'mode' => 'live_model_simulated_site',
                'provider' => $run['provider'], 'model' => $run['model'], 'corpus_sha256' => $run['corpus_sha256'],
                'summary' => ['total' => 1, 'passed' => 1, 'failed' => 0, 'blocked' => 0], 'cases' => [$case],
            ];
            Storage::disk('local')->put('site-agent-evaluations/'.$id.'/cases/'.$caseId.'.json',
                json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return $id;
    }
}
