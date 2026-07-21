<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Filament\Pages\CollectionForecast;
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

    public function test_the_subheading_totals_the_open_debt_by_age_bucket(): void
    {
        $customer = Customer::factory()->create();
        $this->demand($customer->id, 10000, 10);   // 0–30 → ₪100
        $this->demand($customer->id, 20000, 45);   // 31–60 → ₪200
        $this->demand($customer->id, 30000, 120);  // 90+  → ₪300

        $sub = (new CollectionForecast)->getSubheading();

        $this->assertStringContainsString('0–30 ימים: '.Money::ils(10000), $sub);
        $this->assertStringContainsString('31–60 ימים: '.Money::ils(20000), $sub);
        $this->assertStringContainsString('מעל 90 ימים: '.Money::ils(30000), $sub);
        $this->assertStringContainsString('סה״כ פתוח: '.Money::ils(60000), $sub);
    }

    public function test_aging_uses_the_debt_origin_not_the_last_reminder(): void
    {
        $customer = Customer::factory()->create();
        // 120 days old but "reminded" just now (demand_sent_at = now) — must age
        // to 90+, not 0–30.
        $this->demand($customer->id, 30000, 120);

        $sub = (new CollectionForecast)->getSubheading();

        $this->assertStringContainsString('מעל 90 ימים: '.Money::ils(30000), $sub);
        $this->assertStringNotContainsString('0–30 ימים:', $sub);
    }

    public function test_the_navigation_badge_shows_the_total_open(): void
    {
        $customer = Customer::factory()->create();
        $this->demand($customer->id, 10000, 10);
        $this->demand($customer->id, 20000, 45);

        $this->assertSame(Money::ils(30000), CollectionForecast::getNavigationBadge());
    }
}
