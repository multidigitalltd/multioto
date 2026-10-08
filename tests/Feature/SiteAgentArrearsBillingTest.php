<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\EndSiteAgentTrialsJob;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\SendDunningNotificationJob;
use App\Jobs\SendMonthlyMonitoringReportJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use App\Services\Billing\SiteAgentArrearsBilling;
use App\Services\Billing\SiteAgentBillingTransition;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\ChargeResult;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SiteAgentArrearsBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([IssueInvoiceJob::class, SendDunningNotificationJob::class, SendMonthlyMonitoringReportJob::class]);
    }

    public function test_base_and_only_this_personal_months_excess_are_collected_after_the_month_ends(): void
    {
        $subscription = $this->subscription();
        $this->usage($subscription, '2026-10-08 11:59:59'); // Before service began.
        $this->usage($subscription, '2026-10-08 12:00:00');
        $this->usage($subscription, '2026-10-20 10:00:00');
        $this->usage($subscription, '2026-11-08 11:59:59');
        $next = $this->usage($subscription, '2026-11-08 12:00:00');
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->charge($subscription, 10100);

        $charge = $subscription->charges()->sole();
        $this->assertSame('2026-10-08', $charge->period_start->toDateString());
        $this->assertSame('2026-11-08', $charge->period_end->toDateString());
        $this->assertSame('2026-11-08 11:59:59', $charge->usage_until->toDateTimeString());
        $this->assertSame(3, SiteAgentUsage::where('charge_id', $charge->id)->count());
        $this->assertNull($next->fresh()->charge_id);
        $this->assertSame('2026-12-08 12:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
    }

    public function test_even_a_manual_charge_cannot_collect_an_unfinished_month(): void
    {
        $subscription = $this->subscription();
        $this->travelTo($this->date('2026-11-08 11:59:59'));
        $subscription->update(['next_charge_at' => now()->subMinute()]);
        $this->mock(CardcomClient::class)->shouldNotReceive('chargeToken');

        ChargeSubscriptionJob::dispatchSync($subscription->id, manual: true);

        $this->assertSame(0, $subscription->charges()->count());
    }

    public function test_removing_a_plan_feature_flag_does_not_change_an_existing_financial_cycle(): void
    {
        $subscription = $this->subscription();
        $subscription->plan->update(['includes_site_agent' => false]);
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->charge($subscription, 10000);

        $this->assertSame('2026-10-08', $subscription->charges()->sole()->period_start->toDateString());
        $this->assertSame('2026-12-08 12:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
    }

    public function test_new_and_migrated_trials_start_both_paid_usage_and_the_first_month_only_at_trial_end(): void
    {
        foreach ([false, true] as $legacy) {
            $subscription = $legacy ? $this->legacySubscription() : $this->subscription('2026-10-15 12:00:00');
            $subscription->plan->update(['vat_applies' => false, 'message_price_agorot' => 100, 'included_messages' => 2, 'writing_price_agorot' => 500, 'included_writings' => 1]);
            $subscription->update(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => $this->date('2026-10-15 12:00:00'), 'site_agent_message_cap' => 3, 'price_agorot_override' => null]);
            if ($legacy) {
                app(SiteAgentBillingTransition::class)->transition($subscription->id);
            }
            $site = Site::factory()->create(['customer_id' => $subscription->customer_id]);
            $subscription->update(['site_id' => $site->id]);
            $number = SiteAgentSubscriber::create(['phone' => $legacy ? '972501234562' : '972501234561', 'customer_id' => $subscription->customer_id, 'site_id' => $site->id, 'verified_at' => now()]);
            $meter = app(SiteAgentUsageMeter::class);
            $this->travelTo($this->date('2026-10-15 11:59:59'));
            foreach (range(1, 3) as $i) {
                $meter->record($number, 'trial.'.uniqid());
            }
            $this->writing($number);
            $this->writing($number);
            $this->assertSame(0, SiteAgentUsage::where('subscription_id', $subscription->id)->where('billable', true)->count());
            $this->assertFalse($meter->capReached($subscription->fresh()));

            $this->travelTo($this->date('2026-10-15 12:00:00'));
            // Meter the exact paid boundary while the hourly expiry worker
            // has not run yet; its lag cannot silently waive paid usage.
            $this->assertSame(SubscriptionStatus::Trialing, $subscription->fresh()->status);
            foreach (range(1, 3) as $i) {
                $meter->record($number, 'paid.'.uniqid());
            }
            $this->writing($number);
            $this->writing($number);
            $this->assertSame(3, $meter->current($subscription->fresh())['billable']);
            $this->assertSame(2, $meter->current($subscription->fresh())['writings']);

            $this->travelTo($this->date('2026-10-15 12:30:00'));
            (new EndSiteAgentTrialsJob)->handle();
            $this->assertSame('2026-11-15 12:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
            $this->assertFalse(Subscription::dueForCharge()->whereKey($subscription->id)->exists());

            $this->travelTo($this->date('2026-11-15 12:00:00'));
            $this->charge($subscription, 10600);
            $charge = $subscription->charges()->sole();
            $this->assertSame('2026-10-15', $charge->period_start->toDateString());
            $this->assertSame(5, SiteAgentUsage::where('charge_id', $charge->id)->count());
            $this->assertSame(5, SiteAgentUsage::where('subscription_id', $subscription->id)->where('billable', false)->whereNull('charge_id')->count());
        }
    }

    public function test_retry_freezes_the_amount_allowance_and_usage_window_despite_new_messages_and_prices(): void
    {
        $subscription = $this->subscription();
        foreach (range(1, 4) as $i) {
            $this->usage($subscription, '2026-10-20 10:00:00');
        }
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->charge($subscription, 10200, false);
        $original = $subscription->charges()->sole();
        $next = $this->usage($subscription, '2026-11-09 10:00:00');
        $subscription->plan->update(['price_agorot' => 90000, 'message_price_agorot' => 0, 'included_messages' => 100]);
        $this->travelTo($this->date('2026-11-10 12:00:00'));
        $this->charge($subscription, 10200);

        $retry = $subscription->charges()->latest('id')->first();
        $this->assertSame($original->lines, $retry->lines);
        $this->assertSame(2, $retry->attempt_number);
        $this->assertSame(4, SiteAgentUsage::where('charge_id', $retry->id)->count());
        $this->assertNull($next->fresh()->charge_id);
        $this->assertSame('2026-12-08 12:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
    }

    public function test_unknown_payment_outcome_reuses_the_identical_pending_attempt(): void
    {
        $subscription = $this->subscription();
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $keys = [];
        $this->mock(CardcomClient::class, function ($mock) use (&$keys) {
            $mock->shouldReceive('chargeToken')->once()->withArgs(function ($token, $amount, $description, $key) use (&$keys) {
                $keys[] = $key;

                return $amount === 10000;
            })->andThrow(new RuntimeException('Connection lost after submission'));
        });
        try {
            ChargeSubscriptionJob::dispatchSync($subscription->id);
            $this->fail('The transport failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Connection lost after submission', $exception->getMessage());
        }
        $pending = $subscription->charges()->sole();
        $this->mock(CardcomClient::class, function ($mock) use (&$keys) {
            $mock->shouldReceive('chargeToken')->once()->withArgs(function ($token, $amount, $description, $key) use (&$keys) {
                $keys[] = $key;

                return $amount === 10000;
            })->andReturn(new ChargeResult(true, 'tx-retry', '0'));
        });
        ChargeSubscriptionJob::dispatchSync($subscription->id);

        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame($pending->id, $subscription->charges()->sole()->id);
        $this->assertSame(ChargeStatus::Succeeded, $pending->fresh()->status);
    }

    public function test_a_legacy_unknown_attempt_is_reused_even_when_its_due_date_was_moved(): void
    {
        $subscription = $this->legacySubscription();
        $pending = $this->legacyAttempt($subscription, ChargeStatus::Pending);
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->charge($subscription, 12345);

        $this->assertSame(1, $subscription->charges()->count());
        $this->assertSame(ChargeStatus::Succeeded, $pending->fresh()->status);
        $this->assertSame('2026-09-08', $pending->period_start->toDateString());
    }

    public function test_a_legacy_failed_attempt_keeps_its_frozen_amount_even_if_dunning_was_cleared(): void
    {
        $subscription = $this->legacySubscription();
        $this->legacyAttempt($subscription, ChargeStatus::Failed);
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->charge($subscription, 12345);

        $retry = $subscription->charges()->latest('id')->first();
        $this->assertSame(2, $retry->attempt_number);
        $this->assertSame('2026-09-08', $retry->period_start->toDateString());
        $this->assertSame(12345, $retry->total_agorot);
    }

    public function test_canceling_legacy_debt_collects_only_known_attempts_without_restarting_or_opening_a_new_period(): void
    {
        $subscription = $this->legacySubscription();
        $this->legacyAttempt($subscription, ChargeStatus::Failed);
        $subscription->charges()->create([
            'status' => ChargeStatus::Failed, 'amount_agorot' => 2000, 'vat_agorot' => 0, 'total_agorot' => 2000,
            'currency' => 'ILS', 'attempt_number' => 1, 'period_start' => '2026-10-08', 'period_end' => '2026-11-08',
        ]);
        $this->travelTo($this->date('2026-10-20 12:00:00'));
        $subscription->cancel();
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->charge($subscription, 12345);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->next_charge_at);
        $this->charge($subscription, 2000);

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertFalse($subscription->fresh()->isChargeable());
        $this->assertSame(['2026-09-08', '2026-10-08'], $subscription->charges()->where('status', ChargeStatus::Succeeded)->orderBy('id')->get()->map(fn (Charge $charge): string => $charge->period_start->toDateString())->all());
    }

    public function test_short_february_does_not_move_a_january_31_anniversary_to_the_28th_forever(): void
    {
        $subscription = $this->subscription('2027-01-31 09:45:00');
        $this->assertSame('2027-02-28 09:45:00', $subscription->next_charge_at->toDateTimeString());
        $this->travelTo($this->date('2027-02-28 09:45:00'));
        $this->charge($subscription, 10000);
        $this->assertSame('2027-03-31 09:45:00', $subscription->fresh()->next_charge_at->toDateTimeString());
        $this->travelTo($this->date('2027-03-31 09:45:00'));
        $this->charge($subscription, 10000);
        $this->assertSame('2027-04-30 09:45:00', $subscription->fresh()->next_charge_at->toDateTimeString());
    }

    public function test_leap_year_and_israel_dst_keep_the_personal_day_and_local_time(): void
    {
        $this->assertSame('2028-02-29 12:00:00', SiteAgentArrearsBilling::firstChargeAt($this->date('2028-01-31 12:00:00'))->toDateTimeString());
        $subscription = $this->subscription('2027-03-20 12:00:00');
        $end = SiteAgentArrearsBilling::period($subscription)['end'];
        $this->assertSame('2027-04-20T12:00:00+03:00', $end->toIso8601String());
        $this->assertSame('+02:00', $subscription->billing_anchor_at->format('P'));
    }

    public function test_late_processing_keeps_each_months_allowance_separate(): void
    {
        $subscription = $this->subscription();
        foreach (range(1, 3) as $i) {
            $this->usage($subscription, '2026-10-20 10:00:00');
            $this->usage($subscription, '2026-11-20 10:00:00');
        }
        $this->travelTo($this->date('2026-12-09 12:00:00'));
        $this->charge($subscription, 10100);
        $this->assertSame(3, SiteAgentUsage::whereNull('charge_id')->count());
        $this->charge($subscription, 10100);

        $this->assertSame(2, $subscription->charges()->count());
        $this->assertSame(0, SiteAgentUsage::whereNull('charge_id')->count());
        $this->assertSame('2027-01-08 12:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
    }

    public function test_prepaid_month_collects_only_usage_then_the_next_full_month_collects_base(): void
    {
        $subscription = $this->subscription();
        $subscription->update(['billing_prepaid_until' => $subscription->next_charge_at]);
        foreach (range(1, 3) as $i) {
            $this->usage($subscription, '2026-10-20 10:00:00');
        }
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->charge($subscription, 100);
        $this->travelTo($this->date('2026-12-08 12:00:00'));
        $this->charge($subscription, 10000);
    }

    public function test_existing_prepaid_receipt_does_not_hide_the_separate_usage_closing_invoice(): void
    {
        $subscription = $this->subscription();
        $subscription->update(['billing_prepaid_until' => $subscription->next_charge_at]);
        Charge::create([
            'subscription_id' => $subscription->id, 'status' => ChargeStatus::Succeeded,
            'period_start' => '2026-10-08', 'period_end' => '2026-11-08', 'attempt_number' => 1,
            'amount_agorot' => 10000, 'vat_agorot' => 0, 'total_agorot' => 10000, 'currency' => 'ILS',
        ]);
        foreach (range(1, 3) as $i) {
            $this->usage($subscription, '2026-10-20 10:00:00');
        }
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->charge($subscription, 100);

        $this->assertSame(2, $subscription->charges()->count());
        $this->assertSame(2, $subscription->charges()->latest('id')->first()->attempt_number);
        $this->assertSame(3, SiteAgentUsage::whereNotNull('charge_id')->count());
    }

    public function test_prepaid_usage_only_invoice_balances_vat_rounding_without_creating_a_base_charge(): void
    {
        config(['billing.vat_rate' => 0.18]);

        foreach ([3 => 7, 2 => 5] as $unitPrice => $total) {
            $subscription = $this->subscription('2026-10-08 12:00:00', [
                'vat_applies' => true, 'message_price_agorot' => $unitPrice, 'included_messages' => 0,
                'writing_price_agorot' => $unitPrice, 'included_writings' => 0,
            ]);
            $subscription->update(['billing_prepaid_until' => $subscription->next_charge_at]);
            $this->usage($subscription, '2026-10-20 10:00:00');
            $writing = $this->usage($subscription, '2026-10-20 10:00:00');
            $writing->update(['kind' => SiteAgentUsage::WRITING]);
            $this->travelTo($this->date('2026-11-08 12:00:00'));
            $this->charge($subscription, $total);

            $charge = $subscription->charges()->sole();
            $this->assertSame(0, collect($charge->lines)->firstWhere('kind', 'plan')['unit_price_agorot']);
            $this->assertSame($total, array_sum(array_column($charge->lines, 'unit_price_agorot')));
            $this->assertCount(2, $charge->invoiceLines());
            $this->assertSame($total, array_sum(array_column($charge->invoiceLines(), 'unit_price_agorot')));
            foreach ($charge->invoiceLines() as $line) {
                $this->assertGreaterThan(0, $line['unit_price_agorot']);
            }
        }
    }

    public function test_prepaid_annual_interval_is_one_usage_only_cycle_without_early_collection(): void
    {
        $subscription = $this->subscription('2026-01-08 12:00:00', ['billing_interval' => 'yearly', 'price_agorot' => 120000]);
        $subscription->update(['billing_prepaid_until' => $this->date('2027-01-08 12:00:00'), 'next_charge_at' => $this->date('2027-01-08 12:00:00')]);
        foreach (['2026-02-10', '2026-06-10', '2026-12-10'] as $date) {
            $this->usage($subscription, $date.' 10:00:00');
        }
        $this->assertSame('2027-01-08 12:00:00', SiteAgentArrearsBilling::period($subscription)['end']->toDateTimeString());
        $this->travelTo($this->date('2027-01-08 12:00:00'));
        $this->charge($subscription, 100);
        $this->assertSame('2027-02-08 12:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
        $this->travelTo($this->date('2027-02-08 12:00:00'));
        $this->charge($subscription, 10000);
    }

    public function test_monthly_allocation_of_an_annual_rate_preserves_every_agora(): void
    {
        $subscription = $this->subscription('2026-01-31 12:00:00', ['billing_interval' => 'yearly', 'price_agorot' => 10001]);
        $anchor = $this->date('2026-01-31 12:00:00');
        $total = 0;
        foreach (range(0, 11) as $index) {
            $total += SiteAgentArrearsBilling::baseAmounts($subscription, $anchor->addMonthsNoOverflow($index), $anchor->addMonthsNoOverflow($index + 1))['plan'];
        }
        $this->assertSame(10001, $total);
    }

    public function test_legacy_overflow_prepaid_date_creates_only_a_prorated_stub_then_restores_the_original_anniversary(): void
    {
        $subscription = $this->subscription('2027-01-31 00:00:00');
        $subscription->update(['billing_prepaid_until' => $this->date('2027-03-03 00:00:00'), 'next_charge_at' => $this->date('2027-03-03 00:00:00')]);
        $this->travelTo($this->date('2027-03-03 00:00:00'));
        $this->mock(CardcomClient::class)->shouldNotReceive('chargeToken');
        ChargeSubscriptionJob::dispatchSync($subscription->id);

        $this->assertSame('2027-03-31 00:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
        $start = $this->date('2027-03-03 00:00:00');
        $end = $this->date('2027-03-31 00:00:00');
        $cycleStart = $this->date('2027-02-28 00:00:00');
        $expected = (int) round(10000 * ($end->getTimestamp() - $start->getTimestamp()) / ($end->getTimestamp() - $cycleStart->getTimestamp()));
        $this->travelTo($end);
        $this->charge($subscription, $expected);
        $this->assertSame('2027-04-30 00:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
    }

    public function test_zero_payable_prepaid_cycle_advances_without_card_transaction_or_invoice(): void
    {
        $subscription = $this->subscription();
        $subscription->update(['billing_prepaid_until' => $subscription->next_charge_at]);
        $this->usage($subscription, '2026-10-20 10:00:00');
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->mock(CardcomClient::class)->shouldNotReceive('chargeToken');
        ChargeSubscriptionJob::dispatchSync($subscription->id);

        $this->assertSame(0, $subscription->charges()->sole()->total_agorot);
        $this->assertSame(ChargeStatus::Succeeded, $subscription->charges()->sole()->status);
        $this->assertNotNull(SiteAgentUsage::sole()->charge_id);
        Queue::assertNotPushed(IssueInvoiceJob::class);
    }

    public function test_old_unpaid_messages_do_not_consume_the_next_months_cap_or_estimate(): void
    {
        $subscription = $this->subscription();
        $subscription->update(['site_agent_message_cap' => 3]);
        foreach (range(1, 3) as $i) {
            $this->usage($subscription, '2026-10-20 10:00:00');
        }
        $this->usage($subscription, '2026-11-09 10:00:00');
        $this->travelTo($this->date('2026-11-10 12:00:00'));
        $meter = app(SiteAgentUsageMeter::class);

        $this->assertFalse($meter->capReached($subscription));
        $this->assertSame(1, $meter->current($subscription)['billable']);
        $this->assertSame(0, $meter->current($subscription)['estimate_gross_agorot']);
    }

    public function test_successful_payment_with_unadvanced_cursor_is_recovered_without_charging_twice(): void
    {
        $subscription = $this->subscription();
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->charge($subscription, 10000);
        $subscription->update(SiteAgentArrearsBilling::initializeDates($this->date('2026-10-08 12:00:00')));
        $this->mock(CardcomClient::class)->shouldNotReceive('chargeToken');
        ChargeSubscriptionJob::dispatchSync($subscription->id);

        $this->assertSame(1, $subscription->charges()->count());
        $this->assertSame('2026-12-08 12:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
    }

    public function test_cancellation_keeps_only_delivered_service_due_at_the_original_month_end(): void
    {
        $subscription = $this->subscription();
        foreach (range(1, 3) as $i) {
            $this->usage($subscription, '2026-10-10 10:00:00');
        }
        $this->travelTo($this->date('2026-10-20 12:00:00'));
        $subscription->cancel();
        $stopped = $subscription->fresh();
        $this->assertSame('2026-11-08 12:00:00', $stopped->next_charge_at->toDateTimeString());
        $this->assertSame(SubscriptionStatus::Canceled, $stopped->status);
        $this->assertTrue(Subscription::dueForCharge()->whereKey($subscription->id)->doesntExist());
        $afterStop = $this->usage($subscription, '2026-10-21 10:00:00');
        $start = $this->date('2026-10-08 12:00:00');
        $end = $this->date('2026-11-08 12:00:00');
        $stop = $this->date('2026-10-20 12:00:00');
        $expected = (int) round(10000 * ($stop->getTimestamp() - $start->getTimestamp()) / ($end->getTimestamp() - $start->getTimestamp())) + 100;
        $this->travelTo($end);
        $this->assertTrue(Subscription::dueForCharge()->whereKey($subscription->id)->exists());
        $this->charge($subscription, $expected);

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertNull($afterStop->fresh()->charge_id);
        $this->assertFalse($subscription->fresh()->isChargeable());
    }

    public function test_canceled_overdue_service_settles_each_owed_month_before_closing_the_final_debt(): void
    {
        $subscription = $this->subscription();
        $this->travelTo($this->date('2026-12-20 12:00:00'));
        $subscription->cancel();
        $this->charge($subscription, 10000);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->next_charge_at);
        $this->charge($subscription, 10000);
        $this->assertSame('2027-01-08 12:00:00', $subscription->fresh()->next_charge_at->toDateTimeString());
        $this->travelTo($this->date('2027-01-08 12:00:00'));
        $this->charge($subscription, (int) round(10000 * 12 / 31));
        $this->assertNull($subscription->fresh()->next_charge_at);
        $this->assertSame(3, $subscription->charges()->count());
    }

    public function test_failed_final_payment_retries_the_frozen_debt_without_restarting_the_service(): void
    {
        $subscription = $this->subscription('2026-11-08 12:00:00');
        $this->travelTo($this->date('2026-11-20 12:00:00'));
        $subscription->cancel();
        $amount = (int) round(10000 * 12 / 30);
        $this->travelTo($this->date('2026-12-08 12:00:00'));
        $this->charge($subscription, $amount, false);
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertSame('2026-11-20 12:00:00', $subscription->fresh()->billing_stop_at->toDateTimeString());
        $this->travelTo($this->date('2026-12-10 12:00:00'));
        $this->charge($subscription, $amount);

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->next_charge_at);
    }

    public function test_a_late_insert_cannot_be_marked_paid_by_a_frozen_retry_that_never_counted_it(): void
    {
        $subscription = $this->subscription();
        $this->travelTo($this->date('2026-11-08 12:00:00'));
        $this->charge($subscription, 10000, false);
        $late = $this->usage($subscription, '2026-10-20 12:00:00');
        $this->travelTo($this->date('2026-11-10 12:00:00'));
        $this->charge($subscription, 10000);

        $this->assertNull($late->fresh()->charge_id);
    }

    private function subscription(string $anchor = '2026-10-08 12:00:00', array $planAttributes = []): Subscription
    {
        $this->travelTo($this->date($anchor));
        $customer = Customer::factory()->create(['vat_exempt' => false, 'payment_method' => 'credit_card']);
        $token = PaymentToken::factory()->create(['customer_id' => $customer->id]);
        $plan = Plan::factory()->create([
            'includes_site_agent' => true, 'price_agorot' => 10000, 'vat_applies' => false,
            'message_price_agorot' => 100, 'included_messages' => 2,
            ...$planAttributes,
        ]);

        return Subscription::factory()->create([
            'customer_id' => $customer->id, 'token_id' => $token->id, 'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active, 'price_agorot_override' => null,
            'agent_extra_numbers' => 0,
            ...SiteAgentArrearsBilling::initializeDates($this->date($anchor)),
        ]);
    }

    private function usage(Subscription $subscription, string $at): SiteAgentUsage
    {
        return SiteAgentUsage::create([
            'customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id,
            'kind' => SiteAgentUsage::MESSAGE, 'provider_message_id' => 'test.'.uniqid('', true),
            'billable' => true, 'sent_at' => $this->date($at),
        ]);
    }

    private function legacySubscription(): Subscription
    {
        $plan = Plan::factory()->create(['includes_site_agent' => true, 'price_agorot' => 10000]);

        return Subscription::factory()->create([
            'billing_mode' => 'advance', 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
            'current_period_start' => '2026-10-08', 'current_period_end' => '2026-11-08',
            'next_charge_at' => $this->date('2026-11-08 12:00:00'), 'dunning_stage' => 0,
        ]);
    }

    private function legacyAttempt(Subscription $subscription, ChargeStatus $status): Charge
    {
        return $subscription->charges()->create([
            'status' => $status, 'amount_agorot' => 12345, 'vat_agorot' => 0, 'total_agorot' => 12345,
            'currency' => 'ILS', 'attempt_number' => 1, 'period_start' => '2026-09-08', 'period_end' => '2026-10-08',
        ]);
    }

    private function writing(SiteAgentSubscriber $number): void
    {
        SiteAgentRequest::create([
            'site_agent_subscriber_id' => $number->id, 'site_id' => $number->site_id, 'customer_id' => $number->customer_id,
            'message' => 'כתוב תוכן', 'operation' => SiteAgentRequest::OP_POST_CREATE, 'state' => SiteAgentRequest::APPLIED,
            'plan' => ['fields' => ['content' => implode(' ', array_fill(0, 301, 'מילה'))]],
            'preview' => 'תוכן חדש', 'expires_at' => now()->addHour(),
        ]);
    }

    private function charge(Subscription $subscription, int $expected, bool $success = true): void
    {
        $this->mock(CardcomClient::class, function ($mock) use ($expected, $success) {
            $mock->shouldReceive('chargeToken')->once()->withArgs(fn ($token, $amount) => $amount === $expected)
                ->andReturn(new ChargeResult($success, $success ? 'tx-'.uniqid() : null, $success ? '0' : '33'));
        });
        ChargeSubscriptionJob::dispatchSync($subscription->id);
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'Asia/Jerusalem');
    }
}
