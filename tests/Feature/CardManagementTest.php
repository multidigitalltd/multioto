<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Enums\WebhookSource;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\PaymentTokensRelationManager;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\ProcessCardcomLowProfileJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Cardcom\CardTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Choosing which saved card is charged, and taking one off the file.
 *
 * The bug behind these tests: a customer replaced an expired card and kept
 * failing, because a card could be saved without ever being wired to anything.
 */
class CardManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_making_a_card_default_repoints_the_customer_and_their_subscriptions(): void
    {
        $customer = Customer::factory()->create();
        $old = PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Active]);
        $new = PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Replaced]);
        $customer->update(['default_token_id' => $old->id]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
            'token_id' => $old->id,
        ]);

        app(CardTokenService::class)->makeDefault($customer, $new, collectNow: false);

        $this->assertSame($new->id, $customer->fresh()->default_token_id);
        $this->assertSame($new->id, $subscription->fresh()->token_id);
        $this->assertSame(TokenStatus::Active, $new->fresh()->status);
        // Only one card may be active at a time, or "which card is charged?"
        // has no answer.
        $this->assertSame(TokenStatus::Replaced, $old->fresh()->status);
    }

    public function test_choosing_a_card_by_hand_does_not_end_a_trial_or_take_money(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);
        $customer = Customer::factory()->create();
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $trial = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Trialing,
            'token_id' => null,
        ]);
        $overdue = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::PastDue,
            'token_id' => null,
            'next_charge_at' => now()->subWeek(),
        ]);

        app(CardTokenService::class)->makeDefault($customer, $token, collectNow: false);

        // Both are wired to the card, but re-pointing a card is bookkeeping:
        // it must not convert a trial or charge anybody on its own.
        $this->assertSame($token->id, $trial->fresh()->token_id);
        $this->assertSame(SubscriptionStatus::Trialing, $trial->fresh()->status);
        $this->assertSame($token->id, $overdue->fresh()->token_id);
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_a_card_capture_still_collects_the_debt(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::PastDue,
            'token_id' => null,
            'next_charge_at' => now()->subWeek(),
        ]);

        app(CardTokenService::class)->store($customer, ['Token' => 'tok-capture', 'CardLast4Digits' => '1234']);

        Queue::assertPushed(ChargeSubscriptionJob::class, fn ($job) => $job->subscriptionId === $subscription->id);
    }

    public function test_a_card_cannot_be_wired_to_a_customer_it_does_not_belong_to(): void
    {
        $customer = Customer::factory()->create();
        $stranger = PaymentToken::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $this->expectException(InvalidArgumentException::class);

        app(CardTokenService::class)->makeDefault($customer, $stranger);
    }

    public function test_removing_a_card_unwires_it_and_keeps_the_row(): void
    {
        $customer = Customer::factory()->create();
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $customer->update(['default_token_id' => $token->id]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
            'token_id' => $token->id,
        ]);

        app(CardTokenService::class)->detach($customer, $token);

        $this->assertSame(TokenStatus::Removed, $token->fresh()->status);
        $this->assertNull($customer->fresh()->default_token_id);
        $this->assertNull($subscription->fresh()->token_id);
        // The row survives: charges collected on this card still point at it.
        $this->assertDatabaseHas('payment_tokens', ['id' => $token->id]);
    }

    public function test_removing_a_card_promotes_nothing_in_its_place(): void
    {
        $customer = Customer::factory()->create();
        $older = PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Replaced]);
        $current = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $customer->update(['default_token_id' => $current->id]);

        app(CardTokenService::class)->detach($customer, $current);

        // Silently activating an older card would charge a card nobody chose.
        $this->assertNull($customer->fresh()->default_token_id);
        $this->assertSame(TokenStatus::Replaced, $older->fresh()->status);
    }

    public function test_the_panel_switches_the_active_card(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        $old = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $new = PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Replaced]);
        $customer->update(['default_token_id' => $old->id]);

        Livewire::test(PaymentTokensRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])->callTableAction('makeDefault', $new);

        $this->assertSame($new->id, $customer->fresh()->default_token_id);
    }

    public function test_the_panel_removes_a_card(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $customer->update(['default_token_id' => $token->id]);

        Livewire::test(PaymentTokensRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])->callTableAction('detach', $token);

        $this->assertSame(TokenStatus::Removed, $token->fresh()->status);
        $this->assertNull($customer->fresh()->default_token_id);
    }

    public function test_a_card_paid_with_at_the_hosted_page_becomes_the_card_on_file(): void
    {
        Queue::fake([ChargeSubscriptionJob::class, IssueInvoiceJob::class]);
        $customer = Customer::factory()->create();
        $expired = PaymentToken::factory()->create([
            'customer_id' => $customer->id,
            'expiry_month' => 1,
            'expiry_year' => (int) now()->subYear()->format('Y'),
        ]);
        $customer->update(['default_token_id' => $expired->id]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::PastDue,
            'token_id' => $expired->id,
            'next_charge_at' => now()->subWeek(),
        ]);
        $charge = Charge::create([
            'customer_id' => $customer->id,
            'amount_agorot' => 20000, 'vat_agorot' => 3600, 'total_agorot' => 23600,
            'status' => ChargeStatus::Pending, 'attempt_number' => 1,
            'description' => 'תשלום חד-פעמי',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'cardcom_low_profile_id' => 'lp-walkin',
        ]);

        Http::fake(['*/LowProfile/GetLpResult' => Http::response([
            'ResponseCode' => 0,
            'TokenInfo' => ['Token' => 'tok-walkin', 'CardMonth' => 12, 'CardYear' => 2030],
            'TranzactionInfo' => ['Last4CardDigits' => 4580, 'CardName' => 'ויזה'],
        ])]);

        $event = WebhookEvent::record(WebhookSource::Cardcom, 'low_profile', 'lp-walkin', ['LowProfileId' => 'lp-walkin'])[0];
        (new ProcessCardcomLowProfileJob($event->id))->handle();

        $fresh = $customer->fresh();
        $saved = $fresh->defaultToken;

        // The card the customer just paid with is the card on file — before
        // this, the new card was saved and everything kept charging the old one.
        $this->assertNotNull($saved);
        $this->assertSame('4580', $saved->card_last4);
        $this->assertSame($saved->id, $subscription->fresh()->token_id);
        $this->assertSame(TokenStatus::Replaced, $expired->fresh()->status);
        $this->assertSame(ChargeStatus::Succeeded, $charge->fresh()->status);
        // The customer came to settle one charge, not to be billed for a
        // subscription debt in the same breath.
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_a_two_digit_expiry_year_is_not_read_as_the_year_27(): void
    {
        $token = PaymentToken::factory()->make(['expiry_month' => 12, 'expiry_year' => 30]);

        $this->assertSame(2030, $token->expiryYear());
        $this->assertFalse($token->hasExpired());
        $this->assertSame('12/30', $token->expiryLabel());
    }

    public function test_an_unknown_expiry_is_not_treated_as_expired(): void
    {
        $token = PaymentToken::factory()->make(['expiry_month' => null, 'expiry_year' => null]);

        $this->assertFalse($token->hasExpired());
        $this->assertNull($token->expiryLabel());
    }

    public function test_the_diagnostic_names_a_subscription_left_on_the_old_card(): void
    {
        $customer = Customer::factory()->create(['name' => 'בדיקת כרטיסים']);
        $old = PaymentToken::factory()->create(['customer_id' => $customer->id, 'status' => TokenStatus::Replaced]);
        $new = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $customer->update(['default_token_id' => $new->id]);
        Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
            'token_id' => $old->id,
        ]);

        $this->artisan('cardcom:cards', ['customer' => (string) $customer->id])
            ->expectsOutputToContain('מצביע על כרטיס אחר')
            ->assertSuccessful();
    }
}
