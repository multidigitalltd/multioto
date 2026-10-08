<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\RestoreSiteJob;
use App\Jobs\SuspendSiteJob;
use App\Models\Charge;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use App\Services\Billing\DunningMachine;
use App\Services\Billing\RenewalBreakdown;
use App\Services\Billing\SiteAgentArrearsBilling;
use App\Services\Billing\SiteAgentBillingTransition;
use App\Services\Billing\SubscriptionCollectionService;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\CardTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SiteAgentArrearsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_manual_payment_collects_a_completed_personal_month_once_with_its_usage(): void
    {
        $subscription = $this->subscription();
        foreach (range(1, 3) as $i) {
            $this->usage($subscription, '2026-10-20 12:00:00');
        }
        $nextMonth = $this->usage($subscription, '2026-11-08 12:00:00');
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $service = app(SubscriptionCollectionService::class);
        $charge = $service->recordPayment($subscription, 'העברה');

        $this->assertSame(10100, $charge->amount_agorot);
        $this->assertSame(1818, $charge->vat_agorot);
        $this->assertSame('bank_transfer', $charge->payment_method);
        $this->assertSame('2026-10-08', $charge->period_start->toDateString());
        $this->assertNull($nextMonth->fresh()->charge_id);
        $this->assertSame('2026-12-08 12:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
        $this->assertSame($charge->id, $service->recordPayment($subscription->fresh())->id);
        Queue::assertPushed(IssueInvoiceJob::class, 1);
    }

    public function test_manual_payment_rejects_an_unfinished_service_month(): void
    {
        $subscription = $this->subscription();
        $this->travelTo($this->date('2026-10-20 12:00:00'));

        try {
            app(SubscriptionCollectionService::class)->recordPayment($subscription);
            $this->fail('An unfinished month cannot be recorded as paid.');
        } catch (ValidationException) {
            $this->assertSame(0, $subscription->charges()->count());
        }
    }

    public function test_manual_retry_keeps_the_original_price_and_usage_snapshot(): void
    {
        $subscription = $this->subscription();
        $this->usage($subscription, '2026-10-20 12:00:00');
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $failed = $this->attempt($subscription, ChargeStatus::Failed);
        $subscription->plan->update(['price_agorot' => 90000, 'message_price_agorot' => 900]);
        $late = $this->usage($subscription, '2026-10-21 12:00:00');
        $subscription->update(['status' => SubscriptionStatus::PastDue, 'dunning_stage' => 1]);

        $paid = app(SubscriptionCollectionService::class)->recordPayment($subscription);

        $this->assertSame($failed->total_agorot, $paid->total_agorot);
        $this->assertSame($failed->lines, $paid->lines);
        $this->assertNull($late->fresh()->charge_id);
        $this->assertSame(2, $paid->attempt_number);
    }

    public function test_manual_payment_waits_for_an_unknown_card_attempt_to_be_reconciled(): void
    {
        $subscription = $this->subscription();
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->attempt($subscription, ChargeStatus::Pending);

        try {
            app(SubscriptionCollectionService::class)->recordPayment($subscription);
            $this->fail('An unknown card result must be reconciled first.');
        } catch (ValidationException) {
            $this->assertSame(1, $subscription->charges()->count());
            $this->assertSame(ChargeStatus::Pending, $subscription->charges()->sole()->status);
        }
    }

    public function test_final_manual_payment_prorates_base_and_vat_without_reactivating_service(): void
    {
        $subscription = $this->subscription();
        $this->travelTo($this->date('2026-10-20 12:00:00'));
        $subscription->cancel();
        $stop = $subscription->billing_stop_at;
        $start = $subscription->billing_period_start_at;
        $end = SiteAgentArrearsBilling::period($subscription)['end'];
        $expected = (int) round(10000 * ($stop->getTimestamp() - $start->getTimestamp()) / ($end->getTimestamp() - $start->getTimestamp()));
        $this->travelTo($end);

        $paid = app(SubscriptionCollectionService::class)->recordPayment($subscription);

        $this->assertSame($expected, $paid->amount_agorot);
        $this->assertSame((int) round($expected * .18), $paid->vat_agorot);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertSame($paid->id, app(SubscriptionCollectionService::class)->recordPayment($subscription)->id);
        Queue::assertNotPushed(RestoreSiteJob::class);
    }

    public function test_new_card_collects_canceled_final_debt_without_reactivating_the_service(): void
    {
        $subscription = $this->subscription(['payment_method' => 'credit_card']);
        $this->travelTo($this->date('2026-10-20 12:00:00'));
        $subscription->cancel();
        $this->travelTo($this->date('2026-11-10 12:00:00'));
        $token = PaymentToken::factory()->create(['customer_id' => $subscription->customer_id]);

        app(CardTokenService::class)->makeDefault($subscription->customer, $token);

        $this->assertSame($token->id, $subscription->fresh()->token_id);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        Queue::assertPushed(ChargeSubscriptionJob::class, fn ($job) => $job->subscriptionId === $subscription->id && $job->manual);
    }

    public function test_new_card_before_the_closing_date_waits_for_the_personal_month_to_end(): void
    {
        $subscription = $this->subscription(['payment_method' => 'credit_card']);
        $this->travelTo($this->date('2026-10-20 12:00:00'));
        $subscription->cancel();
        $token = PaymentToken::factory()->create(['customer_id' => $subscription->customer_id]);

        app(CardTokenService::class)->makeDefault($subscription->customer, $token);

        $this->assertSame('2026-11-08 12:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_final_dunning_never_suspends_the_site_or_restarts_a_canceled_service(): void
    {
        $subscription = $this->subscription();
        $subscription->update(['site_id' => Site::factory()->create(['customer_id' => $subscription->customer_id])->id]);
        $this->travelTo($this->date('2026-10-20 12:00:00'));
        $subscription->cancel();
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $failed = $this->attempt($subscription, ChargeStatus::Failed);
        $subscription->update(['dunning_stage' => max(array_keys(config('billing.dunning.stages'))) - 1]);

        app(DunningMachine::class)->handleFailure($subscription, $failed);

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertTrue($subscription->fresh()->hasFinalArrearsDebt());
        $this->assertSame('final_payment_failed_final', $subscription->dunningEvents()->firstOrFail()->template_key);
        Queue::assertNotPushed(SuspendSiteJob::class);
    }

    public function test_active_bot_debt_pauses_only_the_bot_and_never_the_hosted_site(): void
    {
        $subscription = $this->subscription();
        $subscription->update([
            'site_id' => Site::factory()->create(['customer_id' => $subscription->customer_id])->id,
            'dunning_stage' => max(array_keys(config('billing.dunning.stages'))) - 1,
        ]);
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        app(DunningMachine::class)->handleFailure($subscription, $this->attempt($subscription, ChargeStatus::Failed));

        $this->assertSame(SubscriptionStatus::Suspended, $subscription->fresh()->status);
        $this->assertStringEndsWith('_no_site', $subscription->dunningEvents()->firstOrFail()->template_key);
        Queue::assertNotPushed(SuspendSiteJob::class);
    }

    public function test_removed_replaced_empty_and_other_customer_tokens_cannot_be_charged(): void
    {
        $subscription = $this->subscription(['payment_method' => 'credit_card']);
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->mock(CardcomClient::class)->shouldNotReceive('chargeToken');
        $token = $subscription->token;

        foreach ([TokenStatus::Removed, TokenStatus::Replaced, TokenStatus::Expired] as $status) {
            $token->update(['status' => $status]);
            $this->assertFalse($subscription->refresh()->isChargeable());
            ChargeSubscriptionJob::dispatchSync($subscription->id, manual: true);
        }

        $token->update(['status' => TokenStatus::Active, 'cardcom_token' => '']);
        $this->assertFalse($subscription->refresh()->isChargeable());
        ChargeSubscriptionJob::dispatchSync($subscription->id, manual: true);

        $otherCustomerToken = PaymentToken::factory()->create();
        $subscription->update(['token_id' => $otherCustomerToken->id]);
        $this->assertFalse($subscription->refresh()->isChargeable());
        ChargeSubscriptionJob::dispatchSync($subscription->id, manual: true);
        $this->assertSame(0, $subscription->charges()->count());
    }

    public function test_a_prepaid_base_invoice_does_not_hide_the_usage_only_closing_invoice(): void
    {
        $subscription = $this->subscription();
        // Reproduce a migrated existing row without the new-creation hook.
        Subscription::whereKey($subscription->id)->update([
            'billing_mode' => 'advance', 'billing_anchor_at' => null, 'billing_period_start_at' => null,
        ]);
        $subscription->refresh();
        $subscription->charges()->create([
            'amount_agorot' => 10000, 'vat_agorot' => 1800, 'total_agorot' => 11800,
            'status' => ChargeStatus::Succeeded, 'attempt_number' => 1,
            'period_start' => '2026-10-08', 'period_end' => '2026-11-08',
        ]);
        $this->travelTo($this->date('2026-11-08 12:00:00'));

        $closing = app(SubscriptionCollectionService::class)->recordPayment($subscription);

        $this->assertSame(0, $closing->total_agorot);
        $this->assertSame(2, $closing->attempt_number);
        $this->assertNotEmpty(SiteAgentArrearsBilling::metadata($closing));
        $this->assertSame('2026-12-08', $subscription->fresh()->next_charge_at->toDateString());
        Queue::assertNotPushed(IssueInvoiceJob::class);
    }

    public function test_canceling_legacy_failed_debt_preserves_only_that_frozen_payment(): void
    {
        $subscription = $this->legacyDebt(ChargeStatus::Failed);
        $subscription->cancel();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertSame('advance', $subscription->billing_mode);
        $this->assertTrue($subscription->hasFinalLegacyDebt());
        $this->assertTrue(Subscription::whereKey($subscription->id)->dueForManualCollection()->exists());
        $this->assertTrue(Subscription::whereKey($subscription->id)->inArrears()->exists());
        $subscription->plan->update(['price_agorot' => 99999]);
        $paid = app(SubscriptionCollectionService::class)->recordPayment($subscription);

        $this->assertSame(2000, $paid->amount_agorot);
        $this->assertSame('2026-09-08', $paid->period_start->toDateString());
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertFalse($subscription->fresh()->hasFinalLegacyDebt());
        $this->assertSame($paid->id, app(SubscriptionCollectionService::class)->recordPayment($subscription)->id);
        $this->assertSame(2, $subscription->charges()->count());
        Queue::assertNotPushed(RestoreSiteJob::class);
    }

    public function test_canceling_a_legacy_unknown_attempt_keeps_its_identity_and_blocks_a_second_manual_payment(): void
    {
        $subscription = $this->legacyDebt(ChargeStatus::Pending);
        $subscription->update(['payment_method' => 'credit_card']);
        $pending = $subscription->charges()->sole();
        $subscription->cancel();

        $this->assertSame(SiteAgentBillingTransition::UNRESOLVED, app(SiteAgentBillingTransition::class)->transition($subscription->id));
        $this->assertTrue($subscription->isChargeable());
        $this->assertTrue(Subscription::whereKey($subscription->id)->dueForCharge()->exists());

        try {
            app(SubscriptionCollectionService::class)->recordPayment($subscription);
            $this->fail('Unknown prior card outcome must not become a second payment.');
        } catch (ValidationException) {
            $this->assertSame($pending->id, $subscription->charges()->sole()->id);
            $this->assertSame(ChargeStatus::Pending, $pending->fresh()->status);
        }

        $pending->update(['status' => ChargeStatus::Canceled]);
        $this->assertSame('inapplicable', app(SiteAgentBillingTransition::class)->transition($subscription->id));
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertFalse($subscription->fresh()->isChargeable());
    }

    public function test_a_replacement_card_settles_legacy_final_debt_without_reopening_service(): void
    {
        $subscription = $this->legacyDebt(ChargeStatus::Failed);
        $subscription->update(['payment_method' => 'credit_card']);
        $subscription->cancel();
        $token = PaymentToken::factory()->create(['customer_id' => $subscription->customer_id]);

        app(CardTokenService::class)->makeDefault($subscription->customer, $token);

        $this->assertSame($token->id, $subscription->fresh()->token_id);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        Queue::assertPushed(ChargeSubscriptionJob::class, fn ($job) => $job->subscriptionId === $subscription->id);
    }

    public function test_canceling_after_the_actual_trial_end_keeps_only_elapsed_paid_service(): void
    {
        $subscription = $this->subscription();
        $subscription->update(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => $subscription->billing_anchor_at]);
        $this->travelTo($this->date('2026-10-08 12:15:00'));
        $subscription->cancel();

        $this->assertTrue($subscription->hasFinalArrearsDebt());
        $this->assertSame('2026-10-08 12:15:00', $subscription->billing_stop_at->toDateTimeString());
        $this->assertSame('2026-11-08 12:00:00', $subscription->next_charge_at->toDateTimeString());
    }

    public function test_an_expired_trial_without_a_chargeable_card_does_not_create_final_debt(): void
    {
        $subscription = $this->subscription();
        $subscription->update(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => $subscription->billing_anchor_at, 'token_id' => null]);
        $this->travelTo($this->date('2026-10-08 12:15:00'));
        $subscription->cancel();

        $this->assertFalse($subscription->hasFinalBillingDebt());
        $this->assertNull($subscription->billing_stop_at);
        $this->assertNull($subscription->next_charge_at);
    }

    private function legacyDebt(ChargeStatus $status): Subscription
    {
        $subscription = $this->subscription();
        Subscription::whereKey($subscription->id)->update([
            'billing_mode' => 'advance', 'billing_anchor_at' => null, 'billing_period_start_at' => null,
            'next_charge_at' => $this->date('2026-10-08 12:00:00'),
        ]);
        $subscription->refresh();
        $subscription->charges()->create([
            'amount_agorot' => 2000, 'vat_agorot' => 360, 'total_agorot' => 2360,
            'status' => $status, 'attempt_number' => 1,
            'period_start' => '2026-09-08', 'period_end' => '2026-10-08',
        ]);

        return $subscription;
    }

    private function subscription(array $attributes = []): Subscription
    {
        $this->travelTo($this->date('2026-10-08 12:00:00'));
        $plan = Plan::factory()->create([
            'includes_site_agent' => true, 'price_agorot' => 10000, 'vat_applies' => true,
            'message_price_agorot' => 100, 'included_messages' => 2,
        ]);

        return Subscription::factory()->create([
            'plan_id' => $plan->id, 'payment_method' => 'bank_transfer',
            'price_agorot_override' => null, 'agent_extra_numbers' => 0,
            ...SiteAgentArrearsBilling::initializeDates(now()),
            ...$attributes,
        ]);
    }

    private function attempt(Subscription $subscription, ChargeStatus $status): Charge
    {
        ['start' => $start, 'end' => $end] = SiteAgentArrearsBilling::period($subscription);

        return $subscription->charges()->create([
            ...app(RenewalBreakdown::class)->for($subscription, $start, $end, $end),
            'status' => $status, 'attempt_number' => 1,
            'period_start' => $start, 'period_end' => $end,
        ]);
    }

    private function usage(Subscription $subscription, string $date): SiteAgentUsage
    {
        return SiteAgentUsage::create([
            'customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id,
            'kind' => SiteAgentUsage::MESSAGE, 'billable' => true, 'sent_at' => $this->date($date),
            'provider_message_id' => uniqid('integration.', true),
        ]);
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Asia/Jerusalem');
    }
}
