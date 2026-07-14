<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Cardcom\CardcomClient;
use App\Support\CardLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class BillingLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_signed_card_update_link_embeds_the_cardcom_iframe(): void
    {
        $this->mock(CardcomClient::class, function ($mock) {
            $mock->shouldReceive('createTokenLowProfile')->once()
                ->andReturn(['url' => 'https://secure.cardcom.solutions/xyz', 'low_profile_id' => 'lp']);
        });

        $customer = Customer::factory()->create();

        // The card page is embedded in an iframe on our own page (the customer
        // never leaves our site); the Cardcom URL is the iframe src.
        $this->get(CardLink::for($customer->id))
            ->assertOk()
            ->assertSee('<iframe', false)
            ->assertSee('https://secure.cardcom.solutions/xyz', false);
    }

    public function test_a_revoked_card_link_shows_an_inactive_page(): void
    {
        // Cardcom must NEVER be reached for a canceled link.
        $this->mock(CardcomClient::class, fn ($mock) => $mock->shouldNotReceive('createTokenLowProfile'));

        $customer = Customer::factory()->create();
        $link = CardLink::for($customer->id);

        // The team cancels outstanding card links (rotates the nonce).
        $customer->revokeCardLinks();

        $this->get($link)
            ->assertOk()
            ->assertDontSee('<iframe', false)
            ->assertSee('הקישור אינו פעיל');
    }

    public function test_a_cardcom_failure_shows_a_friendly_message_not_a_broken_frame(): void
    {
        // Cardcom rejected the request → no URL. The customer must NOT be framing
        // a broken/404 page; they get a clear "try again / contact us" message.
        $this->mock(CardcomClient::class, function ($mock) {
            $mock->shouldReceive('createTokenLowProfile')->once()
                ->andReturn(['url' => '', 'low_profile_id' => '']);
        });

        $customer = Customer::factory()->create();
        $url = URL::temporarySignedRoute('billing.update-card', now()->addHour(), ['customer' => $customer->id]);

        $this->get($url)
            ->assertOk()
            ->assertDontSee('<iframe', false)
            ->assertSee('לא ניתן לטעון כרגע את טופס הכרטיס');
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
