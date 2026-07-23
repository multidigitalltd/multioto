<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Filament\Pages\ManualCollection;
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

    public function test_it_lists_the_overdue_to_collect_rows_first(): void
    {
        // Oldest charge date first: overdue rows to collect rise to the top;
        // just-paid subscriptions (future date) sink to the bottom.
        $overdue = $this->manualSub(now()->subDays(10));   // most overdue → top
        $dueToday = $this->manualSub(now()->subDay());
        $paidRolledForward = $this->manualSub(now()->addMonth()); // bottom

        Livewire::test(ManualCollection::class)
            ->assertCanSeeTableRecords([$overdue, $dueToday, $paidRolledForward], inOrder: true);
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
