<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Services\Ai\AiCostReporter;
use App\Services\Ai\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    private array $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => ['ok' => ['type' => 'boolean']],
        'required' => ['ok'],
    ];

    public function test_it_records_token_usage_from_a_gemini_response(): void
    {
        config([
            'billing.ai.enabled' => true, 'billing.ai.api_key' => 'k',
            'billing.ai.provider' => 'google', 'billing.ai.model' => 'gemini-flash-latest',
            'billing.ai.base_url' => 'https://generativelanguage.googleapis.com',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
                'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 40, 'totalTokenCount' => 150],
            ]),
        ]);

        app(ClaudeClient::class)->structured('s', 'p', $this->schema);

        $row = AiUsage::first();
        $this->assertNotNull($row);
        $this->assertSame('gemini-flash-latest', $row->model);
        $this->assertSame(100, $row->input_tokens);
        $this->assertSame(50, $row->output_tokens); // total (150) − prompt (100), captures thinking
        $this->assertSame(1, $row->requests);
    }

    public function test_it_records_token_usage_from_an_anthropic_response(): void
    {
        config([
            'billing.ai.enabled' => true, 'billing.ai.api_key' => 'k',
            'billing.ai.provider' => 'anthropic', 'billing.ai.model' => 'claude-haiku-4-5',
            'billing.ai.base_url' => 'https://api.anthropic.com', 'billing.ai.effort' => 'low',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"ok":true}']],
                'usage' => ['input_tokens' => 200, 'output_tokens' => 80],
            ]),
        ]);

        app(ClaudeClient::class)->structured('s', 'p', $this->schema);

        $row = AiUsage::first();
        $this->assertSame(200, $row->input_tokens);
        $this->assertSame(80, $row->output_tokens);
    }

    public function test_cost_is_computed_from_the_price_table(): void
    {
        // gemini-3.1-flash-lite → [0.25, 1.50] per 1M tokens.
        AiUsage::create([
            'date' => now()->toDateString(), 'provider' => 'google', 'model' => 'gemini-3.1-flash-lite',
            'input_tokens' => 1_000_000, 'output_tokens' => 1_000_000, 'requests' => 3,
        ]);

        $totals = AiUsage::totals();
        $this->assertSame(1.75, $totals['usd']); // 1.00×0.25 + 1.00×1.50
        $this->assertSame(3, $totals['requests']);

        // The lite alias prices as the lite tier, not the full flash tier.
        $this->assertSame([0.25, 1.50], AiUsage::priceFor('gemini-flash-lite-latest'));
        $this->assertSame([1.50, 9.00], AiUsage::priceFor('gemini-flash-latest'));
    }

    public function test_the_reporter_snapshot_caches_and_refreshes(): void
    {
        AiUsage::create([
            'date' => now()->toDateString(), 'provider' => 'google', 'model' => 'gemini-3.5-flash',
            'input_tokens' => 1_000_000, 'output_tokens' => 0, 'requests' => 1,
        ]);

        $reporter = app(AiCostReporter::class);
        $first = $reporter->snapshot();
        $this->assertSame(1.5, $first['total']['usd']); // 1M input × $1.50

        // More usage accrues (same day+model → the rollup row increments), but the
        // cached figure holds until it is refreshed.
        AiUsage::record('google', 'gemini-3.5-flash', 1_000_000, 0);
        $this->assertSame(1.5, $reporter->snapshot()['total']['usd']);
        $this->assertSame(3.0, $reporter->snapshot(fresh: true)['total']['usd']);
    }
}
