<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Enums\WebhookSource;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\EndSiteAgentTrialsJob;
use App\Jobs\ProcessCardcomLowProfileJob;
use App\Jobs\SendSiteAgentVerificationJob;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentOrder;
use App\Models\SiteAgentRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\SiteAgentArrearsBilling;
use App\Services\SiteAgent\SiteAgentCheckout;
use App\Services\SiteAgent\SiteAgentWritingNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SiteAgentArrearsCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        $this->travelTo(now()->setTimezone('Asia/Jerusalem')->setDate(2026, 1, 31)->setTime(12, 30));
    }

    public function test_signup_captures_a_card_and_activates_service_without_charging_or_invoicing(): void
    {
        $order = $this->start();
        $this->assertSame(SiteAgentArrearsBilling::MODE, $order->billing_mode);
        $this->assertNull($order->charge_id);
        $this->assertSame(0, Subscription::count());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/LowProfile/Create')
            && $request['Operation'] === 'CreateTokenOnly');

        $this->capture($order);
        $order->refresh();
        $subscription = $order->subscription;

        $this->assertSame(SiteAgentOrder::ACTIVE, $order->status);
        $this->assertTrue($order->isFulfilled());
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(SiteAgentArrearsBilling::MODE, $subscription->billing_mode);
        $this->assertSame('2026-01-31 12:30', $subscription->billing_anchor_at->timezone('Asia/Jerusalem')->format('Y-m-d H:i'));
        $this->assertSame('2026-02-28 12:30', $subscription->next_charge_at->timezone('Asia/Jerusalem')->format('Y-m-d H:i'));
        $this->assertSame($order->customer->fresh()->default_token_id, $subscription->token_id);
        $this->assertSame('verified-token', $subscription->token->cardcom_token);
        $this->assertSame(0, Charge::count());
        $this->assertSame(0, Invoice::count());
        Queue::assertPushed(SendSiteAgentVerificationJob::class, 1);
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_the_authoritative_capture_must_identify_the_order_customer(): void
    {
        $order = $this->start();
        $other = Customer::factory()->create();

        $this->capture($order, ['ReturnValue' => (string) $other->id]);

        $this->assertFalse($order->fresh()->isFulfilled());
        $this->assertSame(0, PaymentToken::count());
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, Site::count());
    }

    public function test_a_failed_capture_does_not_activate_using_an_older_saved_card(): void
    {
        $order = $this->start();
        PaymentToken::factory()->create(['customer_id' => $order->customer_id, 'status' => TokenStatus::Active]);

        $this->capture($order, ['ResponseCode' => 2, 'TokenInfo' => []]);

        $this->assertFalse($order->fresh()->isFulfilled());
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, Charge::count());
    }

    public function test_duplicate_capture_events_do_not_create_another_subscription_or_token(): void
    {
        $order = $this->start();
        $this->capture($order);
        $this->capture($order, [], 'second-delivery');

        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, PaymentToken::count());
        Queue::assertPushed(SendSiteAgentVerificationJob::class, 1);
    }

    public function test_a_stale_card_capture_cannot_activate_service_on_a_replaced_card(): void
    {
        $order = $this->start();
        $token = PaymentToken::factory()->create(['customer_id' => $order->customer_id]);
        // Another capture replaced the card after the webhook loaded it.
        PaymentToken::whereKey($token->id)->update(['status' => TokenStatus::Replaced]);
        $this->assertSame(TokenStatus::Active, $token->status);

        try {
            app(SiteAgentCheckout::class)->fulfilCardCapture($order->cardcom_low_profile_id, $token);
            $this->fail('A replaced card activated the service.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('הכרטיס השתנה', $exception->getMessage());
        }

        $this->assertSame(SiteAgentOrder::PENDING, $order->fresh()->status);
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, Site::count());
        $this->assertSame(0, Charge::count());
    }

    public function test_trial_expiry_rejects_a_card_belonging_to_another_customer(): void
    {
        $order = $this->start(7);
        $this->capture($order);
        $subscription = $order->fresh()->subscription;
        $subscription->update(['token_id' => PaymentToken::factory()->create()->id]);
        $this->travel(8)->days();

        (new EndSiteAgentTrialsJob)->handle();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertNull($subscription->next_charge_at);
        $this->assertFalse($subscription->hasFinalArrearsDebt());
        $this->assertSame(0, Charge::count());
    }

    public function test_a_trial_reminder_names_the_later_first_collection_date(): void
    {
        $order = $this->start(7);
        $this->capture($order);
        $this->travel(6)->days();

        (new EndSiteAgentTrialsJob)->handle();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail): bool => str_contains($mail->bodyText, '07/03/2026')
            && str_contains($mail->bodyText, 'בסופו')
            && str_contains($mail->bodyText, 'חודש השירות הראשון'));
        $this->assertSame(0, Charge::count());
    }

    public function test_a_trial_draft_is_disclosed_as_free_even_when_viewed_after_the_trial(): void
    {
        $order = $this->start(7);
        $order->plan->update(['writing_price_agorot' => 1500]);
        $this->capture($order);
        $subscriber = $order->customer->siteAgentSubscribers()->sole();
        $request = SiteAgentRequest::create([
            'site_agent_subscriber_id' => $subscriber->id, 'site_id' => $subscriber->site_id, 'customer_id' => $subscriber->customer_id,
            'message' => 'כתוב פוסט', 'operation' => SiteAgentRequest::OP_POST_CREATE, 'state' => SiteAgentRequest::AWAITING,
            'plan' => ['fields' => ['content' => implode(' ', array_fill(0, 450, 'תוכן'))]],
            'preview' => 'פוסט חדש', 'expires_at' => now()->addHour(),
        ]);
        $this->assertStringContainsString('כלול בתקופת הניסיון בחינם', $request->preview);
        $this->assertStringNotContainsString('₪', $request->preview);

        $this->travel(8)->days();
        (new EndSiteAgentTrialsJob)->handle();
        $notice = app(SiteAgentWritingNotice::class)->for($request->fresh());
        $this->assertStringContainsString('הטיוטה הזו אינה מחויבת', $notice);
        $this->assertSame(0, Charge::count());
    }

    public function test_manual_card_reconciliation_activates_the_order_when_its_webhook_was_lost(): void
    {
        $order = $this->start();
        $this->actingAs(User::factory()->create());
        Http::fake(['*/LowProfile/GetLpResult' => Http::response([
            'ResponseCode' => 0, 'ReturnValue' => (string) $order->customer_id,
            'TokenInfo' => ['Token' => 'reconciled-token', 'CardMonth' => 12, 'CardYear' => 2030],
        ])]);

        Livewire::test(ViewCustomer::class, ['record' => $order->customer_id])->callAction('syncCard');

        $this->assertTrue($order->fresh()->isFulfilled());
        $this->assertSame(1, Subscription::count());
        $this->assertSame(0, Charge::count());
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_a_free_trial_is_followed_by_a_full_personal_month_before_payment(): void
    {
        $order = $this->start(7);
        $this->capture($order);
        $subscription = $order->fresh()->subscription;
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame('2026-02-07 12:30', $subscription->billing_anchor_at->timezone('Asia/Jerusalem')->format('Y-m-d H:i'));
        $this->assertSame('2026-03-07 12:30', $subscription->next_charge_at->timezone('Asia/Jerusalem')->format('Y-m-d H:i'));

        $this->travel(8)->days();
        (new EndSiteAgentTrialsJob)->handle();
        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('2026-03-07 12:30', $subscription->next_charge_at->timezone('Asia/Jerusalem')->format('Y-m-d H:i'));
        $this->assertFalse(Subscription::query()->dueForCharge()->whereKey($subscription->id)->exists());
        $this->assertSame(0, Charge::count());
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_trial_expiry_uses_the_financial_lock_and_does_not_reopen_a_canceled_trial(): void
    {
        $order = $this->start(7);
        $this->capture($order);
        $subscription = $order->fresh()->subscription;
        $this->travel(8)->days();

        $lock = Cache::lock("charge-subscription:{$subscription->id}", 300);
        $this->assertTrue($lock->get());
        (new EndSiteAgentTrialsJob)->handle();
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->fresh()->status);

        $subscription->update(['status' => SubscriptionStatus::Canceled, 'canceled_at' => now(), 'next_charge_at' => null]);
        $lock->release();
        (new EndSiteAgentTrialsJob)->handle();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertSame(0, Charge::count());
    }

    public function test_a_hosted_payment_opened_before_the_change_keeps_its_prepaid_agreement(): void
    {
        $order = $this->start();
        $charge = Charge::create([
            'customer_id' => $order->customer_id, 'status' => ChargeStatus::Pending,
            'amount_agorot' => 14900, 'vat_agorot' => 2682, 'total_agorot' => 17582,
            'attempt_number' => 1, 'period_start' => now()->toDateString(), 'period_end' => now()->addMonth()->toDateString(),
        ]);
        $order->update(['billing_mode' => null, 'charge_id' => $charge->id, 'cardcom_low_profile_id' => null]);
        $charge->update(['status' => ChargeStatus::Succeeded, 'cardcom_transaction_id' => 'legacy-paid']);
        $order->refresh();

        $this->assertSame(SiteAgentOrder::PAID, $order->status);
        $this->assertNotSame(SiteAgentArrearsBilling::MODE, $order->subscription->billing_mode);
        $this->assertTrue($order->subscription->next_charge_at->isFuture());
        $this->assertSame(1, Charge::count());
    }

    public function test_an_old_trial_order_remains_a_trial_when_its_card_returns(): void
    {
        $order = $this->start(7);
        $order->update(['billing_mode' => null]);
        $this->capture($order);

        $subscription = $order->fresh()->subscription;
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->next_charge_at->equalTo($subscription->trial_ends_at));
        $this->assertNotSame(SiteAgentArrearsBilling::MODE, $subscription->billing_mode);
    }

    private function start(int $trialDays = 0): SiteAgentOrder
    {
        Http::fake(['*/LowProfile/Create' => Http::response([
            'ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/card/capture', 'LowProfileId' => 'lp-arrears',
        ])]);
        $plan = Plan::factory()->create([
            'active' => true, 'is_public' => true, 'includes_site_agent' => true,
            'billing_interval' => 'monthly', 'trial_days' => $trialDays,
        ]);

        return app(SiteAgentCheckout::class)->start($plan, [
            'name' => 'לקוח חדש', 'email' => 'new@example.test', 'phone' => '0501234567',
            'domain' => 'new.example.test', 'install_mode' => 'self',
        ])['order'];
    }

    private function capture(SiteAgentOrder $order, array $result = [], string $eventId = 'capture'): void
    {
        Http::fake(['*/LowProfile/GetLpResult' => Http::response(array_replace([
            'ResponseCode' => 0, 'ReturnValue' => (string) $order->customer_id,
            'TokenInfo' => ['Token' => 'verified-token', 'CardMonth' => 12, 'CardYear' => 2030],
        ], $result))]);
        $event = WebhookEvent::record(WebhookSource::Cardcom, 'low_profile', $eventId, [
            'LowProfileId' => $order->cardcom_low_profile_id,
            // A notification's token cannot bypass GetLpResult verification.
            'TokenInfo' => ['Token' => 'unverified-notification-token'],
        ])[0];
        (new ProcessCardcomLowProfileJob($event->id))->handle();
    }
}
