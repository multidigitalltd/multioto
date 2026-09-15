<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use App\Support\CardLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * כרטיס ביטחון — a card from every customer, charged only when the payment they
 * actually agreed to does not arrive.
 *
 * The line these tests defend is between the two: a customer on a standing
 * order gives a card and is still not charged on it. Taking money from somebody
 * who arranged to pay another way, on the day they expected to be left alone,
 * is the failure the whole mechanism has to avoid while still existing.
 */
class SecurityCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_manually_collected_subscription_carries_the_standing_allowance(): void
    {
        config(['billing.card_fallback_days' => 30]);

        $customer = Customer::factory()->create(['payment_method' => 'bank_transfer']);
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);

        $this->assertSame(30, $subscription->fresh()->card_fallback_days);
    }

    public function test_a_card_subscription_gets_no_fallback_because_it_is_simply_charged(): void
    {
        config(['billing.card_fallback_days' => 30]);

        $customer = Customer::factory()->create(['payment_method' => 'credit_card']);
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);

        // Nothing to fall back FROM: the card is the agreed method.
        $this->assertNull($subscription->fresh()->card_fallback_days);
    }

    public function test_an_explicit_allowance_is_never_overwritten(): void
    {
        config(['billing.card_fallback_days' => 30]);

        $customer = Customer::factory()->create(['payment_method' => 'bank_transfer']);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'card_fallback_days' => 90,
        ]);

        $this->assertSame(90, $subscription->fresh()->card_fallback_days);
    }

    public function test_subscriptions_that_already_exist_are_left_exactly_as_they_are(): void
    {
        // Signed up under the earlier arrangement, with no fallback.
        config(['billing.card_fallback_days' => 0]);
        $customer = Customer::factory()->create(['payment_method' => 'standing_order']);
        $existing = Subscription::factory()->create(['customer_id' => $customer->id]);

        $this->assertNull($existing->fresh()->card_fallback_days);

        // The policy is turned on today. A customer who agreed to something else
        // months ago did not agree to this, and a saved row must not acquire it
        // by having the config change underneath them.
        config(['billing.card_fallback_days' => 30]);
        $existing->update(['status' => SubscriptionStatus::PastDue]);

        $this->assertNull($existing->fresh()->card_fallback_days);
        $this->assertSame(0, Subscription::query()->dueForCardFallback()->count());
    }

    public function test_the_allowance_can_be_switched_off_for_new_subscriptions_too(): void
    {
        config(['billing.card_fallback_days' => 0]);

        $customer = Customer::factory()->create(['payment_method' => 'bank_transfer']);

        $this->assertNull(Subscription::factory()->create(['customer_id' => $customer->id])->fresh()->card_fallback_days);
    }

    public function test_the_card_page_tells_a_transfer_customer_it_is_security_and_shows_their_details(): void
    {
        config([
            'billing.card_fallback_days' => 30,
            'billing.signup.instructions.bank_transfer' => "בנק 12 סניף 345\nחשבון 67890",
        ]);
        Http::fake(['*' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/x', 'LowProfileId' => 'lp-1'])]);

        $customer = Customer::factory()->create(['payment_method' => 'bank_transfer']);

        $response = $this->get(CardLink::for($customer->id));

        $response->assertOk();
        // Said plainly, because the customer chose NOT to pay by card.
        $response->assertSee('לביטחון', false);
        $response->assertSee('אינו מחויב באופן שוטף', false);
        $response->assertSee('30', false);
        // And what they actually came for is not lost behind the card form.
        $response->assertSee('סניף 345', false);
    }

    public function test_a_card_customer_sees_the_ordinary_card_page(): void
    {
        Http::fake(['*' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/x', 'LowProfileId' => 'lp-2'])]);

        $customer = Customer::factory()->create(['payment_method' => 'credit_card']);

        $this->get(CardLink::for($customer->id))
            ->assertOk()
            ->assertSee('הזנת פרטי כרטיס אשראי', false)
            ->assertDontSee('אינו מחויב באופן שוטף', false);
    }

    public function test_the_signup_form_states_the_arrangement_before_it_is_agreed_to(): void
    {
        config(['billing.card_fallback_days' => 30]);

        $response = $this->get(route('signup'));

        // Discovering on the card page that a card is required — after choosing
        // a standing order — is finding out afterwards. It belongs next to the
        // choice, and in the terms the customer ticks.
        $response->assertSee('נדרש בכל אמצעי תשלום', false);
        $response->assertSee('משמש כביטחון', false);
        $response->assertSee('30 יום', false);
    }
}
