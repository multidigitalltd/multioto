<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Pages\RevenueForecast;
use App\Filament\Widgets\RevenueForecastStats;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RevenueForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function sub(int $priceAgorot, ?Carbon $nextChargeAt, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        return Subscription::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'plan_id' => Plan::factory()->create(['price_agorot' => $priceAgorot, 'vat_applies' => false])->id,
            'status' => $status,
            'next_charge_at' => $nextChargeAt,
        ]);
    }

    public function test_it_lists_only_upcoming_renewals(): void
    {
        $soon = $this->sub(10000, now()->addDays(5));
        $later = $this->sub(20000, now()->addDays(50));
        $overdue = $this->sub(30000, now()->subDays(3));        // past — belongs to collection, not forecast
        $canceled = $this->sub(40000, now()->addDays(10), SubscriptionStatus::Canceled);

        Livewire::test(RevenueForecast::class)
            ->assertCanSeeTableRecords([$soon, $later])
            ->assertCanNotSeeTableRecords([$overdue, $canceled]);
    }

    public function test_the_stat_squares_project_income_by_horizon(): void
    {
        // Two renewals inside 7 days, one at 45 days.
        $this->sub(10000, now()->addDays(3));
        $this->sub(15000, now()->addDays(6));
        $this->sub(20000, now()->addDays(45));

        Livewire::test(RevenueForecastStats::class)
            ->assertSee('צפוי ב-7 ימים')
            ->assertSee(Money::ils(25000))  // 7-day bucket: 100+150
            ->assertSee(Money::ils(45000)); // 90-day bucket: all three
    }

    public function test_open_payment_demands_are_included_in_the_forecast_scale(): void
    {
        $this->sub(10000, now()->addDays(3)); // one renewal (100) inside 90 days

        // An open, overdue payment demand of 500 — real expected inflow.
        Charge::create([
            'customer_id' => Customer::factory()->create()->id,
            'amount_agorot' => 50000,
            'vat_agorot' => 0,
            'total_agorot' => 50000,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => 'דרישה',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'demand_sent_at' => now()->subDay(),
            'due_at' => now()->subDay(),
        ]);

        Livewire::test(RevenueForecastStats::class)
            ->assertSee('דרישות תשלום פתוחות')
            ->assertSee('1 באיחור')
            ->assertSee(Money::ils(50000))              // demands square
            ->assertSee(Money::ils(60000));             // grand total: 100 renewal + 500 demand
    }
}
