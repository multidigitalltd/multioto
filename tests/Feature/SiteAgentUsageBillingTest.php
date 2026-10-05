<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\SendDunningNotificationJob;
use App\Jobs\SendMonthlyMonitoringReportJob;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionCollectionService;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\ChargeResult;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The monthly charge for the site agent: the plan, every extra number, and the
 * messages the bot sent — three lines, one charge, each message billed once.
 *
 * Money, so every test is about a way it could be wrong by an agora or by a
 * month: a message billed twice, a trial message billed at all, lines that do
 * not add up to what the card was charged, or a failed charge that quietly
 * marked messages as paid.
 */
class SiteAgentUsageBillingTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Customer $customer;

    private Site $site;

    private Subscription $subscription;

    private SiteAgentSubscriber $number;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.vat_rate' => 0.18]);
        Queue::fake([IssueInvoiceJob::class, SendDunningNotificationJob::class, SendMonthlyMonitoringReportJob::class]);

        $this->plan = Plan::create([
            'name' => 'בוט ניהול האתר', 'price_agorot' => 14900, 'extra_number_price_agorot' => 4900,
            'message_price_agorot' => 15, 'vat_applies' => true, 'billing_interval' => 'monthly',
            'active' => true, 'is_public' => true, 'includes_site_agent' => true,
        ]);

        $this->customer = Customer::factory()->create(['vat_exempt' => false]);
        $this->site = Site::factory()->create(['customer_id' => $this->customer->id]);
        $this->subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id, 'plan_id' => $this->plan->id, 'site_id' => $this->site->id,
            'status' => SubscriptionStatus::Active, 'price_agorot_override' => null,
            'agent_extra_numbers' => 0, 'next_charge_at' => now()->subHour(),
        ]);
        $this->number = SiteAgentSubscriber::create([
            'phone' => '972501111111', 'customer_id' => $this->customer->id,
            'site_id' => $this->site->id, 'verified_at' => now(),
        ]);
    }

    public function test_each_delivered_reply_is_counted_once(): void
    {
        $meter = app(SiteAgentUsageMeter::class);

        $meter->record($this->number, 'wamid.A');
        $meter->record($this->number, 'wamid.A'); // the same delivery reported twice
        $meter->record($this->number, 'wamid.B');

        $this->assertSame(2, SiteAgentUsage::count());
        $this->assertSame(2, SiteAgentUsage::where('subscription_id', $this->subscription->id)->where('billable', true)->count());
    }

    public function test_messages_during_a_trial_are_never_billed(): void
    {
        $this->subscription->update(['status' => SubscriptionStatus::Trialing]);

        app(SiteAgentUsageMeter::class)->record($this->number, 'wamid.trial');

        // The trial ends and the first renewal runs: the week's messages are not on it.
        $this->subscription->update(['status' => SubscriptionStatus::Active]);
        $this->chargeSucceeds();

        $this->assertNull($this->subscription->charges()->sole()->lines);
        $this->assertFalse(SiteAgentUsage::sole()->billable);
    }

    public function test_a_plan_that_does_not_price_messages_never_bills_them(): void
    {
        $this->plan->update(['message_price_agorot' => null]);

        app(SiteAgentUsageMeter::class)->record($this->number, 'wamid.free');

        // And pricing them later does not reach back.
        $this->plan->update(['message_price_agorot' => 15]);
        $this->chargeSucceeds();

        $this->assertNull($this->subscription->charges()->sole()->lines);
    }

    public function test_a_renewal_with_nothing_extra_is_exactly_what_it_was(): void
    {
        $this->chargeSucceeds();

        $charge = $this->subscription->charges()->sole();

        $this->assertNull($charge->lines);
        $this->assertSame(14900, $charge->amount_agorot);
        $this->assertSame(2682, $charge->vat_agorot);
        $this->assertSame(17582, $charge->total_agorot);
    }

    public function test_the_renewal_bills_plan_numbers_and_messages_on_separate_lines_that_add_up(): void
    {
        $this->subscription->update(['agent_extra_numbers' => 2]);
        $this->sendMessages(1237);

        $this->chargeSucceeds();

        $charge = $this->subscription->charges()->sole();

        // Net: 149.00 + 2 × 49.00 + 1,237 × 0.15 = 247.00 + 185.55 = 432.55
        $this->assertSame(43255, $charge->amount_agorot);
        // VAT once, on the whole: 432.55 × 18% = 77.859 → 77.86
        $this->assertSame(7786, $charge->vat_agorot);
        $this->assertSame(51041, $charge->total_agorot);

        $lines = collect($charge->lines);
        $this->assertSame(['plan', 'extra_numbers', 'messages'], $lines->pluck('kind')->all());
        // What Linet bills is what the card was charged — to the agora.
        $this->assertSame($charge->total_agorot, collect($charge->invoiceLines())->sum(fn (array $l): int => $l['qty'] * $l['unit_price_agorot']));
        $this->assertSame(11564, $lines->firstWhere('kind', 'extra_numbers')['unit_price_agorot']);
        $this->assertSame(21895, $lines->firstWhere('kind', 'messages')['unit_price_agorot']);
        $this->assertStringContainsString('1,237', $lines->firstWhere('kind', 'messages')['name']);
    }

    public function test_a_successful_charge_marks_what_it_billed_and_the_next_one_starts_there(): void
    {
        $this->sendMessages(10);
        $this->chargeSucceeds();
        $first = $this->subscription->charges()->sole();

        $this->assertSame(10, SiteAgentUsage::where('charge_id', $first->id)->count());

        // A month later: only what was sent since.
        $this->travel(1)->month();
        $this->sendMessages(4);
        $this->subscription->refresh()->update(['next_charge_at' => now()->subHour()]);
        $this->chargeSucceeds();

        $second = $this->subscription->charges()->latest('id')->first();
        $this->assertSame(4, collect($second->lines)->firstWhere('kind', 'messages')['count']);
        $this->assertSame(4, SiteAgentUsage::where('charge_id', $second->id)->count());
    }

    public function test_a_failed_charge_bills_nothing_and_the_retry_counts_them_again(): void
    {
        $this->sendMessages(6);

        $this->charge(success: false);
        $failed = $this->subscription->charges()->sole();

        $this->assertSame(ChargeStatus::Failed, $failed->status);
        $this->assertSame(0, SiteAgentUsage::whereNotNull('charge_id')->count());

        // The retry collects the same six — and nothing has been billed twice.
        $this->subscription->refresh()->update(['next_charge_at' => now()->subMinute()]);
        $this->chargeSucceeds();

        $paid = $this->subscription->charges()->where('status', ChargeStatus::Succeeded)->sole();
        $this->assertSame(6, collect($paid->lines)->firstWhere('kind', 'messages')['count']);
        $this->assertSame(6, SiteAgentUsage::where('charge_id', $paid->id)->count());
    }

    public function test_a_payment_recorded_by_hand_bills_the_messages_too(): void
    {
        $this->sendMessages(3);

        $charge = app(SubscriptionCollectionService::class)->recordPayment($this->subscription);

        $this->assertSame(3, collect($charge->lines)->firstWhere('kind', 'messages')['count']);
        $this->assertSame(3, SiteAgentUsage::where('charge_id', $charge->id)->count());
    }

    public function test_the_portal_shows_the_cycle_so_far(): void
    {
        $this->sendMessages(12);

        $this->withSession(['portal.customer_id' => $this->customer->id])
            ->get(route('portal.site-agent'))
            ->assertOk()
            ->assertSee('השימוש במחזור הנוכחי')
            ->assertSee('12 ×', false);
    }

    public function test_the_estimate_is_computed_like_the_charge(): void
    {
        $this->sendMessages(7);

        $usage = app(SiteAgentUsageMeter::class)->current($this->subscription);

        // 7 × 0.15 = 1.05 net → 1.24 with VAT, computed on the total and not
        // as 7 × 0.18 (which would say 1.26).
        $this->assertSame(124, $usage['estimate_gross_agorot']);
        $this->assertSame(7, $usage['billable']);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function sendMessages(int $count): void
    {
        $meter = app(SiteAgentUsageMeter::class);

        for ($i = 0; $i < $count; $i++) {
            $meter->record($this->number, 'wamid.'.uniqid('', true));
        }
    }

    private function chargeSucceeds(): void
    {
        $this->charge(success: true);
    }

    private function charge(bool $success): void
    {
        $this->subscription->update(['token_id' => $this->subscription->token_id ?? $this->token()]);

        $this->mock(CardcomClient::class, function ($mock) use ($success) {
            $mock->shouldReceive('chargeToken')->once()->andReturn(new ChargeResult(
                success: $success,
                transactionId: $success ? 'tx-'.uniqid() : null,
                responseCode: $success ? '0' : '33',
                message: $success ? null : 'Refused',
            ));
        });

        ChargeSubscriptionJob::dispatchSync($this->subscription->id);
    }

    private function token(): int
    {
        return PaymentToken::factory()->create(['customer_id' => $this->customer->id])->id;
    }
}
