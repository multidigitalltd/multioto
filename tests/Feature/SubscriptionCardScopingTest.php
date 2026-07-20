<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\SubscriptionResource\Pages\CreateSubscription;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A subscription's card and site must belong to ITS customer — you can never
 * attach (and therefore charge) another customer's card, or link another
 * customer's site.
 */
class SubscriptionCardScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_the_selected_customers_cards_and_sites(): void
    {
        $this->actingAs(User::factory()->create());

        $ours = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        $ourCard = PaymentToken::factory()->create(['customer_id' => $ours->id, 'card_last4' => '4242']);
        $theirCard = PaymentToken::factory()->create(['customer_id' => $theirs->id, 'card_last4' => '9999']);
        $ourSite = Site::factory()->create(['customer_id' => $ours->id, 'domain' => 'ours.co.il']);
        $theirSite = Site::factory()->create(['customer_id' => $theirs->id, 'domain' => 'theirs.co.il']);

        Livewire::test(CreateSubscription::class)
            ->fillForm(['customer_id' => $ours->id])
            ->assertFormFieldExists('token_id', fn ($field): bool => array_key_exists($ourCard->id, $field->getOptions())
                && ! array_key_exists($theirCard->id, $field->getOptions()))
            ->assertFormFieldExists('site_id', fn ($field): bool => array_key_exists($ourSite->id, $field->getOptions())
                && ! array_key_exists($theirSite->id, $field->getOptions()));
    }

    public function test_it_rejects_another_customers_card_and_site_on_submit(): void
    {
        $this->actingAs(User::factory()->create());

        $ours = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        $theirCard = PaymentToken::factory()->create(['customer_id' => $theirs->id]);
        $theirSite = Site::factory()->create(['customer_id' => $theirs->id]);

        // A crafted submit that pairs our customer with someone else's card/site
        // must fail validation — the server doesn't trust the client's options.
        Livewire::test(CreateSubscription::class)
            ->fillForm([
                'customer_id' => $ours->id,
                'token_id' => $theirCard->id,
                'site_id' => $theirSite->id,
                'status' => SubscriptionStatus::Active->value,
                'name' => 'מנוי',
                'price_agorot_override' => 10000,
                'billing_interval' => 'monthly',
                'dunning_stage' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['token_id', 'site_id']);

        $this->assertSame(0, Subscription::count());
    }
}
