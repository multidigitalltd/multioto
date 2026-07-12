<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignatureRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_team_member_can_view_a_stored_signature(): void
    {
        Storage::fake('local');
        $customer = Customer::factory()->create(['signature_path' => 'signatures/2026/07/sig.png']);
        Storage::disk('local')->put($customer->signature_path, 'PNGDATA');

        $this->actingAs(User::factory()->create());

        $this->get(route('customer.signature', $customer))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_the_signature_route_requires_authentication(): void
    {
        $customer = Customer::factory()->create(['signature_path' => 'signatures/x.png']);

        $this->get(route('customer.signature', $customer))->assertRedirect();
    }

    public function test_a_missing_signature_returns_not_found(): void
    {
        $customer = Customer::factory()->create(['signature_path' => null]);

        $this->actingAs(User::factory()->create());

        $this->get(route('customer.signature', $customer))->assertNotFound();
    }
}
