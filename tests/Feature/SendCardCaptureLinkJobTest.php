<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Jobs\SendCardCaptureLinkJob;
use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\Subscription;
use App\Services\Notifications\CardCaptureLinkSender;
use App\Services\Notifications\TemplateEngine;
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

        (new SendCardCaptureLinkJob($subscription->id))->handle(new CardCaptureLinkSender($waha, app(TemplateEngine::class)));

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

        (new SendCardCaptureLinkJob($subscription->id))->handle(new CardCaptureLinkSender($waha, app(TemplateEngine::class)));

        Mail::assertSentCount(1);
    }

    public function test_it_does_not_throw_or_retry_when_the_message_is_disabled(): void
    {
        Mail::fake();
        $waha = Mockery::mock(WahaClient::class);
        $waha->shouldNotReceive('sendMessage');

        // Operator turned BOTH channels off — an intentional skip, not a failure.
        foreach (['whatsapp', 'email'] as $channel) {
            NotificationTemplate::create([
                'key' => 'card.capture', 'channel' => $channel, 'body' => 'x', 'enabled' => false,
            ]);
        }

        $customer = Customer::factory()->create(['phone' => '0501234567', 'email' => 'c@example.co.il', 'whatsapp_jid' => null]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
        ]);

        // Must NOT throw (a throw would retry the job into the failed-jobs queue).
        (new SendCardCaptureLinkJob($subscription->id))->handle(new CardCaptureLinkSender($waha, app(TemplateEngine::class)));

        Mail::assertNothingSent();
    }
}
