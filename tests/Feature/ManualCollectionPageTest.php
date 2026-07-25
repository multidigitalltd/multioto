<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Pages\ManualCollection;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ManualCollectionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_the_page_lists_only_manually_collected_subscriptions(): void
    {
        $customer = Customer::factory()->create(['payment_method' => 'bank_transfer']);
        $manual = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create()->id,
            'token_id' => null,
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->subDay(),
        ]);
        // A card subscription (has a token) must not appear here.
        $card = Subscription::factory()->create();

        Livewire::test(ManualCollection::class)
            ->assertCanSeeTableRecords([$manual])
            ->assertCanNotSeeTableRecords([$card]);
    }

    public function test_the_nav_badge_counts_due_manual_collections(): void
    {
        $this->assertNull(ManualCollection::getNavigationBadge());

        $customer = Customer::factory()->create(['payment_method' => 'standing_order']);
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'token_id' => null,
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->subDay(),
        ]);

        $this->assertSame('1', ManualCollection::getNavigationBadge());
    }

    public function test_mark_paid_is_only_offered_while_the_demand_is_due(): void
    {
        $due = $this->manualSub(now()->subDay());          // overdue → collectable
        $collected = $this->manualSub(now()->addMonth());  // already rolled forward → not re-issuable

        Livewire::test(ManualCollection::class)
            ->assertTableActionVisible('markPaid', $due)
            ->assertTableActionHidden('markPaid', $collected);
    }

    public function test_it_lists_the_newest_charge_dates_first(): void
    {
        // Newest charge date first: recently-paid subscriptions (rolled to a
        // future date) at the top, the oldest dates at the bottom.
        $paidRolledForward = $this->manualSub(now()->addMonth()); // newest → top
        $dueToday = $this->manualSub(now()->subDay());
        $overdue = $this->manualSub(now()->subDays(10));   // oldest → bottom

        Livewire::test(ManualCollection::class)
            ->assertCanSeeTableRecords([$paidRolledForward, $dueToday, $overdue], inOrder: true);
    }

    public function test_it_shows_when_the_last_payment_was_actually_made_and_how_late(): void
    {
        // Due on the 1st, actually paid on the 9th — the table must show the
        // REAL payment date and the 8-day delay, not just the next due date.
        // This is how chronically-late payers become visible.
        $sub = $this->manualSub(now()->addMonth());
        Charge::create([
            'subscription_id' => $sub->id,
            'amount_agorot' => 10000, 'vat_agorot' => 1800, 'total_agorot' => 11800,
            'status' => ChargeStatus::Succeeded, 'attempt_number' => 1,
            'period_start' => now()->subDays(8)->toDateString(),
            'period_end' => now()->addDays(22)->toDateString(),
            'charged_at' => now(),
        ]);

        Livewire::test(ManualCollection::class)
            ->assertSeeText(now()->format('d/m/Y'))
            ->assertSeeText('באיחור של 8 ימים');
    }

    public function test_an_on_time_payment_is_labeled_as_such(): void
    {
        $sub = $this->manualSub(now()->addMonth());
        Charge::create([
            'subscription_id' => $sub->id,
            'amount_agorot' => 10000, 'vat_agorot' => 1800, 'total_agorot' => 11800,
            'status' => ChargeStatus::Succeeded, 'attempt_number' => 1,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'charged_at' => now(),
        ]);

        Livewire::test(ManualCollection::class)
            ->assertSeeText('שולם בזמן')
            ->assertDontSeeText('באיחור של');
    }

    public function test_a_legacy_charge_without_charged_at_still_shows_a_payment_date(): void
    {
        // Rows that predate the charged_at stamp fall back to created_at — the
        // customer's payment history must not read as "טרם נרשם תשלום".
        $sub = $this->manualSub(now()->addMonth());
        $legacy = Charge::create([
            'subscription_id' => $sub->id,
            'amount_agorot' => 10000, 'vat_agorot' => 1800, 'total_agorot' => 11800,
            'status' => ChargeStatus::Succeeded, 'attempt_number' => 1,
            'period_start' => now()->subDays(3)->toDateString(),
            'period_end' => now()->addDays(27)->toDateString(),
            'charged_at' => null,
        ]);

        Livewire::test(ManualCollection::class)
            ->assertSeeText($legacy->created_at->format('d/m/Y'))
            ->assertDontSeeText('טרם נרשם תשלום');
    }

    private function manualSub(Carbon $nextChargeAt): Subscription
    {
        return Subscription::factory()->create([
            'customer_id' => Customer::factory()->create(['payment_method' => 'bank_transfer'])->id,
            'plan_id' => Plan::factory()->create()->id,
            'token_id' => null,
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => $nextChargeAt,
        ]);
    }
}
