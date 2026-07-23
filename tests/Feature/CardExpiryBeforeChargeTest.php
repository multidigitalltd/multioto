<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Jobs\AlertExpiringCardsBeforeChargeJob;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class CardExpiryBeforeChargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $waha = Mockery::mock(WahaClient::class);
        $waha->shouldReceive('sendMessage')->zeroOrMoreTimes();
        $waha->shouldReceive('normalizeChatId')->zeroOrMoreTimes()->andReturnArg(0);
        $this->app->instance(WahaClient::class, $waha);
    }

    /** A subscription whose active card expires before its next charge, due within the window. */
    private function sub(?Carbon $cardExpires, ?Carbon $nextChargeAt, array $overrides = []): Subscription
    {
        $customer = Customer::factory()->create(['email' => 'owner@biz.co.il', 'phone' => '0501234567']);

        $token = PaymentToken::factory()->create([
            'customer_id' => $customer->id,
            'status' => TokenStatus::Active,
            'expiry_month' => $cardExpires?->month,
            'expiry_year' => $cardExpires?->year,
            'card_last4' => '4242',
        ]);

        return Subscription::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create(['price_agorot' => 12000, 'vat_applies' => false])->id,
            'token_id' => $token->id,
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => $nextChargeAt,
        ], $overrides));
    }

    public function test_it_alerts_and_sends_the_customer_a_link_when_the_card_expires_before_the_charge(): void
    {
        $sub = $this->sub(now()->subMonth(), now()->addDays(3)); // card already expired, charge in 3 days

        AlertExpiringCardsBeforeChargeJob::dispatchSync();

        // Customer was invited to update their card (over whatever channels they have)...
        $this->assertGreaterThan(0, NotificationLog::where('type', NotificationType::CardLink->value)
            ->where('customer_id', $sub->customer_id)->count());
        // ...and we recorded that we warned, so we won't nag again.
        $this->assertNotNull($sub->refresh()->card_expiry_alerted_at);
    }

    public function test_it_ignores_a_card_that_is_still_valid_at_the_charge_date(): void
    {
        $sub = $this->sub(now()->addYears(2), now()->addDays(3));

        AlertExpiringCardsBeforeChargeJob::dispatchSync();

        $this->assertSame(0, NotificationLog::where('customer_id', $sub->customer_id)->count());
        $this->assertNull($sub->refresh()->card_expiry_alerted_at);
    }

    public function test_it_ignores_a_charge_beyond_the_lookahead_window(): void
    {
        // Expired card, but the charge is far away — no auto-charge is imminent.
        $sub = $this->sub(now()->subMonth(), now()->addDays(60));

        AlertExpiringCardsBeforeChargeJob::dispatchSync();

        $this->assertNull($sub->refresh()->card_expiry_alerted_at);
    }

    public function test_it_does_not_warn_twice(): void
    {
        $sub = $this->sub(now()->subMonth(), now()->addDays(3), ['card_expiry_alerted_at' => now()->subDay()]);

        AlertExpiringCardsBeforeChargeJob::dispatchSync();

        $this->assertSame(0, NotificationLog::where('customer_id', $sub->customer_id)->count());
    }

    public function test_saving_a_new_card_re_arms_the_alert(): void
    {
        $sub = $this->sub(now()->subMonth(), now()->addDays(3), ['card_expiry_alerted_at' => now()->subDay()]);

        $fresh = PaymentToken::factory()->create(['customer_id' => $sub->customer_id]);
        $sub->update(['token_id' => $fresh->id]);

        $this->assertNull($sub->refresh()->card_expiry_alerted_at);
    }

    public function test_the_token_reports_its_expiry_as_the_end_of_the_month(): void
    {
        $token = PaymentToken::factory()->create(['expiry_month' => 3, 'expiry_year' => 2027]);

        $this->assertTrue($token->expiresAt()->equalTo(Carbon::create(2027, 3, 31)->endOfMonth()));
    }
}
