<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\SendDunningNotificationJob;
use App\Jobs\SuspendSiteJob;
use App\Models\Site;
use App\Models\Subscription;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\ChargeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChargeSubscriptionJobTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeCardcom(bool $success): void
    {
        $this->mock(CardcomClient::class, function ($mock) use ($success) {
            $mock->shouldReceive('chargeToken')->once()->andReturn(new ChargeResult(
                success: $success,
                transactionId: $success ? 'tx-123' : null,
                responseCode: $success ? '0' : '33',
                message: $success ? null : 'Refused',
            ));
        });
    }

    public function test_successful_charge_advances_period_and_issues_invoice(): void
    {
        Queue::fake([IssueInvoiceJob::class]);
        $this->fakeCardcom(success: true);

        $subscription = Subscription::factory()->create([
            'next_charge_at' => now()->subHour(),
        ]);

        ChargeSubscriptionJob::dispatchSync($subscription->id);

        $subscription->refresh();
        $charge = $subscription->charges()->sole();

        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertSame('tx-123', $charge->cardcom_transaction_id);
        // 18% VAT on top of the plan price, in agorot.
        $this->assertSame((int) round($charge->amount_agorot * 0.18), $charge->vat_agorot);
        $this->assertSame($charge->amount_agorot + $charge->vat_agorot, $charge->total_agorot);

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(0, $subscription->dunning_stage);
        $this->assertTrue($subscription->next_charge_at->isFuture());
        $this->assertSame(
            $charge->period_end->toDateString(),
            $subscription->next_charge_at->toDateString(),
        );

        Queue::assertPushed(IssueInvoiceJob::class, fn ($job) => $job->chargeId === $charge->id);
    }

    public function test_vat_exempt_customer_is_charged_without_vat(): void
    {
        Queue::fake([IssueInvoiceJob::class]);
        $this->fakeCardcom(success: true);

        $subscription = Subscription::factory()->create(['next_charge_at' => now()->subHour()]);
        $subscription->customer->update(['vat_exempt' => true]);

        ChargeSubscriptionJob::dispatchSync($subscription->id);

        $charge = $subscription->charges()->sole();
        $this->assertSame(0, $charge->vat_agorot);
        $this->assertSame($charge->amount_agorot, $charge->total_agorot);
    }

    public function test_failed_charge_enters_dunning_stage_one_with_retry_and_notifications(): void
    {
        Queue::fake([SendDunningNotificationJob::class]);
        $this->fakeCardcom(success: false);

        $subscription = Subscription::factory()->create(['next_charge_at' => now()->subHour()]);

        ChargeSubscriptionJob::dispatchSync($subscription->id);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->dunning_stage);
        $this->assertSame(
            now()->addDays(config('billing.dunning.stages.1.retry_in_days'))->startOfDay()->toDateTimeString(),
            $subscription->next_charge_at->toDateTimeString(),
        );

        // WhatsApp + email events queued for the stage-1 template.
        $this->assertSame(2, $subscription->dunningEvents()->count());
        $this->assertSame('payment_failed', $subscription->dunningEvents()->first()->template_key);
        Queue::assertPushed(SendDunningNotificationJob::class, 2);
    }

    public function test_final_dunning_stage_suspends_the_site_and_stops_retrying(): void
    {
        Queue::fake([SendDunningNotificationJob::class, SuspendSiteJob::class]);
        $this->fakeCardcom(success: false);

        $site = Site::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $site->customer_id,
            'site_id' => $site->id,
            'status' => SubscriptionStatus::PastDue,
            'dunning_stage' => 3,
            'next_charge_at' => now()->subHour(),
        ]);

        ChargeSubscriptionJob::dispatchSync($subscription->id);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->status);
        $this->assertSame(4, $subscription->dunning_stage);
        $this->assertNull($subscription->next_charge_at);

        Queue::assertPushed(SuspendSiteJob::class, fn ($job) => $job->siteId === $site->id);
    }

    public function test_subscription_not_due_is_not_charged(): void
    {
        $this->mock(CardcomClient::class, fn ($mock) => $mock->shouldReceive('chargeToken')->never());

        $subscription = Subscription::factory()->create(['next_charge_at' => now()->addDay()]);

        ChargeSubscriptionJob::dispatchSync($subscription->id);

        $this->assertSame(0, $subscription->charges()->count());
    }

    public function test_retry_during_dunning_keeps_the_same_billing_period(): void
    {
        Queue::fake([SendDunningNotificationJob::class]);

        $subscription = Subscription::factory()->create(['next_charge_at' => now()->subHour()]);

        // First attempt fails.
        $this->fakeCardcom(success: false);
        ChargeSubscriptionJob::dispatchSync($subscription->id);
        $firstCharge = $subscription->charges()->sole();

        // Retry (still failing) charges the same unpaid period, attempt #2.
        $subscription->refresh()->update(['next_charge_at' => now()->subMinute()]);
        $this->fakeCardcom(success: false);
        ChargeSubscriptionJob::dispatchSync($subscription->id);

        $retry = $subscription->charges()->latest('id')->first();
        $this->assertSame(2, $retry->attempt_number);
        $this->assertSame($firstCharge->period_start->toDateString(), $retry->period_start->toDateString());
    }
}
