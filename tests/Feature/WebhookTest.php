<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Enums\WebhookSource;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\ProcessCardcomLowProfileJob;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.cardcom.webhook_secret' => 'cardcom-secret',
            'billing.waha.webhook_secret' => 'waha-secret',
        ]);
    }

    public function test_cardcom_webhook_rejects_a_bad_secret(): void
    {
        $this->post('/webhooks/cardcom?secret=wrong', ['LowProfileId' => 'lp-1'])
            ->assertForbidden();

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_webhooks_fail_closed_when_secret_is_not_configured(): void
    {
        config(['billing.cardcom.webhook_secret' => null, 'billing.waha.webhook_secret' => '']);

        $this->post('/webhooks/cardcom', ['LowProfileId' => 'lp-1'])->assertForbidden();
        $this->post('/webhooks/waha', ['event' => 'message'])->assertForbidden();

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_cardcom_webhook_is_idempotent_per_low_profile_id(): void
    {
        Queue::fake([ProcessCardcomLowProfileJob::class]);

        $payload = ['LowProfileId' => 'lp-42', 'ReturnValue' => '7'];

        $this->post('/webhooks/cardcom?secret=cardcom-secret', $payload)->assertOk();
        $this->post('/webhooks/cardcom?secret=cardcom-secret', $payload)->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        Queue::assertPushed(ProcessCardcomLowProfileJob::class, 1);
    }

    public function test_low_profile_processing_stores_token_and_retries_dunning_subscriptions(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);

        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::PastDue,
            'dunning_stage' => 2,
            'next_charge_at' => now()->addDays(3),
        ]);
        $customer = $subscription->customer;
        $oldToken = $subscription->token;

        [$event] = WebhookEvent::record(
            WebhookSource::Cardcom,
            'low_profile_completed',
            'lp-99',
            [
                'LowProfileId' => 'lp-99',
                'ReturnValue' => (string) $customer->id,
                'TokenInfo' => [
                    'Token' => 'new-token-ref',
                    'CardLast4Digits' => '4242',
                    'CardYear' => 2030,
                    'CardMonth' => 12,
                ],
            ],
        );

        (new ProcessCardcomLowProfileJob($event->id))->handle();

        $customer->refresh();
        $newToken = $customer->defaultToken;

        $this->assertSame('new-token-ref', $newToken->cardcom_token);
        $this->assertSame(TokenStatus::Replaced, $oldToken->fresh()->status);
        $this->assertSame($newToken->id, $subscription->fresh()->token_id);
        Queue::assertPushed(ChargeSubscriptionJob::class, fn ($job) => $job->subscriptionId === $subscription->id);

        // Reprocessing the same event is a no-op.
        (new ProcessCardcomLowProfileJob($event->id))->handle();
        $this->assertSame(2, $customer->paymentTokens()->count());
    }

    public function test_low_profile_activates_a_newly_onboarded_trialing_subscription(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);

        // A subscription created by the onboarding wizard: Trialing, no token,
        // waiting for the customer to enter a card via the capture link.
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'token_id' => null,
            'status' => SubscriptionStatus::Trialing,
            'next_charge_at' => now()->addDays(5),
        ]);

        [$event] = WebhookEvent::record(
            WebhookSource::Cardcom,
            'low_profile_completed',
            'lp-onboard',
            [
                'LowProfileId' => 'lp-onboard',
                'ReturnValue' => (string) $customer->id,
                'TokenInfo' => ['Token' => 'onboard-token', 'CardLast4Digits' => '1234'],
            ],
        );

        (new ProcessCardcomLowProfileJob($event->id))->handle();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($customer->fresh()->default_token_id, $subscription->token_id);
        // First charge stays on its scheduled date — activation must not charge early.
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_waha_message_creates_a_ticket_and_matches_customer_by_phone(): void
    {
        $customer = Customer::factory()->create(['phone' => '+972501234567', 'whatsapp_jid' => null]);

        $payload = [
            'event' => 'message',
            'payload' => [
                'id' => 'wa-msg-1',
                'from' => '972501234567@c.us',
                'body' => 'האתר שלי לא עולה',
            ],
        ];

        $this->post('/webhooks/waha?secret=waha-secret', $payload)->assertOk();

        $ticket = Ticket::sole();
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertSame('972501234567@c.us', $customer->fresh()->whatsapp_jid);
        $this->assertSame(1, $ticket->messages()->count());

        // Redelivery of the same message id does not duplicate anything.
        $this->post('/webhooks/waha?secret=waha-secret', $payload)->assertOk();
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_waha_message_from_unknown_sender_opens_unidentified_ticket(): void
    {
        $this->post('/webhooks/waha?secret=waha-secret', [
            'event' => 'message',
            'payload' => ['id' => 'wa-msg-2', 'from' => '15550001111@c.us', 'body' => 'hello'],
        ])->assertOk();

        $ticket = Ticket::sole();
        $this->assertNull($ticket->customer_id);
        $this->assertSame('פנייה לא מזוהה', $ticket->subject);
    }
}
