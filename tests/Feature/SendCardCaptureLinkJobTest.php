<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Jobs\SendCardCaptureLinkJob;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\Notifications\CardCaptureLinkSender;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class SendCardCaptureLinkJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_send_a_card_link_for_a_canceled_subscription(): void
    {
        Mail::fake();
        $waha = Mockery::mock(WahaClient::class);
        $waha->shouldNotReceive('sendMessage');

        $customer = Customer::factory()->create(['phone' => '0501234567', 'email' => 'c@example.co.il']);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Canceled,
        ]);

        (new SendCardCaptureLinkJob($subscription->id))->handle(new CardCaptureLinkSender($waha));

        Mail::assertNothingSent();
    }

    public function test_it_sends_a_card_link_for_an_active_subscription(): void
    {
        Mail::fake();
        $waha = Mockery::mock(WahaClient::class);
        $waha->shouldReceive('sendMessage')->once();

        $customer = Customer::factory()->create(['phone' => '0501234567', 'email' => 'c@example.co.il', 'whatsapp_jid' => null]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
        ]);

        (new SendCardCaptureLinkJob($subscription->id))->handle(new CardCaptureLinkSender($waha));

        Mail::assertSentCount(1);
    }
}
