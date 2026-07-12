<?php

namespace Tests\Feature;

use App\Enums\BillingInterval;
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
}
