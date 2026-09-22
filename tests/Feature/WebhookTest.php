<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\MessageDirection;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Enums\WebhookSource;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\ProcessCardcomLowProfileJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\WebhookEvent;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
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

    /**
     * הלקוח שילם בעמוד המתארח אחרי שהחוב כבר נסגר.
     *
     * לקארדקום אין דרך לבטל סשן תשלום, ולכן עמוד שהלקוח כבר פתח נשאר לתשלום גם
     * אחרי שהצוות חייב מהכרטיס השמור או סימן את הדרישה כשולמה. עד עכשיו סינן
     * את זה תנאי "רק ממתין", כלומר הכסף נלקח, לא נרשם בשום מקום, והתגלה רק אם
     * מישהו קרא את הדוח של קארדקום.
     */
    public function test_a_hosted_page_paid_after_the_debt_was_settled_is_reported_not_swallowed(): void
    {
        $notifier = Mockery::mock(TeamNotifier::class);
        $notifier->shouldReceive('alert')->once()->withArgs(
            fn (string $title, string $body): bool => str_contains($title, 'תשלום כפול')
                && str_contains($body, '777777')
        );
        $this->instance(TeamNotifier::class, $notifier);

        $customer = Customer::factory()->create();
        $charge = Charge::create([
            'customer_id' => $customer->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            // Already settled — by the saved card, or by "סמן כשולם".
            'status' => ChargeStatus::Succeeded,
            'attempt_number' => 1,
            'description' => 'דרישה',
            'cardcom_low_profile_id' => 'lp-dup',
            'cardcom_transaction_id' => '111111',
            'charged_at' => now(),
            'demand_sent_at' => now(),
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        // Cardcom reports a DIFFERENT, successful transaction on that session.
        Http::fake(['*/LowProfile/GetLpResult' => Http::response([
            'ResponseCode' => 0, 'TranzactionId' => 777777,
        ])]);

        [$event] = WebhookEvent::record(
            WebhookSource::Cardcom,
            'low_profile_completed',
            'lp-dup',
            ['LowProfileId' => 'lp-dup'],
        );

        (new ProcessCardcomLowProfileJob($event->id))->handle();

        // The row keeps the transaction the invoice was issued against — the
        // duplicate is reported, never written over the payment that settled it.
        $charge->refresh();
        $this->assertSame('111111', $charge->cardcom_transaction_id);
        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertNotNull($event->fresh()->processed_at);
    }

    /** אותה עסקה שנשלחה שוב היא מסירה חוזרת, לא תשלום כפול. */
    public function test_a_redelivered_webhook_for_the_same_transaction_is_not_called_a_duplicate(): void
    {
        $notifier = Mockery::mock(TeamNotifier::class);
        $notifier->shouldNotReceive('alert');
        $this->instance(TeamNotifier::class, $notifier);

        $customer = Customer::factory()->create();
        Charge::create([
            'customer_id' => $customer->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => ChargeStatus::Succeeded,
            'attempt_number' => 1,
            'description' => 'דרישה',
            'cardcom_low_profile_id' => 'lp-same',
            'cardcom_transaction_id' => '222222',
            'charged_at' => now(),
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ]);

        Http::fake(['*/LowProfile/GetLpResult' => Http::response([
            'ResponseCode' => 0, 'TranzactionId' => 222222,
        ])]);

        [$event] = WebhookEvent::record(
            WebhookSource::Cardcom,
            'low_profile_completed',
            'lp-same',
            ['LowProfileId' => 'lp-same'],
        );

        (new ProcessCardcomLowProfileJob($event->id))->handle();

        $this->assertNotNull($event->fresh()->processed_at);
    }

    public function test_low_profile_token_capture_fetches_the_token_via_getlpresult_when_webhook_is_minimal(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);
        config(['billing.cardcom.base_url' => 'https://secure.cardcom.test/api/v11/']);

        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'token_id' => null,
            'status' => SubscriptionStatus::Trialing,
            'next_charge_at' => now()->addDays(5),
        ]);

        // Cardcom's webhook body is minimal (no TokenInfo) — the token must be
        // fetched from the authoritative GetLpResult.
        Http::fake(['*/LowProfile/GetLpResult' => Http::response([
            'ResponseCode' => 0,
            'ReturnValue' => (string) $customer->id,
            'TokenInfo' => ['Token' => 'fetched-token', 'CardLast4Digits' => '9999', 'CardYear' => 2031, 'CardMonth' => 6],
        ])]);

        [$event] = WebhookEvent::record(
            WebhookSource::Cardcom,
            'low_profile_completed',
            'lp-min',
            ['LowProfileId' => 'lp-min'],
        );

        (new ProcessCardcomLowProfileJob($event->id))->handle();

        $this->assertSame('fetched-token', $customer->fresh()->defaultToken?->cardcom_token);
        $this->assertSame($customer->fresh()->default_token_id, $subscription->fresh()->token_id);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/LowProfile/GetLpResult'));
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

    public function test_low_profile_charges_a_self_signup_subscription_immediately_when_due(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);

        // Self-signup: Trialing, no token, first charge already due (now).
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'token_id' => null,
            'status' => SubscriptionStatus::Trialing,
            'next_charge_at' => now()->subMinute(),
        ]);

        [$event] = WebhookEvent::record(
            WebhookSource::Cardcom,
            'low_profile_completed',
            'lp-signup',
            [
                'LowProfileId' => 'lp-signup',
                'ReturnValue' => (string) $customer->id,
                'TokenInfo' => ['Token' => 'signup-token', 'CardLast4Digits' => '4321'],
            ],
        );

        (new ProcessCardcomLowProfileJob($event->id))->handle();

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        Queue::assertPushed(ChargeSubscriptionJob::class, fn ($job) => $job->subscriptionId === $subscription->id);
    }

    public function test_waha_message_creates_a_ticket_and_matches_customer_by_phone(): void
    {
        // A new ticket triggers the automatic WhatsApp acknowledgement.
        Http::fake(['*/api/sendText' => Http::response(['id' => 'ack-1'])]);
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
        // The inbound message plus the automatic acknowledgement.
        $this->assertSame(1, $ticket->messages()->where('direction', MessageDirection::Inbound)->count());

        // Redelivery of the same message id does not duplicate anything.
        $messageCount = $ticket->messages()->count();
        $this->post('/webhooks/waha?secret=waha-secret', $payload)->assertOk();
        $this->assertSame(1, Ticket::count());
        $this->assertSame($messageCount, $ticket->messages()->count());
    }

    public function test_waha_message_from_unknown_sender_opens_unidentified_ticket(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['id' => 'ack-2'])]);

        $this->post('/webhooks/waha?secret=waha-secret', [
            'event' => 'message',
            'payload' => ['id' => 'wa-msg-2', 'from' => '15550001111@c.us', 'body' => 'hello'],
        ])->assertOk();

        $ticket = Ticket::sole();
        $this->assertNull($ticket->customer_id);
        $this->assertSame('פנייה לא מזוהה', $ticket->subject);
    }
}
