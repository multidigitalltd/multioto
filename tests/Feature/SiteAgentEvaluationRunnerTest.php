<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Services\Agent\McpClient;
use App\Services\Ai\GeminiContextCache;
use App\Services\SiteAgent\Evaluation\EvaluationCorpus;
use App\Services\SiteAgent\Evaluation\EvaluationGeminiContextCache;
use App\Services\SiteAgent\Evaluation\EvaluationRunner;
use App\Services\SiteAgent\Evaluation\EvaluationSiteActionProposer;
use App\Services\SiteAgent\Evaluation\EvaluationSiteAgentProposalFidelity;
use App\Services\SiteAgent\SiteActionProposer;
use App\Services\SiteAgent\SiteAgentConversation;
use App\Services\SiteAgent\SiteAgentProposalFidelity;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\FakesSiteAgentProposalFidelity;
use Tests\TestCase;

/** Harness wiring only: these scripted HTTP responses do not grade real language understanding. */
class SiteAgentEvaluationRunnerTest extends TestCase
{
    use FakesSiteAgentProposalFidelity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProposalFidelity();
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

    public function test_all_current_cases_are_reported_as_not_run_during_preflight_not_as_passed(): void
    {
        $cases = app(EvaluationCorpus::class)->cases();
        $this->assertCount(150, $cases);
        $this->assertSame(150, count(array_unique(array_column($cases, 'id'))));
        $this->assertSame(288, array_sum(array_map(fn (array $case): int => count($case['turns']), $cases)));
        $report = app(EvaluationRunner::class)->run($cases, false);
        $this->assertSame(['total' => 150, 'passed' => 0, 'failed' => 0, 'blocked' => 150], $report['summary']);
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
        $case = $this->countCase();
        $report = app(EvaluationRunner::class)->run($case, true);
        $this->assertSame(['total' => 1, 'passed' => 1, 'failed' => 0, 'blocked' => 0], $report['summary'], json_encode($report['cases'][0]['failures']));
        $this->assertSame(1, $report['provider_responses']);
        $this->assertTrue($report['cases'][0]['model_executed']);
        $this->assertStringContainsString('40', $report['cases'][0]['turns'][0]['reply']);
        $this->assertSame('not_performed', $report['cases'][0]['semantic_review']);
        $this->assertDatabaseCount('site_agent_subscribers', 0);
        $this->assertDatabaseCount('site_agent_requests', 0);
    }

    public function test_scripted_product_update_reaches_actual_approval_apply_and_exact_state_oracle(): void
    {
        $originalFidelity = app(SiteAgentProposalFidelity::class);
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
        $this->assertSame([['ordinal' => 1, 'operation' => 'update_product', 'verdict' => 'allow', 'reason' => 'matched']],
            $report['cases'][0]['turns'][0]['proposal_fidelity_diagnostics']);
        $this->assertSame([], $report['cases'][0]['turns'][1]['proposal_fidelity_diagnostics']);
        $this->assertSame($originalFidelity, app(SiteAgentProposalFidelity::class));
        $this->assertDatabaseCount('site_agent_requests', 0);
    }

    public function test_real_fidelity_review_uses_a_short_uncached_request_and_all_usage_is_accounted_for(): void
    {
        app()->forgetInstance(SiteAgentProposalFidelity::class);
        config(['siteagent.assistant.cache.enabled' => true]);
        app()->instance(GeminiContextCache::class, new EvaluationGeminiContextCache([], 'fidelity-run-stable-salt'));
        $mainCalls = 0;
        $reviews = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) use (&$mainCalls, &$reviews) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                return $this->cacheResponse();
            }
            if (($request['generationConfig']['responseMimeType'] ?? null) === 'application/json') {
                $reviews++;
                $this->assertArrayNotHasKey('cachedContent', $request->data());
                $this->assertArrayNotHasKey('tools', $request->data());
                $this->assertStringNotContainsString('functionDeclarations', json_encode($request->data()));
                $this->assertLessThan(6000, mb_strlen($request['systemInstruction']['parts'][0]['text']));

                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'verdict' => 'allow', 'reason' => 'matched', 'feedback' => '',
                ])]]]]], 'usageMetadata' => ['promptTokenCount' => 300, 'totalTokenCount' => 330]]);
            }
            $this->assertSame('cachedContents/evaluation-reference', $request['cachedContent']);
            $tool = ++$mainCalls === 1
                ? ['name' => 'get_product', 'args' => ['product_id' => 7]]
                : ['name' => 'propose_product_update', 'args' => ['product_id' => 7, 'regular_price' => '90.00']];

            return Http::response(['candidates' => [['content' => ['parts' => [['functionCall' => $tool]]]]],
                'usageMetadata' => ['promptTokenCount' => 1000, 'cachedContentTokenCount' => 800, 'totalTokenCount' => 1020]]);
        }]);
        $case = ['id' => 'harness-fidelity-review', 'domain' => 'harness', 'title' => 'בדיקת התאמה אמיתית מול תעבורה מדומה',
            'turns' => [['user' => 'שנה את מחיר חולצה כחולה ל-90'], ['user' => 'כן']],
            'expect' => ['outcome' => 'applied', 'operations' => ['update_product'],
                'tools_all' => ['wc_product_get', 'wc_product_update'], 'final' => ['products.7.regular_price' => '90.00']]];
        $report = app(EvaluationRunner::class)->run([$case], true);

        $this->assertSame(1, $report['summary']['passed'], json_encode($report['cases'][0]['failures']));
        $this->assertSame(2, $mainCalls);
        $this->assertSame(1, $reviews);
        $this->assertSame(3, $report['provider_requests']);
        $this->assertSame(3, $report['provider_responses']);
        $this->assertSame(1, $report['cache_management_requests']);
        $this->assertSame(['input_tokens' => 2300, 'cached_input_tokens' => 1600, 'uncached_input_tokens' => 700,
            'output_tokens' => 70, 'cache_hit_requests' => 2], $report['cases'][0]['usage']);
        $this->assertSame([['ordinal' => 1, 'operation' => 'update_product', 'verdict' => 'allow', 'reason' => 'matched']],
            $report['cases'][0]['turns'][0]['proposal_fidelity_diagnostics']);
        $this->assertNotInstanceOf(EvaluationSiteAgentProposalFidelity::class, app(SiteAgentProposalFidelity::class));
        $this->assertFalse(app()->isShared(SiteAgentProposalFidelity::class));
    }

    public function test_fidelity_observer_restores_a_custom_binding_even_when_the_scenario_throws(): void
    {
        $original = app(SiteAgentProposalFidelity::class);
        $created = 0;
        app()->bind(SiteAgentProposalFidelity::class, function () use ($original, &$created) {
            $created++;

            return $original;
        });
        Http::fake(['generativelanguage.googleapis.com/*' => $this->tool('get_product_counts', [])]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true, function (array $progress): void {
            if ($progress['event'] === 'turn_complete') {
                throw new \RuntimeException('private exception content');
            }
        });

        $this->assertContains('runner_exception:RuntimeException', $report['cases'][0]['failures']);
        $this->assertSame(1, $created);
        $this->assertSame($original, app(SiteAgentProposalFidelity::class));
        $this->assertSame(2, $created);
        $this->assertFalse(app()->isShared(SiteAgentProposalFidelity::class));
        $this->assertStringNotContainsString('private exception content', json_encode($report));
    }

    public function test_fidelity_diagnostics_are_bounded_and_do_not_store_review_or_owner_text(): void
    {
        $subscriber = new SiteAgentSubscriber;
        $result = ['verdict' => 'revise', 'reason' => 'wrong_value', 'feedback' => 'private review feedback', 'reply' => 'private reply'];
        $delegate = \Mockery::mock(SiteAgentProposalFidelity::class);
        $delegate->shouldReceive('review')->times(55)->with($subscriber, 'private owner message', 'update_product',
            'private preview /secret/path token', ['private prior owner message'])->andReturn($result);
        $observer = new EvaluationSiteAgentProposalFidelity($delegate);
        for ($index = 0; $index < 55; $index++) {
            $returned = $observer->review($subscriber, 'private owner message', 'update_product',
                'private preview /secret/path token', ['private prior owner message']);
        }

        $this->assertSame($result, $returned);
        $this->assertCount(50, $observer->diagnostics());
        $this->assertSame(['ordinal' => 50, 'operation' => 'update_product', 'verdict' => 'revise', 'reason' => 'wrong_value'],
            $observer->diagnostics()[49]);
        $this->assertStringNotContainsString('private', json_encode($observer->diagnostics()));
        $this->assertStringNotContainsString('secret', json_encode($observer->diagnostics()));
        Http::assertNothingSent();
    }

    #[TestWith(['איזה שדה לעדכן?', 'matched', 'passed'])]
    #[TestWith(['השדה הזה מיוחד.', 'inconclusive', 'failed'])]
    public function test_reply_evidence_is_exported_without_replacing_the_case_result(string $reply, string $evidenceStatus, string $caseStatus): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => $this->answer($reply)]);
        $case = ['id' => 'harness-reply-evidence', 'domain' => 'harness', 'title' => 'הבהרה ממוקדת',
            'turns' => [['user' => 'עדכן את השדה הזה']],
            'expect' => ['outcome' => 'clarification', 'reply_contract' => ['topic_groups' => [['שדה']]]]];
        $report = app(EvaluationRunner::class)->run([$case], true);

        $this->assertSame($caseStatus, $report['cases'][0]['status']);
        $this->assertSame($evidenceStatus, $report['cases'][0]['reply_evidence']['status']);
        $this->assertSame(1, $report['cases'][0]['reply_evidence']['turns'][0]['turn']);
        $this->assertSame('not_performed', $report['cases'][0]['semantic_review']);
    }

    public function test_proposal_diagnostics_capture_validation_then_read_and_retry_without_changing_execution(): void
    {
        $round = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$round) {
            return match (++$round) {
                1, 3 => $this->tool('propose_product_update', ['product_id' => 7, 'regular_price' => '90.00']),
                2 => $this->tool('get_product', ['product_id' => 7]),
                default => $this->answer('Unused response'),
            };
        }]);
        $case = ['id' => 'harness-proposal-diagnostics', 'domain' => 'harness', 'title' => 'תיקון הצעה לאחר קריאת היעד',
            'turns' => [['user' => 'שנה את מחיר חולצה כחולה ל-90'], ['user' => 'כן']],
            'expect' => ['outcome' => 'applied', 'operations' => ['update_product'],
                'tools_all' => ['wc_product_get', 'wc_product_update'], 'final' => ['products.7.regular_price' => '90.00']]];
        $report = app(EvaluationRunner::class)->run([$case], true);
        $result = $report['cases'][0];

        $this->assertSame('passed', $result['status'], json_encode($result['failures']));
        $this->assertSame(['rejected', 'prepared'], array_column($result['turns'][0]['proposal_diagnostics'], 'result_kind'));
        $diagnostic = $result['turns'][0]['proposal_diagnostics'][0];
        $this->assertSame('propose_product_update', $diagnostic['tool']);
        $this->assertSame('target_not_read', $diagnostic['rejection_code']);
        $this->assertSame(['product_id', 'regular_price'], $diagnostic['input_keys']);
        $this->assertArrayNotHasKey('arguments', $diagnostic);
        $this->assertArrayNotHasKey('plan', $diagnostic);
        $this->assertStringNotContainsString('90.00', json_encode($diagnostic));
        $this->assertSame([], $result['turns'][1]['proposal_diagnostics']);
        $this->assertFalse(collect($result['turns'][0]['calls'])->contains('write', true));
        $this->assertTrue($result['turns'][1]['approved']);
        $this->assertSame('90.00', $result['final']['products'][7]['regular_price']);
        $this->assertSame(3, $report['provider_requests']);
    }

    public function test_proposal_diagnostics_are_bounded_and_never_include_values_or_credential_shaped_keys(): void
    {
        config(['billing.ai.api_key' => 'private_credential']);
        $proposer = app(EvaluationSiteActionProposer::class);
        $site = new Site(['domain' => 'evaluation.example', 'mcp_secret' => 'synthetic_secret']);
        for ($attempt = 0; $attempt < 55; $attempt++) {
            $result = $proposer->propose($site, 'propose_product_update', [
                'product_id' => 7, 'regular_price' => 'private raw value', 'private_credential' => 'hidden',
                'values' => ['synthetic_secret' => 'hidden nested value', 'stock_quantity' => 9],
            ], []);
        }
        $this->assertArrayHasKey('error', $result);
        $diagnostics = $proposer->diagnostics();
        $this->assertCount(50, $diagnostics);
        $this->assertContains('redacted_field', $diagnostics[0]['input_keys']);
        $this->assertSame(['redacted_field', 'stock_quantity'], $diagnostics[0]['value_keys']);
        foreach (['private_credential', 'synthetic_secret', 'private raw value', 'hidden nested value'] as $value) {
            $this->assertStringNotContainsString($value, json_encode($diagnostics));
        }
        $this->assertSame([], app(EvaluationSiteActionProposer::class)->diagnostics());
        $this->assertNotInstanceOf(EvaluationSiteActionProposer::class, app(SiteActionProposer::class));
    }

    public function test_proposal_read_exceptions_are_diagnosed_by_fixed_code_without_exporting_exception_text(): void
    {
        $mcp = \Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')->once()->andThrow(new \RuntimeException('private stack trace credential https://secret.example'));
        app()->instance(McpClient::class, $mcp);
        $proposer = app(EvaluationSiteActionProposer::class);
        $site = new Site(['domain' => 'evaluation.example', 'mcp_secret' => 'synthetic_secret']);
        $result = $proposer->propose($site, 'propose_product_update', ['product_id' => 7, 'regular_price' => '90.00'], [7]);

        $this->assertStringContainsString('private stack trace', $result['error']);
        $this->assertSame('read_failed', $proposer->diagnostics()[0]['rejection_code']);
        $this->assertSame('לא ניתן היה לקרוא ולאמת את נתוני האתר.', $proposer->diagnostics()[0]['validation_message']);
        $this->assertStringNotContainsString('private', json_encode($proposer->diagnostics()));
        $this->assertStringNotContainsString('secret.example', json_encode($proposer->diagnostics()));
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
        $this->assertSame([['http_status' => 401, 'reason' => 'http_error', 'finish_reason' => null, 'block_reason' => null]],
            $report['cases'][0]['provider_diagnostics']);
        $this->assertStringNotContainsString('Invalid test key', json_encode($report));
    }

    public function test_a_connection_timeout_is_diagnosed_without_raw_messages_or_an_automatic_retry(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => function () {
            $reason = new ConnectException('sensitive-provider-key timeout detail',
                new \GuzzleHttp\Psr7\Request('POST', 'https://generativelanguage.googleapis.com/private-sensitive-path'),
                null, ['errno' => 28]);

            return Create::rejectionFor($reason);
        }]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true);

        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertSame(1, $report['provider_requests']);
        $this->assertSame(0, $report['provider_responses']);
        $this->assertSame([['http_status' => null, 'reason' => 'transport_error', 'error_kind' => 'timeout',
            'request_kind' => 'tool_use', 'finish_reason' => null, 'block_reason' => null]], $report['cases'][0]['provider_diagnostics']);
        $this->assertStringNotContainsString('sensitive', json_encode($report));
        $this->assertFalse($report['cases'][0]['model_executed']);
    }

    public function test_structured_request_connection_failures_remain_distinct_from_cache_management_failures(): void
    {
        config(['siteagent.assistant.cache.enabled' => true]);
        $runner = app(EvaluationRunner::class);
        (new \ReflectionProperty($runner, 'caseStarted'))->setValue($runner, microtime(true));
        (new \ReflectionMethod($runner, 'restrictNetwork'))->invoke($runner);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection('sensitive private URL')]);
        foreach (['/v1beta/cachedContents', '/v1beta/models/gemini-3.1-flash-lite:generateContent'] as $path) {
            try {
                Http::post('https://generativelanguage.googleapis.com'.$path, [
                    'generationConfig' => ['responseMimeType' => 'application/json'],
                    'contents' => [['parts' => [['text' => 'private prompt']]]],
                ]);
                $this->fail('The failed connection must remain rejected.');
            } catch (ConnectionException) {
                // No successful response exists for either request.
            }
        }
        $provider = (new \ReflectionProperty($runner, 'providerDiagnostics'))->getValue($runner);
        $cache = (new \ReflectionProperty($runner, 'cacheDiagnostics'))->getValue($runner);
        $this->assertSame('structured_output', $provider[0]['request_kind']);
        $this->assertSame('transport_error', $provider[0]['reason']);
        $this->assertSame([['operation' => 'create', 'http_status' => null,
            'reason' => 'transport_error', 'error_kind' => 'transport_error']], $cache);
        $this->assertSame(1, (new \ReflectionProperty($runner, 'providerRequests'))->getValue($runner));
        $this->assertSame(0, (new \ReflectionProperty($runner, 'providerResponses'))->getValue($runner));
        $this->assertSame(1, (new \ReflectionProperty($runner, 'cacheManagementRequests'))->getValue($runner));
        $this->assertStringNotContainsString('private', json_encode([$provider, $cache]));
    }

    public function test_an_empty_stop_response_is_not_invented_model_evidence(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model']]],
        ])]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true);

        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertSame(0, $report['provider_responses']);
        $this->assertFalse($report['cases'][0]['model_executed']);
        $this->assertSame('missing_model_content', $report['cases'][0]['provider_diagnostics'][0]['reason']);
        $this->assertSame('STOP', $report['cases'][0]['provider_diagnostics'][0]['finish_reason']);
    }

    #[TestWith([[['text' => 'Private reasoning only', 'thought' => true]]])]
    #[TestWith([[[]]])]
    #[TestWith([[['text' => '   ']]])]
    #[TestWith([[['functionCall' => ['args' => []]]]])]
    #[TestWith([[['functionCall' => ['name' => 'get_product_counts', 'args' => ['not', 'an', 'object']]]]])]
    public function test_thought_only_or_malformed_parts_do_not_count_as_visible_model_evidence(array $parts): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => $parts]]],
        ])]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true);

        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertSame(0, $report['provider_responses']);
        $this->assertFalse($report['cases'][0]['model_executed']);
        $this->assertSame('missing_model_content', $report['cases'][0]['provider_diagnostics'][0]['reason']);
    }

    public function test_a_native_no_argument_function_call_may_omit_the_optional_args_object(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'get_product_counts']]]]]],
        ])]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true);

        $this->assertSame(1, $report['summary']['passed'], json_encode($report['cases'][0]['failures']));
        $this->assertSame(1, $report['provider_requests']);
        $this->assertSame(1, $report['provider_responses']);
    }

    public function test_runner_rejects_a_nonisolated_database_before_any_request_or_migration(): void
    {
        config(['database.connections.pgsql' => ['driver' => 'pgsql']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('isolated launcher');
        app(EvaluationRunner::class)->run($this->countCase(), true);
    }

    public function test_a_curated_lone_confirmation_passes_as_a_deterministic_guard_without_claiming_model_execution(): void
    {
        $case = ['id' => 'harness-003', 'domain' => 'harness', 'title' => 'אישור ללא הצעה',
            'turns' => [['user' => 'כן']], 'expect' => ['outcome' => 'clarification',
                'model_required' => false, 'reply_contains' => [SiteAgentConversation::NO_PENDING_PROPOSAL]]];
        $report = app(EvaluationRunner::class)->run([$case], true);

        $this->assertSame(['total' => 1, 'passed' => 1, 'failed' => 0, 'blocked' => 0], $report['summary']);
        $this->assertSame('deterministic', $report['cases'][0]['execution']);
        $this->assertFalse($report['cases'][0]['model_executed']);
        $this->assertSame(0, $report['cases'][0]['provider_requests']);
        $this->assertSame(0, $report['cases'][0]['provider_responses']);
        $this->assertSame(['model' => 0, 'deterministic' => 1, 'not_executed' => 0], $report['execution_counts']);
        Http::assertNothingSent();
    }

    public function test_zero_provider_traffic_still_blocks_a_case_that_requires_model_execution(): void
    {
        $case = ['id' => 'harness-004', 'domain' => 'harness', 'title' => 'מודל נדרש',
            'turns' => [['user' => 'כן']], 'expect' => ['outcome' => 'clarification',
                'reply_contains' => [SiteAgentConversation::NO_PENDING_PROPOSAL]]];
        $report = app(EvaluationRunner::class)->run([$case], true);

        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertSame('not_executed', $report['cases'][0]['execution']);
        $this->assertContains('no_successful_model_response', $report['cases'][0]['failures']);
        Http::assertNothingSent();
    }

    public function test_a_failed_provider_attempt_cannot_pass_even_when_the_corpus_allows_a_deterministic_guard(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Invalid test key']], 401)]);
        $case = ['id' => 'harness-005', 'domain' => 'harness', 'title' => 'כשל ספק אינו הצלחה',
            'turns' => [['user' => 'מחק לצמיתות את כל האתר']], 'expect' => ['outcome' => 'refused',
                'model_required' => false, 'reply_any' => ['לא הצלחתי', 'לא הצליח']]];
        $report = app(EvaluationRunner::class)->run([$case], true);

        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertSame(0, $report['summary']['passed']);
        $this->assertSame('not_executed', $report['cases'][0]['execution']);
        $this->assertGreaterThan(0, $report['cases'][0]['provider_requests']);
        $this->assertContains('provider_transport_or_response_failure', $report['cases'][0]['failures']);
    }

    public function test_corpus_model_requirement_rejects_truthy_strings_instead_of_disabling_model_evidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('model-required assertion must be boolean');
        app(EvaluationCorpus::class)->validate(['id' => 'harness-006', 'domain' => 'harness', 'title' => 'Invalid assertion',
            'turns' => [['user' => 'כן']], 'expect' => ['outcome' => 'clarification',
                'model_required' => 'false', 'reply_contains' => ['אין כרגע הצעה']]]);
    }

    public function test_corpus_plan_alternatives_require_an_explicit_nonempty_value_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid proposal alternatives');
        app(EvaluationCorpus::class)->validate(['id' => 'harness-008', 'domain' => 'harness', 'title' => 'Invalid alternatives',
            'turns' => [['user' => 'Prepare price 90']], 'expect' => ['outcome' => 'proposal',
                'operations' => ['update_price'], 'plan_any' => ['fields.regular_price' => []]]]);
    }

    public function test_plugin_activation_health_get_is_not_a_missing_provider_response(): void
    {
        $round = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$round) {
            return match (++$round) {
                1 => $this->tool('list_plugins', []),
                2 => $this->tool('propose_plugin_toggle', ['plugin' => 'hello-dolly/hello.php', 'active' => true]),
                default => $this->answer('ההצעה מוכנה.'),
            };
        }]);
        $case = ['id' => 'harness-007', 'domain' => 'harness', 'title' => 'בדיקת בריאות אתר מדומה',
            'turns' => [['user' => 'תפעיל את Hello Dolly'], ['user' => 'כן']],
            'expect' => ['outcome' => 'applied', 'operations' => ['toggle_plugin'],
                'tools_all' => ['wp_plugin_activate'], 'final' => ['plugins.hello-dolly/hello.php.active' => true]]];
        $report = app(EvaluationRunner::class)->run([$case], true);

        $this->assertSame(1, $report['summary']['passed'], json_encode($report['cases'][0]['failures']));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://evaluation.example');
        $this->assertGreaterThan(0, $report['provider_requests']);
        $this->assertSame($report['provider_requests'], $report['provider_responses']);
        $this->assertSame([], $report['cases'][0]['provider_diagnostics']);
    }

    public function test_explicit_cache_creation_is_separate_from_inference_and_usage_does_not_double_count_cached_tokens(): void
    {
        config(['siteagent.assistant.cache.enabled' => true]);
        $round = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) use (&$round) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                return $this->cacheResponse();
            }
            $this->assertSame('cachedContents/evaluation-reference', $request['cachedContent']);
            $this->assertArrayNotHasKey('systemInstruction', $request->data());
            $this->assertArrayNotHasKey('tools', $request->data());

            return Http::response(['candidates' => [['content' => ['parts' => ++$round === 1
                ? [['functionCall' => ['name' => 'get_product_counts', 'args' => (object) []]]]
                : [['text' => 'יש 40 מוצרים.']]]]],
                'usageMetadata' => ['promptTokenCount' => 1000, 'cachedContentTokenCount' => 800, 'totalTokenCount' => 1020]]);
        }]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true);

        $this->assertSame(1, $report['summary']['passed'], json_encode($report['cases'][0]['failures']));
        $this->assertSame(1, $report['provider_requests']);
        $this->assertSame(1, $report['provider_responses']);
        $this->assertSame(1, $report['cache_management_requests']);
        $this->assertSame(1, $report['cache_management_responses']);
        $this->assertSame('google_explicit', $report['cache_mode']);
        $this->assertSame(['input_tokens' => 1000, 'cached_input_tokens' => 800, 'uncached_input_tokens' => 200,
            'output_tokens' => 20, 'cache_hit_requests' => 1], $report['cases'][0]['usage']);
        $this->assertSame($report['cases'][0]['usage'], $report['provider_usage']);
        $this->assertStringNotContainsString('evaluation-reference', json_encode($report));
    }

    public function test_isolated_prefix_metadata_survives_scenario_cache_flushes_and_exports_only_to_private_handoff(): void
    {
        config(['siteagent.assistant.cache.enabled' => true]);
        app()->instance(GeminiContextCache::class, new EvaluationGeminiContextCache([], 'isolated-run-stable-salt'));
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                return $this->cacheResponse();
            }

            return $this->tool('get_product_counts', []);
        }]);
        $cases = $this->countCase();
        $cases[] = [...$cases[0], 'id' => 'harness-next-count'];
        $report = app(EvaluationRunner::class)->run($cases, true);

        $this->assertSame(2, $report['summary']['passed'], json_encode(array_column($report['cases'], 'failures')));
        $this->assertSame(1, $report['cache_management_requests']);
        $this->assertSame(2, $report['provider_responses']);
        $this->assertSame(0, $report['cases'][1]['cache_management_requests']);
        $this->assertNotEmpty($report['_cache_state']['entries']);
        $this->assertArrayNotHasKey('_cache_state', $report['cases'][0]);
        $this->assertStringNotContainsString('כמה מוצרים', json_encode($report['_cache_state'], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('test-evaluation-key', json_encode($report['_cache_state']));
    }

    public function test_required_evaluation_cache_failure_stops_before_any_uncached_inference(): void
    {
        config(['siteagent.assistant.cache.enabled' => true]);
        app()->instance(GeminiContextCache::class, new EvaluationGeminiContextCache([], 'isolated-run-stable-salt'));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Unavailable']], 503)]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true);

        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertSame(0, $report['provider_requests']);
        $this->assertSame(0, $report['provider_responses']);
        $this->assertSame(1, $report['cache_management_requests']);
        $this->assertSame('fallback', $report['cases'][0]['cache_status']['state']);
        $this->assertFalse($report['cases'][0]['model_executed']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), ':generateContent'));
    }

    public function test_cache_creation_failure_falls_back_without_blocking_a_successful_model_case(): void
    {
        config(['siteagent.assistant.cache.enabled' => true]);
        $round = 0;
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) use (&$round) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                return Http::response(['error' => ['message' => 'sensitive cache response']], 503);
            }
            $this->assertArrayNotHasKey('cachedContent', $request->data());
            $this->assertArrayHasKey('tools', $request->data());

            return ++$round === 1 ? $this->tool('get_product_counts', []) : $this->answer('יש 40 מוצרים.');
        }]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true);

        $this->assertSame(1, $report['summary']['passed'], json_encode($report['cases'][0]['failures']));
        $this->assertSame(1, $report['provider_requests']);
        $this->assertSame(1, $report['provider_responses']);
        $this->assertSame(1, $report['cache_management_requests']);
        $this->assertSame([['operation' => 'create', 'http_status' => 503]], $report['cases'][0]['cache_diagnostics']);
        $this->assertSame('fallback', $report['cache_status']['state']);
        $this->assertStringNotContainsString('sensitive cache response', json_encode($report));
    }

    public function test_a_rejected_cache_reference_followed_by_the_same_successful_uncached_request_is_not_a_provider_failure(): void
    {
        config(['siteagent.assistant.cache.enabled' => true]);
        $round = 0;
        $cachedContents = null;
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) use (&$round, &$cachedContents) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                return $this->cacheResponse();
            }
            if (isset($request['cachedContent'])) {
                $cachedContents = $request['contents'];

                return Http::response(['error' => ['message' => 'cachedContent expired']], 404);
            }
            if (++$round === 1) {
                $this->assertSame($cachedContents, $request['contents']);

                return $this->tool('get_product_counts', []);
            }

            return $this->answer('יש 40 מוצרים.');
        }]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true);

        $this->assertSame(1, $report['summary']['passed'], json_encode($report['cases'][0]['failures']));
        $this->assertSame(2, $report['provider_requests']);
        $this->assertSame(1, $report['provider_responses']);
        $this->assertSame(1, $report['cache_reference_retries']);
        $this->assertSame(1, $report['cases'][0]['cache_reference_retries']);
        $this->assertSame('cache_reference_rejected', $report['cases'][0]['provider_diagnostics'][0]['reason']);
    }

    #[TestWith([401, 'Invalid API key'])]
    #[TestWith([400, 'Invalid function declarations schema'])]
    public function test_cache_management_success_cannot_mask_inference_authentication_or_schema_failures(int $status, string $message): void
    {
        config(['siteagent.assistant.cache.enabled' => true]);
        Http::fake(['generativelanguage.googleapis.com/*' => function (Request $request) use ($status, $message) {
            if (str_ends_with($request->url(), '/cachedContents')) {
                return $this->cacheResponse();
            }

            return Http::response(['error' => ['message' => $message]], $status);
        }]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true);

        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertSame(0, $report['provider_responses']);
        $this->assertGreaterThan(0, $report['cache_management_responses']);
        $this->assertSame(0, $report['cache_reference_retries']);
        $this->assertContains('provider_transport_or_response_failure', $report['cases'][0]['failures']);
        $this->assertFalse($report['cases'][0]['model_executed']);
    }

    public function test_an_unrelated_successful_request_does_not_resolve_a_prior_cache_failure(): void
    {
        config(['siteagent.assistant.cache.enabled' => true]);
        $runner = app(EvaluationRunner::class);
        $record = new \ReflectionMethod($runner, 'recordProviderResponse');
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent';
        $failed = new Response(404, [], json_encode(['error' => ['message' => 'cachedContent expired']]));
        $request = new \GuzzleHttp\Psr7\Request('POST', $url, [], json_encode([
            'cachedContent' => 'cachedContents/example', 'contents' => [['text' => 'original request']],
        ]));
        $record->invoke($runner, $failed, 'google', $request);
        $success = new Response(200, [], json_encode(['candidates' => [['content' => ['parts' => [['text' => 'hello']]]]],
            'usageMetadata' => ['promptTokenCount' => 100, 'cachedContentTokenCount' => 999, 'totalTokenCount' => 110]]));
        $otherRequest = new \GuzzleHttp\Psr7\Request('POST', $url, [], json_encode([
            'systemInstruction' => [], 'contents' => [['text' => 'different request']],
        ]));
        $record->invoke($runner, $success, 'google', $otherRequest);

        $this->assertSame(0, (new \ReflectionProperty($runner, 'cacheReferenceRetries'))->getValue($runner));
        $usage = (new \ReflectionProperty($runner, 'providerUsage'))->getValue($runner);
        $this->assertSame(100, $usage['cached_input_tokens']);
        $this->assertSame(0, $usage['uncached_input_tokens']);
    }

    public function test_provider_usage_is_reported_even_when_a_successful_http_response_contains_no_model_answer(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'promptFeedback' => ['blockReason' => 'SAFETY'],
            'usageMetadata' => ['promptTokenCount' => 1000, 'cachedContentTokenCount' => 800, 'totalTokenCount' => 1010],
        ])]);
        $report = app(EvaluationRunner::class)->run($this->countCase(), true);

        $this->assertSame(1, $report['summary']['blocked']);
        $this->assertSame(0, $report['provider_responses']);
        $this->assertSame(1000, $report['cases'][0]['usage']['input_tokens']);
        $this->assertSame(800, $report['cases'][0]['usage']['cached_input_tokens']);
        $this->assertSame(10, $report['cases'][0]['usage']['output_tokens']);
    }

    public function test_only_exact_cache_creation_and_renewal_paths_are_allowed_when_enabled(): void
    {
        config(['siteagent.assistant.cache.enabled' => true]);
        $runner = app(EvaluationRunner::class);
        (new \ReflectionProperty($runner, 'caseStarted'))->setValue($runner, microtime(true));
        (new \ReflectionMethod($runner, 'restrictNetwork'))->invoke($runner);
        Http::fake(['*' => Http::response(['name' => 'cachedContents/safe-id'])]);
        $base = 'https://generativelanguage.googleapis.com';
        Http::post($base.'/v1beta/cachedContents', []);
        Http::patch($base.'/v1beta/cachedContents/safe-id', []);

        foreach ([['GET', $base.'/v1beta/cachedContents'], ['DELETE', $base.'/v1beta/cachedContents/safe-id'],
            ['PATCH', $base.'/v1beta/cachedContents/safe-id/extra'], ['POST', $base.'/v1beta/cachedContents?extra=1'],
            ['POST', 'https://unapproved.example/v1beta/cachedContents'], ['POST', $base.'/v1beta/files']] as [$method, $url]) {
            try {
                Http::send($method, $url);
                $this->fail('Unexpected allowed cache request');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Evaluation blocked external traffic.', $exception->getMessage());
            }
        }
        $this->assertSame(0, (new \ReflectionProperty($runner, 'providerRequests'))->getValue($runner));
        $this->assertSame(2, (new \ReflectionProperty($runner, 'cacheManagementRequests'))->getValue($runner));
        config(['siteagent.assistant.cache.enabled' => false]);
        $this->expectExceptionMessage('Evaluation blocked external traffic.');
        Http::post($base.'/v1beta/cachedContents', []);
    }

    private function countCase(): array
    {
        return [['id' => 'harness-count', 'domain' => 'harness', 'title' => 'ספירה באמצעות הכלי',
            'turns' => [['user' => 'כמה מוצרים יש לי באתר?']],
            'expect' => ['outcome' => 'read', 'tools_all' => ['wc_product_counts'], 'reply_contains' => ['40']]]];
    }

    private function cacheResponse(): PromiseInterface
    {
        return Http::response(['name' => 'cachedContents/evaluation-reference',
            'model' => 'models/gemini-3.1-flash-lite', 'expireTime' => now()->addHour()->toIso8601String()]);
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
