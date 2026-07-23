<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Filament\Pages\RevenueForecast;
use App\Filament\Widgets\RevenueForecastStats;
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
}
