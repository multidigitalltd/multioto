<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Models\Customer;
use App\Support\PaymentLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function demand(ChargeStatus $status, ?string $payUrl = 'https://secure.cardcom.test/lp/ABC', ?string $bitUrl = 'https://secure.cardcom.test/bit/ABC'): Charge
    {
        $customer = Customer::factory()->create();

        return Charge::create([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => $status,
            'attempt_number' => 1,
            'description' => 'דרישת תשלום',
            'cardcom_pay_url' => $payUrl,
            'cardcom_bit_url' => $bitUrl,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);
    }

    public function test_a_payable_demand_redirects_to_the_bit_page(): void
    {
        $charge = $this->demand(ChargeStatus::Pending);

        $this->get(PaymentLink::bitFor($charge->id))
            ->assertRedirect('https://secure.cardcom.test/bit/ABC');
    }

    public function test_a_canceled_demand_bit_link_shows_inactive_and_does_not_redirect(): void
    {
        $charge = $this->demand(ChargeStatus::Canceled);

        $response = $this->get(PaymentLink::bitFor($charge->id));

        $response->assertOk();
        $response->assertSee('אינו פעיל');
        $response->assertDontSee('secure.cardcom.test');
    }

    public function test_the_bit_gateway_rejects_an_unsigned_url(): void
    {
        $charge = $this->demand(ChargeStatus::Pending);

        $this->get("/billing/pay/{$charge->id}/bit")->assertForbidden();
    }

    public function test_a_payable_demand_redirects_to_the_cardcom_page(): void
    {
        $charge = $this->demand(ChargeStatus::Pending);

        $this->get(PaymentLink::for($charge->id))
            ->assertRedirect('https://secure.cardcom.test/lp/ABC');
    }

    public function test_a_canceled_demand_shows_an_inactive_page_and_does_not_redirect(): void
    {
        $charge = $this->demand(ChargeStatus::Canceled);

        $response = $this->get(PaymentLink::for($charge->id));

        $response->assertOk();
        $response->assertSee('אינו פעיל');
        $response->assertDontSee('secure.cardcom.test');
    }

    public function test_a_paid_demand_says_it_was_already_paid(): void
    {
        $charge = $this->demand(ChargeStatus::Succeeded);

        $response = $this->get(PaymentLink::for($charge->id));

        $response->assertOk();
        $response->assertSee('כבר בוצע');
    }

    public function test_the_gateway_rejects_an_unsigned_url(): void
    {
        $charge = $this->demand(ChargeStatus::Pending);

        // Same path, no signature → forbidden (no charge-id enumeration).
        $this->get("/billing/pay/{$charge->id}")->assertForbidden();
    }
}
