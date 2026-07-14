<?php

namespace Tests\Feature;

use App\Enums\BillingInterval;
use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_subscription_inherits_the_customers_saved_card(): void
    {
        // A card-first customer: card captured via /join, saved as the default
        // token, but no subscription created yet.
        $customer = Customer::factory()->create();
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $customer->update(['default_token_id' => $token->id]);

        // The team later adds a custom subscription — no token set on the form.
        $subscription = Subscription::create([
            'customer_id' => $customer->id,
            'name' => 'אחסון מותאם',
            'price_agorot_override' => 9900,
            'next_charge_at' => now(),
        ]);

        // It must inherit the saved card so the scheduler can charge it.
        $this->assertSame($token->id, $subscription->token_id);
    }

    public function test_an_explicit_token_is_not_overwritten_by_the_default(): void
    {
        $customer = Customer::factory()->create();
        $default = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $explicit = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $customer->update(['default_token_id' => $default->id]);

        $subscription = Subscription::create([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create()->id,
            'token_id' => $explicit->id,
            'next_charge_at' => now(),
        ]);

        $this->assertSame($explicit->id, $subscription->token_id);
    }

    public function test_accessors_prefer_the_plan_when_one_is_selected(): void
    {
        // A subscription that was free-form and is now switched to a fixed plan
        // still carries stale custom fields — the plan must win.
        $plan = Plan::factory()->create([
            'name' => 'אחסון סטנדרט',
            'price_agorot' => 5000,
            'vat_applies' => true,
            'billing_interval' => BillingInterval::Monthly,
        ]);
        $subscription = Subscription::factory()->create([
            'plan_id' => $plan->id,
            'name' => 'שם ישן חופשי',
            'billing_interval' => BillingInterval::Yearly,
            'vat_applies' => false,
            'price_agorot_override' => null,
        ]);

        $this->assertSame('אחסון סטנדרט', $subscription->planName());
        $this->assertSame(BillingInterval::Monthly, $subscription->billingInterval());
        $this->assertTrue($subscription->vatApplies());
        $this->assertSame(5000, $subscription->basePriceAgorot());
    }

    public function test_accessors_use_free_form_fields_without_a_plan(): void
    {
        $subscription = Subscription::factory()->create([
            'plan_id' => null,
            'name' => 'חבילה בהתאמה אישית',
            'billing_interval' => BillingInterval::Yearly,
            'vat_applies' => false,
            'price_agorot_override' => 24000,
        ]);

        $this->assertSame('חבילה בהתאמה אישית', $subscription->planName());
        $this->assertSame(BillingInterval::Yearly, $subscription->billingInterval());
        $this->assertFalse($subscription->vatApplies());
        $this->assertSame(24000, $subscription->basePriceAgorot());
    }

    public function test_cancel_stops_billing_and_keeps_the_record(): void
    {
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->addMonth(),
        ]);

        $subscription->cancel();
        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertNotNull($subscription->canceled_at);
        $this->assertNull($subscription->next_charge_at);
        // The row is kept, not deleted.
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
    }

    public function test_mark_due_now_leaves_an_already_overdue_anchor_untouched(): void
    {
        // A past next_charge_at IS the real overdue anchor — pulling it to "now"
        // would gift the late payer free days, so it must be left as-is.
        $original = now()->subDays(15)->startOfDay();
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::PastDue,
            'next_charge_at' => $original,
        ]);

        $subscription->markDueNow();

        $this->assertSame($original->toDateTimeString(), $subscription->fresh()->next_charge_at->toDateTimeString());
    }

    public function test_mark_due_now_restores_a_cleared_anchor_to_the_paid_through_date(): void
    {
        // A suspended subscription (final dunning stage cleared next_charge_at)
        // must become collectable at the date its paid coverage ended — not now.
        $paidThrough = now()->subDays(40)->startOfDay();
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::Suspended,
            'next_charge_at' => null,
        ]);
        $subscription->charges()->create([
            'amount_agorot' => 10000, 'vat_agorot' => 1800, 'total_agorot' => 11800,
            'currency' => config('billing.currency'), 'status' => ChargeStatus::Succeeded,
            'attempt_number' => 1,
            'period_start' => $paidThrough->copy()->subMonth(),
            'period_end' => $paidThrough,
            'charged_at' => $paidThrough,
        ]);

        $subscription->markDueNow();

        $this->assertSame($paidThrough->toDateString(), $subscription->fresh()->next_charge_at->toDateString());
    }

    public function test_mark_due_now_charges_an_up_to_date_active_subscription_immediately(): void
    {
        // "Charge now" on an Active subscription that is paid ahead (nothing
        // overdue) must collect the upcoming period at once — not sit at a future
        // date and silently no-op.
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'current_period_end' => now()->addMonth()->toDateString(),
            'next_charge_at' => now()->addMonth(),
        ]);

        $subscription->markDueNow();

        $this->assertTrue($subscription->fresh()->next_charge_at->isPast());
    }
}
