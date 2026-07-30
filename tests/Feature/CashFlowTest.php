<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Filament\Pages\CashFlow;
use App\Filament\Widgets\CashFlowStats;
use App\Filament\Widgets\OpenDemandsTable;
use App\Filament\Widgets\UpcomingRenewalsTable;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One screen for all the money that is coming, split by how it actually gets
 * here. The distinction that earns the page: a renewal with a saved card
 * collects itself, a renewal without one collects only if a person notices, and
 * a proforma already sent is waiting on the customer. Add them into one number
 * and the second kind quietly stops arriving.
 */
class CashFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** A renewal; $withCard decides whether it can charge itself. */
    private function sub(int $priceAgorot, ?Carbon $nextChargeAt, bool $withCard = true, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        $customer = Customer::factory()->create();

        return Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create(['price_agorot' => $priceAgorot, 'vat_applies' => false])->id,
            'status' => $status,
            'next_charge_at' => $nextChargeAt,
            'token_id' => $withCard
                ? PaymentToken::factory()->create(['customer_id' => $customer->id])->id
                : null,
        ]);
    }

    /**
     * $ageDays sets the immutable created_at (the debt origin). demand_sent_at is
     * always "now" to mimic a demand reminded moments ago — aging must ignore it.
     */
    private function demand(int $customerId, int $totalAgorot, ?int $ageDays, ?Carbon $dueAt = null): Charge
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
            'due_at' => $dueAt,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        if ($ageDays !== null) {
            Charge::whereKey($charge->id)->update(['created_at' => now()->subDays($ageDays)]);
            $charge->refresh();
        }

        return $charge;
    }

    public function test_the_page_opens(): void
    {
        Livewire::test(CashFlow::class)->assertOk();
    }

    /*
    | ----------------------------------------------------------------
    | Automatic versus manual
    | ----------------------------------------------------------------
    */

    public function test_the_squares_separate_what_collects_itself_from_what_does_not(): void
    {
        $this->sub(10000, now()->addDays(3));               // card → automatic
        $this->sub(15000, now()->addDays(6));               // card → automatic
        $this->sub(20000, now()->addDays(10), withCard: false); // no card → manual

        Livewire::test(CashFlowStats::class)
            ->assertSee('גבייה אוטומטית — 30 יום')
            ->assertSee('גבייה ידנית — 30 יום')
            ->assertSee(Money::ils(25000))  // automatic: 100 + 150
            ->assertSee(Money::ils(20000))  // manual: 200
            ->assertSee('חידושים בלי כרטיס — לא ייגבו לבד');
    }

    public function test_a_renewal_without_a_card_is_marked_as_manual_in_the_table(): void
    {
        $automatic = $this->sub(10000, now()->addDays(3));
        $manual = $this->sub(20000, now()->addDays(4), withCard: false);

        Livewire::test(UpcomingRenewalsTable::class)
            ->assertCanSeeTableRecords([$automatic, $manual])
            ->assertSee('גבייה אוטומטית')
            ->assertSee('גבייה ידנית — אין כרטיס');
    }

    public function test_the_renewals_table_lists_only_what_is_still_ahead(): void
    {
        $soon = $this->sub(10000, now()->addDays(5));
        $later = $this->sub(20000, now()->addDays(50));
        $overdue = $this->sub(30000, now()->subDays(3));   // past — not a forecast
        $canceled = $this->sub(40000, now()->addDays(10), status: SubscriptionStatus::Canceled);

        Livewire::test(UpcomingRenewalsTable::class)
            ->assertCanSeeTableRecords([$soon, $later])
            ->assertCanNotSeeTableRecords([$overdue, $canceled]);
    }

    /*
    | ----------------------------------------------------------------
    | Proforma invoices already out
    | ----------------------------------------------------------------
    */

    public function test_the_demands_table_lists_open_demands_and_excludes_paid_or_non_demands(): void
    {
        $customer = Customer::factory()->create();

        $fresh = $this->demand($customer->id, 10000, 10);
        $mid = $this->demand($customer->id, 20000, 45);
        $old = $this->demand($customer->id, 30000, 120);
        $plain = $this->demand($customer->id, 5000, null);  // never sent as a demand
        $paid = $this->demand($customer->id, 9000, 5);
        $paid->update(['status' => ChargeStatus::Succeeded]);

        Livewire::test(OpenDemandsTable::class)
            ->assertCanSeeTableRecords([$fresh, $mid, $old])
            ->assertCanNotSeeTableRecords([$plain, $paid]);
    }

    public function test_open_demands_are_counted_and_the_overdue_ones_named(): void
    {
        $customer = Customer::factory()->create();
        $this->demand($customer->id, 50000, 5, dueAt: now()->subDay());

        Livewire::test(CashFlowStats::class)
            ->assertSee('חשבוניות עסקה פתוחות')
            ->assertSee('1 באיחור')
            ->assertSee(Money::ils(50000));
    }

    public function test_aging_uses_the_debt_origin_not_the_last_reminder(): void
    {
        $customer = Customer::factory()->create();
        // 120 days old but reminded moments ago. Reading demand_sent_at would
        // make it look brand new on every nudge.
        $this->demand($customer->id, 30000, 120);

        Livewire::test(CashFlowStats::class)
            ->assertSee('מתוכן ישנות מ-60 יום')
            ->assertSee(Money::ils(30000));

        Livewire::test(OpenDemandsTable::class)->assertSee('מעל 90 ימים');
    }

    public function test_the_grand_total_adds_renewals_and_open_demands(): void
    {
        $this->sub(10000, now()->addDays(3));
        $customer = Customer::factory()->create();
        $this->demand($customer->id, 50000, 5);

        Livewire::test(CashFlowStats::class)
            ->assertSee('סה״כ צפוי')
            ->assertSee(Money::ils(60000));
    }

    /*
    | ----------------------------------------------------------------
    | The old screens' breakdowns, kept as filters
    | ----------------------------------------------------------------
    */

    public function test_the_horizon_filter_narrows_the_renewals_to_the_chosen_window(): void
    {
        $week = $this->sub(10000, now()->addDays(5));
        $month = $this->sub(20000, now()->addDays(20));
        $quarter = $this->sub(30000, now()->addDays(80));

        Livewire::test(UpcomingRenewalsTable::class)
            ->filterTable('horizon', '7')
            ->assertCanSeeTableRecords([$week])
            ->assertCanNotSeeTableRecords([$month, $quarter])
            ->filterTable('horizon', '90')
            ->assertCanSeeTableRecords([$week, $month, $quarter]);
    }

    public function test_the_age_filter_narrows_the_demands_to_the_chosen_bucket(): void
    {
        $customer = Customer::factory()->create();
        $fresh = $this->demand($customer->id, 10000, 10);   // 0–30
        $mid = $this->demand($customer->id, 20000, 45);     // 31–60
        $old = $this->demand($customer->id, 30000, 120);    // 90+

        Livewire::test(OpenDemandsTable::class)
            ->filterTable('bucket', '1')
            ->assertCanSeeTableRecords([$mid])
            ->assertCanNotSeeTableRecords([$fresh, $old])
            ->filterTable('bucket', '3')
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$fresh, $mid]);
    }

    /*
    | ----------------------------------------------------------------
    | Where the numbers may appear
    | ----------------------------------------------------------------
    */

    public function test_the_amounts_are_not_shown_on_the_navigation_badge(): void
    {
        $this->demand(Customer::factory()->create()->id, 10000, 10);

        // The total is intentionally kept off the tab — it shows only inside.
        $this->assertNull(CashFlow::getNavigationBadge());
    }

    public function test_a_team_member_without_the_finance_module_cannot_reach_the_screen(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Agent,
            'allowed_modules' => ['support'],
        ]));

        $this->assertFalse(CashFlow::canAccess());
        $this->assertFalse(CashFlowStats::canView());
        $this->assertFalse(UpcomingRenewalsTable::canView());
        $this->assertFalse(OpenDemandsTable::canView());
    }

    public function test_the_widgets_are_not_auto_discovered_onto_the_dashboard(): void
    {
        // They render only inside this page; otherwise the amounts would leak
        // onto the main dashboard for everyone who opens it.
        $this->assertFalse(CashFlowStats::isDiscovered());
        $this->assertFalse(UpcomingRenewalsTable::isDiscovered());
        $this->assertFalse(OpenDemandsTable::isDiscovered());
    }
}
