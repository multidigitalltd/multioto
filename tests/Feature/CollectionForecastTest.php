<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Filament\Pages\CollectionForecast;
use App\Filament\Widgets\CollectionForecastStats;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CollectionForecastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * $ageDays sets the immutable created_at (the debt origin). demand_sent_at is
     * always "now" to mimic a demand reminded moments ago — aging must ignore it.
     */
    private function demand(int $customerId, int $totalAgorot, ?int $ageDays): Charge
    {
        $charge = Charge::create([
            'customer_id' => $customerId,
            'amount_agorot' => $totalAgorot,
            'vat_agorot' => 0,
            'total_agorot' => $totalAgorot,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => 'דרישה',
            'demand_sent_at' => $ageDays === null ? null : now(),
            'demand_channel' => $ageDays === null ? null : 'email',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        if ($ageDays !== null) {
            Charge::whereKey($charge->id)->update(['created_at' => now()->subDays($ageDays)]);
            $charge->refresh();
        }

        return $charge;
    }

    public function test_it_lists_open_demands_and_excludes_paid_or_non_demands(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();

        $fresh = $this->demand($customer->id, 10000, 10);   // 0–30
        $mid = $this->demand($customer->id, 20000, 45);     // 31–60
        $old = $this->demand($customer->id, 30000, 120);    // 90+
        $plain = $this->demand($customer->id, 5000, null);  // not a demand
        $paid = $this->demand($customer->id, 9000, 5);
        $paid->update(['status' => ChargeStatus::Succeeded]);

        Livewire::test(CollectionForecast::class)
            ->assertCanSeeTableRecords([$fresh, $mid, $old])
            ->assertCanNotSeeTableRecords([$plain, $paid]);
    }

    public function test_the_stat_squares_total_the_open_debt_by_age_bucket(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        $this->demand($customer->id, 10000, 10);   // 0–30 → ₪100
        $this->demand($customer->id, 20000, 45);   // 31–60 → ₪200
        $this->demand($customer->id, 30000, 120);  // 90+  → ₪300

        // The breakdown lives in the header stat squares, not the nav badge.
        Livewire::test(CollectionForecastStats::class)
            ->assertSee('0–30 ימים')
            ->assertSee('31–60 ימים')
            ->assertSee('מעל 90 ימים')
            ->assertSee('סה״כ פתוח')
            ->assertSee(Money::ils(10000))
            ->assertSee(Money::ils(20000))
            ->assertSee(Money::ils(30000))
            ->assertSee(Money::ils(60000));
    }

    public function test_aging_uses_the_debt_origin_not_the_last_reminder(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        // 120 days old but "reminded" just now (demand_sent_at = now) — must age
        // to 90+ (₪300), leaving the 0–30 square at zero.
        $this->demand($customer->id, 30000, 120);

        Livewire::test(CollectionForecastStats::class)
            ->assertSee('מעל 90 ימים')
            ->assertSee(Money::ils(30000)) // the 90+ square
            ->assertSee(Money::ils(0));    // the 0–30 square is empty
    }

    public function test_the_amount_is_not_shown_on_the_navigation_badge(): void
    {
        $customer = Customer::factory()->create();
        $this->demand($customer->id, 10000, 10);

        // The total is intentionally kept off the tab — it shows only inside.
        $this->assertNull(CollectionForecast::getNavigationBadge());
    }

    public function test_the_stats_widget_is_not_auto_discovered_onto_the_dashboard(): void
    {
        // It renders only inside the forecast page (getHeaderWidgets), never on
        // the main dashboard — otherwise the amounts would leak there too.
        $this->assertFalse(CollectionForecastStats::isDiscovered());
    }
}
