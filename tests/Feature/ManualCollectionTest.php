<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\IssueInvoiceJob;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManualCollectionTest extends TestCase
{
    use RefreshDatabase;

    /** A non-card (bank-transfer) subscription with no saved card. */
    private function manualSubscription(array $overrides = []): Subscription
    {
        $customer = Customer::factory()->create(['payment_method' => 'bank_transfer', 'vat_exempt' => false]);
        $plan = Plan::factory()->create(['price_agorot' => 10000, 'vat_applies' => true]);

        return Subscription::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'token_id' => null,
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->subDay(),
        ], $overrides));
    }

    public function test_manually_collected_scope_matches_non_card_subscriptions_only(): void
    {
        $manual = $this->manualSubscription();
        // A card subscription (has a token) is auto-charged, not manual.
        Subscription::factory()->create();

        $this->assertEqualsCanonicalizing(
            [$manual->id],
            Subscription::query()->manuallyCollected()->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$manual->id],
            Subscription::query()->dueForManualCollection()->pluck('id')->all(),
        );
    }

    public function test_recording_a_payment_advances_the_subscription_and_issues_an_invoice(): void
    {
        Queue::fake([IssueInvoiceJob::class]);
        $sub = $this->manualSubscription();
        $due = $sub->next_charge_at->copy();

        $charge = app(SubscriptionCollectionService::class)->recordPayment($sub, 'אסמכתא 999');

        // A succeeded charge tied to the subscription, priced from the plan + VAT.
        $this->assertSame(ChargeStatus::Succeeded, $charge->status);
        $this->assertSame($sub->id, $charge->subscription_id);
        $this->assertSame(10000, $charge->amount_agorot);
        $this->assertSame((int) round(10000 * 0.18), $charge->vat_agorot);
        $this->assertSame('אסמכתא 999', $charge->invoice_notes);
        $this->assertNotNull($charge->charged_at);

        // The subscription rolled into the next period.
        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertSame(0, $sub->dunning_stage);
        $this->assertSame(
            $due->copy()->addMonth()->toDateString(),
            $sub->next_charge_at->toDateString(),
        );

        Queue::assertPushed(IssueInvoiceJob::class, fn ($job) => $job->chargeId === $charge->id);
    }

    public function test_recording_a_payment_is_idempotent_on_a_double_click(): void
    {
        Queue::fake([IssueInvoiceJob::class]);
        $sub = $this->manualSubscription();

        $first = app(SubscriptionCollectionService::class)->recordPayment($sub);
        // A second click (the subscription's next charge is now in the future)
        // must not bill or invoice the next period — it returns the same charge.
        $again = app(SubscriptionCollectionService::class)->recordPayment($sub->fresh());

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, $sub->charges()->where('status', ChargeStatus::Succeeded)->count());
        Queue::assertPushed(IssueInvoiceJob::class, 1);
    }
}
