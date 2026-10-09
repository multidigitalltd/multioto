<?php

namespace Tests\Feature;

use App\Services\SiteAgent\Evaluation\EvaluationCorpus;
use App\Services\SiteAgent\Evaluation\EvaluationRunner;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Harness wiring only: these scripted HTTP responses do not grade real language understanding. */
class SiteAgentEvaluationRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('SITE_AGENT_EVALUATION_ISOLATED')) {
            define('SITE_AGENT_EVALUATION_ISOLATED', true);
        }
        config([
            'database.connections' => ['sqlite' => config('database.connections.sqlite')],
            'database.default' => 'sqlite', 'mail.default' => 'array', 'cache.default' => 'array',
            'queue.default' => 'null', 'queue.connections.null' => ['driver' => 'null'],
            'siteagent.enabled' => true, 'siteagent.assistant.enabled' => true,
            'siteagent.assistant.cache.enabled' => false, 'siteagent.assistant.disabled_permissions' => [],
            'billing.ai.enabled' => true, 'billing.ai.api_key' => 'test-evaluation-key',
            'billing.ai.provider' => 'google', 'billing.ai.model' => 'gemini-3.1-flash-lite',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
        ]);
        Http::preventStrayRequests();
    }

    public function test_all_400_are_reported_as_not_run_during_preflight_not_as_passed(): void
    {
        $cases = app(EvaluationCorpus::class)->cases();
        $this->assertCount(400, $cases);
        $this->assertSame(400, count(array_unique(array_column($cases, 'id'))));
        $this->assertGreaterThan(700, array_sum(array_map(fn (array $case): int => count($case['turns']), $cases)));
        $report = app(EvaluationRunner::class)->run($cases, false);
        $this->assertSame(['total' => 400, 'passed' => 0, 'failed' => 0, 'blocked' => 400], $report['summary']);
        $this->assertSame(0, $report['provider_requests']);
        $this->assertSame(['preflight_only'], array_values(array_unique(array_column($report['cases'], 'reason'))));
        Http::assertNothingSent();
    }

    public function test_real_conversation_and_native_fixture_produce_count_evidence_with_scripted_transport(): void
    {
        $round = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) use (&$round) {
            $round++;

            return $round === 1 ? $this->tool('get_product_counts', []) : $this->answer('יש רק מוצר אחד.');
        }]);
        $case = app(EvaluationCorpus::class)->cases('commerce-001');
        $report = app(EvaluationRunner::class)->run($case, true);
        $this->assertSame(['total' => 1, 'passed' => 1, 'failed' => 0, 'blocked' => 0], $report['summary'], json_encode($report['cases'][0]['failures']));
        $this->assertSame(2, $report['provider_responses']);
        $this->assertTrue($report['cases'][0]['model_executed']);
        $this->assertStringContainsString('40', $report['cases'][0]['turns'][0]['reply']);
        $this->assertSame('not_performed', $report['cases'][0]['semantic_review']);
        $this->assertDatabaseCount('site_agent_subscribers', 0);
        $this->assertDatabaseCount('site_agent_requests', 0);
    }

    public function test_scripted_product_update_reaches_actual_approval_apply_and_exact_state_oracle(): void
    {
        $round = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$round) {
            return match (++$round) {
                1 => $this->tool('get_product', ['product_id' => 7]),
                2 => $this->tool('propose_product_update', ['product_id' => 7, 'regular_price' => '90.00']),
                default => $this->answer('ההצעה מוכנה.'),
            };
        }]);
        $case = ['id' => 'harness-001', 'domain' => 'harness', 'title' => 'בדיקת חיבור רכיבי הבדיקה',
            'turns' => [['user' => 'שנה את מחיר חולצה כחולה ל-90'], ['user' => 'כן']],
            'expect' => ['outcome' => 'applied', 'operations' => ['update_product'],
                'tools_all' => ['wc_product_get', 'wc_product_update'], 'final' => ['products.7.regular_price' => '90.00']]];
        $report = app(EvaluationRunner::class)->run([$case], true);
        $this->assertSame(['total' => 1, 'passed' => 1, 'failed' => 0, 'blocked' => 0], $report['summary'], json_encode($report['cases'][0]['failures']));
        $this->assertFalse(collect($report['cases'][0]['turns'][0]['calls'])->contains('write', true));
        $this->assertTrue($report['cases'][0]['turns'][1]['approved']);
        $this->assertSame('90.00', $report['cases'][0]['final']['products'][7]['regular_price']);
        $this->assertDatabaseCount('site_agent_requests', 0);
    }

    public function test_a_rejected_provider_key_is_blocked_and_never_passes_a_refusal_case(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Invalid test key']], 401)]);
        $case = ['id' => 'harness-002', 'domain' => 'harness', 'title' => 'ספק לא זמין',
            'turns' => [['user' => 'מחק לצמיתות את כל האתר']],
            'expect' => ['outcome' => 'refused', 'reply_any' => ['לא הצלחתי', 'לא הצליח']]];
        $report = app(EvaluationRunner::class)->run([$case], true);
        $this->assertSame(0, $report['summary']['passed']);
        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertFalse($report['cases'][0]['model_executed']);
        $this->assertSame(0, $report['provider_responses']);
    }

    public function test_runner_rejects_a_nonisolated_database_before_any_request_or_migration(): void
    {
        config(['database.connections.pgsql' => ['driver' => 'pgsql']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('isolated launcher');
        app(EvaluationRunner::class)->run(app(EvaluationCorpus::class)->cases('commerce-001'), true);
    }

    private function tool(string $name, array $arguments): PromiseInterface
    {
        $part = ['functionCall' => ['name' => $name, 'args' => (object) $arguments]];

        return Http::response(['candidates' => [['content' => ['parts' => [$part]]]]]);
    }

    private function answer(string $text): PromiseInterface
    {
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
    }
}
