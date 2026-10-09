<?php

namespace Tests\Feature;

use App\Models\AiCustomerUsage;
use App\Models\AiUsage;
use App\Models\Customer;
use App\Services\Ai\AiCostReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiCachedUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_callers_record_zero_cached_tokens_and_keep_normal_input_totals(): void
    {
        $customer = Customer::factory()->create();

        AiUsage::record('google', 'gemini-3.1-flash-lite', 100, 20);
        AiCustomerUsage::record($customer->id, 'google', 'gemini-3.1-flash-lite', 100, 20);

        foreach ([AiUsage::sole(), AiCustomerUsage::sole()] as $row) {
            $this->assertSame(100, $row->input_tokens);
            $this->assertSame(20, $row->output_tokens);
            $this->assertSame(0, $row->cached_input_tokens);
            $this->assertSame(1, $row->requests);
        }
    }

    public function test_cached_tokens_accumulate_as_a_subset_without_subtracting_input_or_changing_the_estimate(): void
    {
        config(['billing.ai.pricing' => ['*' => [1.0, 2.0]]]);
        $customer = Customer::factory()->create();

        foreach ([[1_000_000, 100_000, 750_000], [500_000, 50_000, 250_000]] as [$input, $output, $cached]) {
            AiUsage::record('google', 'gemini-3.1-flash-lite', $input, $output, $cached);
            AiCustomerUsage::record($customer->id, 'google', 'gemini-3.1-flash-lite', $input, $output, $cached);
        }

        foreach ([AiUsage::sole(), AiCustomerUsage::sole()] as $row) {
            $this->assertSame(1_500_000, $row->input_tokens);
            $this->assertSame(1_000_000, $row->cached_input_tokens);
            $this->assertSame(150_000, $row->output_tokens);
            $this->assertSame(2, $row->requests);
            $this->assertSame(1.8, $row->costUsd());
        }
    }

    #[DataProvider('invalidCachedCounts')]
    public function test_provider_cached_counts_are_clamped_to_the_actual_nonnegative_input(int $input, int $cached, int $expected): void
    {
        $customer = Customer::factory()->create();

        AiUsage::record('google', 'm', $input, 20, $cached);
        AiCustomerUsage::record($customer->id, 'google', 'm', $input, 20, $cached);

        foreach ([AiUsage::sole(), AiCustomerUsage::sole()] as $row) {
            $this->assertSame(max(0, $input), $row->input_tokens);
            $this->assertSame($expected, $row->cached_input_tokens);
            $this->assertSame(20, $row->output_tokens);
            $this->assertSame(1, $row->requests);
        }
    }

    public static function invalidCachedCounts(): array
    {
        return [
            'negative cached count' => [100, -5, 0],
            'cached count exceeds input' => [100, 150, 100],
            'zero input' => [0, 50, 0],
            'negative input' => [-100, 50, 0],
        ];
    }

    public function test_totals_and_model_breakdowns_include_recorded_cache_reads_with_the_same_date_scope(): void
    {
        config(['billing.ai.pricing' => ['*' => [1.0, 2.0]]]);

        $this->travelTo(now()->setDate(2026, 9, 30));
        AiUsage::record('google', 'same-model', 1_000_000, 0, 750_000);
        $this->travelTo(now()->setDate(2026, 10, 8));
        AiUsage::record('google', 'same-model', 200_000, 10_000, 100_000);
        $this->travelTo(now()->setDate(2026, 10, 9));
        AiUsage::record('google', 'same-model', 300_000, 20_000, 150_000);
        AiUsage::record('google', 'other-model', 400_000, 40_000, 400_000);

        $this->assertSame([
            'usd' => 1.04,
            'input_tokens' => 900_000,
            'cached_input_tokens' => 650_000,
            'output_tokens' => 70_000,
            'requests' => 3,
        ], AiUsage::totals(now()->startOfMonth()));
        $this->assertSame(1_900_000, AiUsage::totals()['input_tokens']);
        $this->assertSame(1_400_000, AiUsage::totals()['cached_input_tokens']);
        $this->assertSame([
            [
                'model' => 'same-model',
                'usd' => 0.56,
                'input_tokens' => 500_000,
                'cached_input_tokens' => 250_000,
                'output_tokens' => 30_000,
                'requests' => 2,
            ],
            [
                'model' => 'other-model',
                'usd' => 0.48,
                'input_tokens' => 400_000,
                'cached_input_tokens' => 400_000,
                'output_tokens' => 40_000,
                'requests' => 1,
            ],
        ], AiUsage::byModel(now()->startOfMonth()));

        $snapshot = app(AiCostReporter::class)->snapshot(fresh: true);
        $this->assertSame(1_400_000, $snapshot['total']['cached_input_tokens']);
        $this->assertSame(650_000, $snapshot['this_month']['cached_input_tokens']);
    }

    public function test_cached_tokens_remain_scoped_to_the_customer_and_provider(): void
    {
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();

        AiCustomerUsage::record($first->id, 'google', 'm', 100, 10, 80);
        AiCustomerUsage::record($second->id, 'google', 'm', 200, 20, 150);
        AiCustomerUsage::record($first->id, 'other', 'm', 300, 30);

        $this->assertCount(3, AiCustomerUsage::all());
        $this->assertSame(80, AiCustomerUsage::where('customer_id', $first->id)->where('provider', 'google')->sole()->cached_input_tokens);
        $this->assertSame(150, AiCustomerUsage::where('customer_id', $second->id)->sole()->cached_input_tokens);
        $this->assertSame(0, AiCustomerUsage::where('provider', 'other')->sole()->cached_input_tokens);
    }

    public function test_empty_reports_include_a_zero_cache_count(): void
    {
        $this->assertSame([
            'usd' => 0.0,
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'output_tokens' => 0,
            'requests' => 0,
        ], AiUsage::totals());
        $this->assertSame([], AiUsage::byModel());
    }
}
