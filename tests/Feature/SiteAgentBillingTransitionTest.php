<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\TransitionSiteAgentBillingJob;
use App\Models\Charge;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\Billing\SiteAgentArrearsBilling;
use App\Services\Billing\SiteAgentBillingTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class SiteAgentBillingTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-08 10:30:00', 'Asia/Jerusalem'));
    }

    public function test_an_administratively_created_bot_subscription_starts_a_personal_postpaid_month(): void
    {
        $subscription = Subscription::factory()->create([
            'plan_id' => Plan::factory()->create(['includes_site_agent' => true]),
            'current_period_start' => null,
            'current_period_end' => null,
            'next_charge_at' => now(),
        ])->fresh();

        $this->assertSame('arrears', $subscription->billing_mode);
        $this->assertSame('2026-10-08 10:30:00', $subscription->billing_anchor_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-08 10:30:00', $subscription->next_charge_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, $subscription->charges()->count());
    }

    public function test_new_trial_keeps_a_full_free_trial_and_a_full_postpaid_month(): void
    {
        $subscription = Subscription::factory()->create([
            'plan_id' => Plan::factory()->create(['includes_site_agent' => true]),
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(7),
        ])->fresh();

        $this->assertSame('2026-10-15 10:30:00', $subscription->billing_anchor_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-15 10:30:00', $subscription->next_charge_at->format('Y-m-d H:i:s'));
    }

    public function test_unrelated_products_and_explicit_legacy_callbacks_keep_their_contract(): void
    {
        $other = Subscription::factory()->create(['next_charge_at' => now()]);
        $legacy = $this->legacy();

        $this->assertNull($other->billing_mode);
        $this->assertSame('advance', $legacy->billing_mode);
        $this->assertNull($legacy->billing_anchor_at);
        $this->assertSame('2026-10-19', $legacy->next_charge_at->toDateString());
        $this->assertSame('inapplicable', app(SiteAgentBillingTransition::class)->transition($other->id));
    }

    public function test_monthly_prepaid_service_is_credited_and_the_existing_due_date_is_preserved(): void
    {
        $subscription = $this->legacy();

        $this->assertSame('transitioned', app(SiteAgentBillingTransition::class)->transition($subscription->id));
        $subscription->refresh();

        $this->assertSame('arrears', $subscription->billing_mode);
        $this->assertSame('2026-09-19', $subscription->billing_period_start_at->toDateString());
        $this->assertSame('2026-10-19', $subscription->billing_prepaid_until->toDateString());
        $this->assertSame('2026-10-19', $subscription->next_charge_at->toDateString());
        $this->assertSame(['plan' => 0, 'extras' => 0], SiteAgentArrearsBilling::baseAmounts(
            $subscription, $subscription->billing_period_start_at, $subscription->billing_prepaid_until,
        ));
        $this->assertSame(0, $subscription->charges()->count());
    }

    public function test_an_already_paid_year_is_one_usage_only_closing_period_not_twelve_retroactive_invoices(): void
    {
        $subscription = $this->legacy([
            'plan_id' => Plan::factory()->create(['includes_site_agent' => true, 'billing_interval' => 'yearly']),
            'current_period_start' => '2026-01-19',
            'current_period_end' => '2027-01-19',
            'next_charge_at' => '2027-01-19',
        ]);

        app(SiteAgentBillingTransition::class)->transition($subscription->id);
        $subscription->refresh();

        $this->assertSame('2026-01-19', $subscription->billing_period_start_at->toDateString());
        $this->assertSame('2027-01-19', $subscription->billing_prepaid_until->toDateString());
        $this->assertSame('2027-01-19', $subscription->next_charge_at->toDateString());
        $this->assertSame(0, $subscription->charges()->count());
        $this->assertSame(0, Subscription::dueForCharge()->count());
    }

    public function test_a_pending_attempt_is_not_rewritten_and_later_reconciliation_completes_after_resolution(): void
    {
        $subscription = $this->legacy();
        $charge = $this->charge($subscription, ChargeStatus::Pending);
        $original = $subscription->fresh()->getAttributes();

        $this->assertSame('unresolved', app(SiteAgentBillingTransition::class)->transition($subscription->id));
        $this->assertSame($original, $subscription->fresh()->getAttributes());

        $charge->update(['status' => ChargeStatus::Canceled]);
        (new TransitionSiteAgentBillingJob)->handle(app(SiteAgentBillingTransition::class));

        $this->assertSame('arrears', $subscription->fresh()->billing_mode);
        $this->assertSame(1, $subscription->charges()->count());
    }

    public function test_failed_debt_stays_legacy_until_that_same_period_is_paid(): void
    {
        $subscription = $this->legacy();
        $this->charge($subscription, ChargeStatus::Failed);

        $this->assertSame('unresolved', app(SiteAgentBillingTransition::class)->transition($subscription->id));
        $this->charge($subscription, ChargeStatus::Succeeded, ['attempt_number' => 2]);
        $this->assertSame('transitioned', app(SiteAgentBillingTransition::class)->transition($subscription->id));
    }

    public function test_a_previous_invoice_preserves_the_original_join_anniversary_despite_legacy_date_overflow(): void
    {
        $subscription = $this->legacy([
            'current_period_start' => '2026-03-03',
            'current_period_end' => '2026-04-03',
            'next_charge_at' => '2026-04-03',
        ]);
        $this->charge($subscription, ChargeStatus::Succeeded, ['period_start' => '2026-01-31', 'period_end' => '2026-03-03']);

        app(SiteAgentBillingTransition::class)->transition($subscription->id);

        $this->assertSame('2026-01-31', $subscription->fresh()->billing_anchor_at->toDateString());
        $this->assertSame('2026-04-03', $subscription->fresh()->next_charge_at->toDateString());
    }

    public function test_transition_does_not_run_concurrently_with_a_charge(): void
    {
        $subscription = $this->legacy();
        $lock = Cache::lock("charge-subscription:{$subscription->id}", 300);
        $this->assertTrue($lock->get());

        try {
            $this->assertSame('locked', app(SiteAgentBillingTransition::class)->transition($subscription->id));
            $this->assertSame('advance', $subscription->fresh()->billing_mode);
        } finally {
            $lock->release();
        }
    }

    public function test_transition_is_idempotent_and_reports_incomplete_rows_without_customer_details(): void
    {
        $subscription = $this->legacy(['current_period_start' => null]);
        (new TransitionSiteAgentBillingJob)->handle(app(SiteAgentBillingTransition::class));
        (new TransitionSiteAgentBillingJob)->handle(app(SiteAgentBillingTransition::class));

        $this->assertSame('advance', $subscription->fresh()->billing_mode);
        $log = SystemLog::where('source', 'billing')->where('level', 'warning')->sole();
        $this->assertSame(['incomplete' => 1], $log->context);

        $subscription->update(['current_period_start' => '2026-09-19']);
        $service = app(SiteAgentBillingTransition::class);
        $this->assertSame('transitioned', $service->transition($subscription->id));
        $this->assertSame('already', $service->transition($subscription->id));
    }

    public function test_the_original_anniversary_is_immutable_after_initialization(): void
    {
        $subscription = $this->legacy();
        app(SiteAgentBillingTransition::class)->transition($subscription->id);
        $subscription->refresh();

        $this->expectException(LogicException::class);
        $subscription->update(['billing_anchor_at' => now()]);
    }

    public function test_canceling_paid_service_keeps_only_its_closing_invoice_at_the_original_month_end(): void
    {
        $subscription = $this->legacy();
        app(SiteAgentBillingTransition::class)->transition($subscription->id);
        $subscription->refresh()->cancel();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertSame('2026-10-08 10:30:00', $subscription->billing_stop_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19', $subscription->next_charge_at->toDateString());
        $this->assertTrue($subscription->hasFinalArrearsDebt());
        $this->assertTrue($subscription->isChargeable());
        $this->assertFalse(Subscription::whereKey($subscription->id)->dueForCharge()->exists());

        $subscription->markDueNow();
        $this->assertSame('2026-10-19', $subscription->next_charge_at->toDateString());
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
        $this->assertTrue(Subscription::whereKey($subscription->id)->dueForCharge()->exists());

        $originalStop = $subscription->billing_stop_at;
        $subscription->cancel();
        $this->assertTrue($originalStop->equalTo($subscription->billing_stop_at));
    }

    public function test_canceling_a_free_trial_has_no_final_debt(): void
    {
        $subscription = Subscription::factory()->create([
            'plan_id' => Plan::factory()->create(['includes_site_agent' => true]),
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(3),
        ]);
        $subscription->cancel();

        $this->assertNull($subscription->next_charge_at);
        $this->assertNull($subscription->billing_stop_at);
        $this->assertFalse($subscription->isChargeable());
        $this->assertFalse($subscription->hasFinalArrearsDebt());
    }

    public function test_canceled_service_paid_by_transfer_remains_on_the_manual_collection_list(): void
    {
        $subscription = $this->legacy(['payment_method' => 'bank_transfer', 'card_fallback_days' => 5]);
        $subscription->cancel();

        $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
        $this->assertTrue(Subscription::whereKey($subscription->id)->dueForManualCollection()->exists());
        $this->assertFalse(Subscription::whereKey($subscription->id)->dueForCharge()->exists());
        $this->assertFalse(Subscription::whereKey($subscription->id)->dueForCardFallback()->exists());
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'Asia/Jerusalem'));
        $this->assertTrue(Subscription::whereKey($subscription->id)->dueForCardFallback()->exists());
    }

    private function legacy(array $attributes = []): Subscription
    {
        return Subscription::factory()->create(array_merge([
            'plan_id' => Plan::factory()->create(['includes_site_agent' => true]),
            'billing_mode' => 'advance',
            'current_period_start' => '2026-09-19',
            'current_period_end' => '2026-10-19',
            'next_charge_at' => '2026-10-19',
        ], $attributes));
    }

    private function charge(Subscription $subscription, ChargeStatus $status, array $attributes = []): Charge
    {
        return $subscription->charges()->create(array_merge([
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'currency' => 'ILS',
            'status' => $status,
            'attempt_number' => 1,
            'period_start' => '2026-09-19',
            'period_end' => '2026-10-19',
        ], $attributes));
    }
}
