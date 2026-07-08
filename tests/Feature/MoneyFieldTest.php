<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Filament\Resources\SubscriptionResource\Pages\EditSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MoneyFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_shekels_entered_in_the_form_are_stored_as_integer_agorot(): void
    {
        Livewire::test(CreatePlan::class)
            ->fillForm([
                'name' => 'מסלול',
                'price_agorot' => '1.90', // ₪1.90
                'vat_applies' => true,
                'billing_interval' => 'monthly',
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // 1.90 ₪ → 190 agorot, stored as an integer.
        $this->assertSame(190, Plan::first()->price_agorot);
    }

    public function test_half_shekel_rounds_to_the_correct_agorot(): void
    {
        Livewire::test(CreatePlan::class)
            ->fillForm([
                'name' => 'חצי שקל',
                'price_agorot' => '1.50',
                'vat_applies' => false,
                'billing_interval' => 'monthly',
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(150, Plan::first()->price_agorot);
    }

    public function test_stored_agorot_are_shown_back_as_shekels_when_editing(): void
    {
        $plan = Plan::factory()->create(['price_agorot' => 12345]); // ₪123.45

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->assertFormSet(['price_agorot' => '123.45']);
    }

    public function test_subscription_override_round_trips_through_shekels(): void
    {
        $sub = Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
            'price_agorot_override' => null,
        ]);

        Livewire::test(EditSubscription::class, ['record' => $sub->getRouteKey()])
            ->fillForm(['price_agorot_override' => '49.90'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(4990, $sub->fresh()->price_agorot_override);
    }
}
