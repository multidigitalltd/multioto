<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Subscription;
use App\Services\Cardcom\CardTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Paying one subscription by transfer while another goes on the card — and the
 * card standing behind the transfer if it never arrives.
 *
 * Everything here is about which subscriptions the scheduler is allowed to
 * charge. The failure that matters is taking money a customer arranged to send
 * another way, so most of these assert that a charge does NOT happen.
 */
class SubscriptionPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private function customerWithCard(string $method = 'credit_card'): Customer
    {
        $customer = Customer::factory()->create(['payment_method' => $method]);
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $customer->update(['default_token_id' => $token->id]);

        return $customer->fresh();
    }

    private function subscription(Customer $customer, array $attributes = []): Subscription
    {
        return Subscription::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'token_id' => $customer->default_token_id,
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->subDay(),
        ], $attributes));
    }

    public function test_a_subscription_paid_by_transfer_is_not_charged_though_a_card_is_on_file(): void
    {
        $customer = $this->customerWithCard();
        $onCard = $this->subscription($customer);
        $byTransfer = $this->subscription($customer, ['payment_method' => 'bank_transfer']);

        $due = Subscription::query()->dueForCharge()->pluck('id');

        // The card is saved and wired, and that used to be the entire test for
        // "charge it". The subscription's own arrangement now decides.
        $this->assertTrue($due->contains($onCard->id));
        $this->assertFalse($due->contains($byTransfer->id));
    }

    public function test_a_subscription_can_be_put_on_the_card_against_the_customer_default(): void
    {
        // The customer pays by standing order — except for this one.
        $customer = $this->customerWithCard('standing_order');
        $onCard = $this->subscription($customer, ['payment_method' => 'credit_card']);
        $inherits = $this->subscription($customer);

        $due = Subscription::query()->dueForCharge()->pluck('id');

        $this->assertTrue($due->contains($onCard->id));
        $this->assertFalse($due->contains($inherits->id));
    }

    public function test_a_subscription_with_no_setting_follows_the_customer(): void
    {
        $onCard = $this->subscription($this->customerWithCard('credit_card'));
        $byTransfer = $this->subscription($this->customerWithCard('bank_transfer'));

        $this->assertFalse($onCard->isManuallyCollected());
        $this->assertTrue($byTransfer->isManuallyCollected());

        // And a customer with nothing set at all is on a card: the default
        // arrangement, not limbo.
        $blank = $this->subscription($this->customerWithCard(''));
        $blank->customer->update(['payment_method' => null]);
        $this->assertFalse($blank->fresh()->isManuallyCollected());
        $this->assertTrue(Subscription::query()->dueForCharge()->pluck('id')->contains($blank->id));
    }

    public function test_a_transfer_subscription_appears_on_the_manual_list_even_with_a_card(): void
    {
        $customer = $this->customerWithCard();
        $byTransfer = $this->subscription($customer, ['payment_method' => 'bank_transfer']);

        // It used to take "no token" to reach this list, so a fallback card
        // would have removed the collection from the team's work list entirely.
        $this->assertTrue(Subscription::query()->dueForManualCollection()->pluck('id')->contains($byTransfer->id));
        $this->assertFalse($byTransfer->collectsAutomatically());
    }

    public function test_the_card_is_charged_once_the_grace_period_has_passed(): void
    {
        $customer = $this->customerWithCard();

        $waiting = $this->subscription($customer, [
            'payment_method' => 'bank_transfer',
            'card_fallback_days' => 7,
            'next_charge_at' => now()->subDays(3),   // late, but inside its grace
        ]);
        $overdue = $this->subscription($customer, [
            'payment_method' => 'bank_transfer',
            'card_fallback_days' => 7,
            'next_charge_at' => now()->subDays(9),   // past it
        ]);

        $fallback = Subscription::query()->dueForCardFallback()->pluck('id');

        $this->assertFalse($fallback->contains($waiting->id));
        $this->assertTrue($fallback->contains($overdue->id));
        // And neither is on the ordinary run at any point.
        $this->assertFalse(Subscription::query()->dueForCharge()->pluck('id')->contains($overdue->id));
    }

    public function test_without_a_fallback_allowance_the_card_is_never_charged(): void
    {
        $customer = $this->customerWithCard();

        // Same subscription, a year overdue, no allowance set. The fallback is
        // opt-in: a card nobody nominated as backup stays unused.
        $this->subscription($customer, [
            'payment_method' => 'bank_transfer',
            'card_fallback_days' => null,
            'next_charge_at' => now()->subYear(),
        ]);

        $this->assertSame(0, Subscription::query()->dueForCardFallback()->count());
    }

    public function test_each_subscription_is_late_by_its_own_allowance(): void
    {
        $customer = $this->customerWithCard();

        $patient = $this->subscription($customer, [
            'payment_method' => 'standing_order',
            'card_fallback_days' => 30,
            'next_charge_at' => now()->subDays(10),
        ]);
        $strict = $this->subscription($customer, [
            'payment_method' => 'standing_order',
            'card_fallback_days' => 5,
            'next_charge_at' => now()->subDays(10),
        ]);

        $fallback = Subscription::query()->dueForCardFallback()->pluck('id');

        $this->assertFalse($fallback->contains($patient->id));
        $this->assertTrue($fallback->contains($strict->id));
    }

    public function test_a_recorded_payment_takes_the_subscription_out_of_the_fallback(): void
    {
        $customer = $this->customerWithCard();
        $subscription = $this->subscription($customer, [
            'payment_method' => 'bank_transfer',
            'card_fallback_days' => 7,
            'next_charge_at' => now()->subDays(9),
        ]);

        $this->assertSame(1, Subscription::query()->dueForCardFallback()->count());

        // Marking it paid rolls the period forward. That is the whole safety
        // property: a transfer that arrived and was written down can never be
        // charged on top.
        $subscription->update(['next_charge_at' => now()->addMonth()]);

        $this->assertSame(0, Subscription::query()->dueForCardFallback()->count());
    }

    public function test_entering_a_card_does_not_collect_a_transfer_subscription(): void
    {
        Queue::fake([ChargeSubscriptionJob::class]);

        $customer = Customer::factory()->create(['payment_method' => 'bank_transfer']);
        $byTransfer = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::PastDue,
            'token_id' => null,
            'next_charge_at' => now()->subWeek(),
        ]);

        app(CardTokenService::class)->store($customer, ['Token' => 'tok-fallback', 'CardLast4Digits' => '1111']);

        // The card is attached — it is the fallback — but capturing it is not a
        // decision to take the money now.
        $this->assertNotNull($byTransfer->fresh()->token_id);
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_a_transfer_subscription_is_not_reported_as_awaiting_a_card(): void
    {
        $customer = Customer::factory()->create(['payment_method' => 'credit_card']);

        // Card customer, but this subscription is on a standing order and has no
        // card. It is not "nobody will collect this" — somebody collects it by
        // hand, and calling it a debt would chase a card nobody needs.
        $byStandingOrder = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active,
            'token_id' => null,
            'payment_method' => 'standing_order',
            'next_charge_at' => now()->subWeek(),
        ]);

        $this->assertFalse(Subscription::query()->awaitingCardOverdue()->pluck('id')->contains($byStandingOrder->id));
        $this->assertTrue(Subscription::query()->dueForManualCollection()->pluck('id')->contains($byStandingOrder->id));
    }

    public function test_the_grace_period_is_expressed_in_each_engines_own_dialect(): void
    {
        // Production runs PostgreSQL and the tests run SQLite, so the branch
        // that matters most in production is the one never exercised here.
        // Building the query (without running it) at least proves the right
        // dialect is chosen, and that it is chosen from the query's own
        // connection rather than whichever happens to be the default.
        config(['database.connections.pgsql_probe' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'probe',
            'username' => 'probe', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
        ]]);

        $postgres = Subscription::on('pgsql_probe')->dueForCardFallback()->toSql();
        $sqlite = Subscription::query()->dueForCardFallback()->toSql();

        $this->assertStringContainsString("INTERVAL '1 day'", $postgres);
        $this->assertStringNotContainsString('datetime(', $postgres);

        $this->assertStringContainsString('datetime(', $sqlite);
        $this->assertStringNotContainsString('INTERVAL', $sqlite);
    }

    public function test_the_manual_methods_are_defined_in_one_place(): void
    {
        // Two lists of "which methods are collected by hand" is one list too
        // many: a method on one and not the other would put a subscription on
        // the automatic run and the manual work list at once, or on neither.
        $this->assertSame(Subscription::MANUAL_PAYMENT_METHODS, PaymentMethod::manualValues());
        $this->assertFalse(PaymentMethod::isManualValue('credit_card'));
        $this->assertFalse(PaymentMethod::isManualValue(null));
        $this->assertFalse(PaymentMethod::isManualValue('something_new'));
    }
}
