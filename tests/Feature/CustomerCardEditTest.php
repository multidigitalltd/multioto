<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A new card can be added straight from the customer EDIT screen, using the
 * same secure Cardcom card-capture flow as the customer 360° page.
 */
class CustomerCardEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_edit_screen_offers_the_card_capture_actions(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertOk()
            ->assertActionExists('cardLink')
            ->assertActionExists('syncCard');
    }
}
