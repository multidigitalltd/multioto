<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class BillingLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_signed_card_update_link_redirects_to_cardcom(): void
    {
        $this->mock(CardcomClient::class, function ($mock) {
            $mock->shouldReceive('createTokenLowProfile')->once()
                ->andReturn(['url' => 'https://secure.cardcom.solutions/xyz', 'low_profile_id' => 'lp']);
        });

        $customer = Customer::factory()->create();

        $url = URL::temporarySignedRoute('billing.update-card', now()->addHour(), ['customer' => $customer->id]);

        $this->get($url)->assertRedirect('https://secure.cardcom.solutions/xyz');
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $customer = Customer::factory()->create();

        $url = URL::temporarySignedRoute('billing.update-card', now()->subMinute(), ['customer' => $customer->id]);

        $this->get($url)->assertForbidden();
    }

    public function test_an_unsigned_link_is_rejected(): void
    {
        $customer = Customer::factory()->create();

        // No signature at all — the signed middleware rejects it before the
        // controller runs, so no Cardcom call is ever made.
        $this->get("/billing/update-card/{$customer->id}")->assertForbidden();
    }
}
