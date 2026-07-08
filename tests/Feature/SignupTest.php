<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_signup_page_lists_active_plans(): void
    {
        Plan::factory()->create(['name' => 'מסלול פעיל', 'active' => true]);
        Plan::factory()->create(['name' => 'מסלול כבוי', 'active' => false]);

        $this->get(route('signup'))
            ->assertOk()
            ->assertSee('מסלול פעיל')
            ->assertDontSee('מסלול כבוי');
    }

    public function test_signup_creates_a_trialing_customer_and_redirects_to_card_capture(): void
    {
        $plan = Plan::factory()->create(['active' => true]);

        $response = $this->post(route('signup.store'), [
            'name' => 'עסק חדש',
            'business_type' => BusinessType::LicensedDealer->value,
            'email' => 'New@Example.CO.il',
            'phone' => '0501234567',
            'domain' => 'https://newbiz.co.il',
            'plan_id' => $plan->id,
            'terms' => '1',
        ]);

        // Redirects to the signed Cardcom card-capture link.
        $response->assertRedirect();
        $this->assertStringContainsString('/billing/update-card/', $response->headers->get('Location'));
        $this->assertStringContainsString('signature=', $response->headers->get('Location'));

        $customer = Customer::first();
        $this->assertNotNull($customer);
        $this->assertSame('new@example.co.il', $customer->email); // normalized
        $this->assertSame('newbiz.co.il', $customer->sites()->value('domain')); // scheme stripped

        $sub = Subscription::first();
        $this->assertSame(SubscriptionStatus::Trialing, $sub->status);
        $this->assertSame($plan->id, $sub->plan_id);
        $this->assertNull($sub->token_id);
    }

    public function test_exempt_dealer_signup_is_marked_vat_exempt(): void
    {
        $plan = Plan::factory()->create(['active' => true]);

        $this->post(route('signup.store'), [
            'name' => 'עוסק פטור',
            'business_type' => BusinessType::ExemptDealer->value,
            'email' => 'patur@example.co.il',
            'phone' => '0501112222',
            'plan_id' => $plan->id,
            'terms' => '1',
        ])->assertRedirect();

        $this->assertTrue(Customer::first()->vat_exempt);
    }

    public function test_signup_validates_required_fields(): void
    {
        $this->post(route('signup.store'), [])
            ->assertSessionHasErrors(['name', 'business_type', 'email', 'phone', 'plan_id', 'terms']);

        $this->assertSame(0, Customer::count());
    }

    public function test_signup_rejects_an_inactive_plan(): void
    {
        $plan = Plan::factory()->create(['active' => false]);

        $this->post(route('signup.store'), [
            'name' => 'עסק',
            'business_type' => BusinessType::Company->value,
            'email' => 'x@example.co.il',
            'phone' => '0500000000',
            'plan_id' => $plan->id,
            'terms' => '1',
        ])->assertSessionHasErrors('plan_id');

        $this->assertSame(0, Customer::count());
    }

    public function test_signup_rejects_a_honeypot_submission(): void
    {
        $plan = Plan::factory()->create(['active' => true]);

        $this->post(route('signup.store'), [
            'name' => 'בוט',
            'business_type' => BusinessType::Company->value,
            'email' => 'bot@example.co.il',
            'phone' => '0500000000',
            'plan_id' => $plan->id,
            'terms' => '1',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertSame(0, Customer::count());
    }
}
