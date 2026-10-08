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
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use App\Services\Billing\SiteAgentArrearsBilling;
use App\Services\Billing\SubscriptionCollectionService;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\ChargeResult;
use App\Services\SiteAgent\SiteAgentMessageCap;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use App\Services\SiteAgent\WhatsAppCloudClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
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

        $this->freezeTime();

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
            'agent_extra_numbers' => 0,
            ...SiteAgentArrearsBilling::initializeDates(now()),
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

        $this->assertSame(['plan'], array_column($this->subscription->charges()->sole()->lines, 'kind'));
        $this->assertFalse(SiteAgentUsage::sole()->billable);
    }

    public function test_a_plan_that_does_not_price_messages_never_bills_them(): void
    {
        $this->plan->update(['message_price_agorot' => null]);

        app(SiteAgentUsageMeter::class)->record($this->number, 'wamid.free');

        // And pricing them later does not reach back.
        $this->plan->update(['message_price_agorot' => 15]);
        $this->chargeSucceeds();

        $this->assertSame(['plan'], array_column($this->subscription->charges()->sole()->lines, 'kind'));
    }

    public function test_a_renewal_with_nothing_extra_is_exactly_what_it_was(): void
    {
        $this->chargeSucceeds();

        $charge = $this->subscription->charges()->sole();

        $this->assertSame(['plan'], array_column($charge->lines, 'kind'));
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
        $this->travel(20)->days();
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

        $this->travelTo(SiteAgentArrearsBilling::period($this->subscription)['end']);
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

    public function test_only_messages_beyond_the_included_ones_are_charged(): void
    {
        $this->plan->update(['included_messages' => 300]);
        $this->sendMessages(400);

        $this->chargeSucceeds();
        $charge = $this->subscription->charges()->sole();
        $line = collect($charge->lines)->firstWhere('kind', 'messages');

        // 149.00 + 100 × 0.15 = 164.00 net.
        $this->assertSame(16400, $charge->amount_agorot);
        $this->assertStringContainsString('300 כלולות', $line['name']);
        $this->assertSame($charge->total_agorot, collect($charge->invoiceLines())->sum(fn (array $l): int => $l['qty'] * $l['unit_price_agorot']));
        // All 400 are counted as billed, not only the 100 charged.
        $this->assertSame(400, SiteAgentUsage::where('charge_id', $charge->id)->count());
    }

    public function test_messages_all_within_the_included_ones_are_counted_and_not_counted_again(): void
    {
        $this->plan->update(['included_messages' => 300]);
        $this->sendMessages(120);

        $this->chargeSucceeds();
        $first = $this->subscription->charges()->sole();

        // The invoice is the plain plan, exactly as before…
        $this->assertSame(['plan'], array_column($first->lines, 'kind'));
        $this->assertSame(14900, $first->amount_agorot);
        // …and the 120 are stamped all the same, so next month starts at zero.
        $this->assertSame(120, SiteAgentUsage::where('charge_id', $first->id)->count());

        $this->travel(20)->days();
        $this->sendMessages(350);
        $this->subscription->refresh()->update(['next_charge_at' => now()->subHour()]);
        $this->chargeSucceeds();

        $second = $this->subscription->charges()->latest('id')->first();
        // 350 this cycle, 300 included: 50 charged — not 170.
        $this->assertSame(14900 + 50 * 15, $second->amount_agorot);
    }

    public function test_the_estimate_leaves_the_included_messages_out(): void
    {
        $this->plan->update(['included_messages' => 5]);
        $this->sendMessages(7);

        $usage = app(SiteAgentUsageMeter::class)->current($this->subscription->refresh());

        // 2 × 0.15 = 0.30 net → 0.35 with VAT.
        $this->assertSame(35, $usage['estimate_gross_agorot']);
        $this->assertSame(5, $usage['included']);
    }

    public function test_the_cap_is_reached_at_the_count_the_invoice_will_carry(): void
    {
        $meter = app(SiteAgentUsageMeter::class);
        $this->subscription->update(['site_agent_message_cap' => 10]);

        $this->sendMessages(7);
        $this->assertFalse($meter->capReached($this->subscription));
        $this->assertFalse($meter->shouldWarn($this->subscription));

        $this->sendMessages(1);
        // 8 of 10: the 80% notice, once.
        $this->assertTrue($meter->shouldWarn($this->subscription));
        $this->subscription->update(['site_agent_cap_warned_at' => now()]);
        $this->assertFalse($meter->shouldWarn($this->subscription->refresh()));

        $this->sendMessages(2);
        $this->assertTrue($meter->capReached($this->subscription));
    }

    public function test_the_portal_sets_and_removes_the_cap(): void
    {
        $portal = $this->withSession(['portal.customer_id' => $this->customer->id]);

        $portal->post(route('portal.site-agent.cap'), ['cap' => 500])->assertRedirect();
        $this->assertSame(500, $this->subscription->refresh()->site_agent_message_cap);

        $portal->post(route('portal.site-agent.cap'), ['cap' => 0])->assertSessionHasErrorsIn('cap', 'cap');
        $this->assertSame(500, $this->subscription->refresh()->site_agent_message_cap);

        $portal->post(route('portal.site-agent.cap'), ['cap' => ''])->assertRedirect();
        $this->assertNull($this->subscription->refresh()->site_agent_message_cap);
    }

    public function test_a_message_over_the_ceiling_is_delivered_but_never_billed(): void
    {
        // Two workers that both passed the "under the cap?" check before
        // either recorded: the ceiling is held where the row is written.
        $this->subscription->update(['site_agent_message_cap' => 2]);

        $this->sendMessages(3);

        $this->assertSame(3, SiteAgentUsage::count());
        $this->assertSame(2, SiteAgentUsage::where('billable', true)->count());
    }

    public function test_the_eighty_percent_notice_is_kept_until_it_is_actually_delivered(): void
    {
        $this->subscription->update(['site_agent_message_cap' => 5]);
        $this->sendMessages(4);
        $cap = app(SiteAgentMessageCap::class);

        $failing = Mockery::mock(WhatsAppCloudClient::class);
        $failing->shouldReceive('sendText')->once()->andReturn(null);
        $cap->warnIfDue($failing, '972501111111', $this->subscription->refresh());

        // Not delivered: still due.
        $this->assertNull($this->subscription->refresh()->site_agent_cap_warned_at);

        $working = Mockery::mock(WhatsAppCloudClient::class);
        $working->shouldReceive('sendText')->once()->andReturn('wamid.warn');
        $cap->warnIfDue($working, '972501111111', $this->subscription->refresh());
        // Delivered once; a second worker does not send it again.
        $cap->warnIfDue($working, '972501111111', $this->subscription->refresh());

        $this->assertNotNull($this->subscription->refresh()->site_agent_cap_warned_at);
    }

    public function test_a_writing_unit_is_text_on_the_site_beyond_the_threshold(): void
    {
        $words = fn (int $n): string => implode(' ', array_fill(0, $n, 'מילה'));

        $this->assertSame(350, SiteAgentUsageMeter::writingWords(['fields' => ['title' => $words(50), 'content' => '<p>'.$words(350).'</p>']]));
        $this->assertSame(320, SiteAgentUsageMeter::writingWords(['text' => $words(320)]));
        $this->assertSame(0, SiteAgentUsageMeter::writingWords(['fields' => ['regular_price' => '90']]));
        // Compact WordPress HTML: adjacent tags are word boundaries.
        $this->assertSame(4, SiteAgentUsageMeter::writingWords(['fields' => ['content' => '<p>אחת</p><p>שתיים</p><ul><li>שלוש</li><li>ארבע</li></ul>']]));
    }

    public function test_a_long_text_is_one_unit_per_offer_and_a_short_one_none(): void
    {
        $this->plan->update(['writing_price_agorot' => 1500]);

        $long = $this->applied(['fields' => ['content' => implode(' ', array_fill(0, 301, 'מילה'))]]);
        $short = $this->applied(['fields' => ['content' => implode(' ', array_fill(0, 300, 'מילה'))]]);
        $meter = app(SiteAgentUsageMeter::class);

        $meter->recordWriting($long);
        $meter->recordWriting($long); // a retry
        $meter->recordWriting($short);

        $this->assertSame(1, SiteAgentUsage::where('kind', SiteAgentUsage::WRITING)->count());
        $this->assertSame(301, SiteAgentUsage::where('kind', SiteAgentUsage::WRITING)->value('words'));
        // Not a message: the message count and the cap are untouched.
        $this->assertSame(0, $meter->unbilled($this->subscription, now()));
    }

    public function test_writing_units_beyond_the_included_ones_get_their_own_line(): void
    {
        $this->plan->update(['writing_price_agorot' => 1500, 'included_writings' => 1]);
        $meter = app(SiteAgentUsageMeter::class);

        foreach (range(1, 3) as $i) {
            $meter->recordWriting($this->applied(['text' => implode(' ', array_fill(0, 400, 'מילה'))]));
        }

        $this->chargeSucceeds();
        $charge = $this->subscription->charges()->sole();
        $line = collect($charge->lines)->firstWhere('kind', 'writings');

        // 149.00 + 2 × 15.00 = 179.00 net.
        $this->assertSame(17900, $charge->amount_agorot);
        $this->assertStringContainsString('1 כלולים', $line['name']);
        $this->assertSame($charge->total_agorot, collect($charge->invoiceLines())->sum(fn (array $l): int => $l['qty'] * $l['unit_price_agorot']));
        $this->assertSame(3, SiteAgentUsage::where('kind', SiteAgentUsage::WRITING)->where('charge_id', $charge->id)->count());
    }

    public function test_the_offer_says_it_will_cost_a_writing_unit_before_the_yes(): void
    {
        $this->plan->update(['writing_price_agorot' => 1500]);

        $request = SiteAgentRequest::create([
            'site_agent_subscriber_id' => $this->number->id, 'site_id' => $this->site->id, 'customer_id' => $this->customer->id,
            'message' => 'פוסט', 'operation' => SiteAgentRequest::OP_POST_CREATE, 'state' => SiteAgentRequest::AWAITING,
            'plan' => ['operation' => SiteAgentRequest::OP_POST_CREATE, 'fields' => ['content' => implode(' ', array_fill(0, 450, 'מילה'))], 'summary' => 'פוסט'],
            'preview' => '📝 פוסט חדש', 'expires_at' => now()->addHour(),
        ]);

        $this->assertStringContainsString('טקסט של 450 מילים', $request->preview);
        $this->assertStringContainsString('17.70', $request->preview); // 15.00 + 18% VAT

        // A plan that does not price writing discloses nothing.
        $this->plan->update(['writing_price_agorot' => null]);
        $request->update(['preview' => '📝 פוסט מעודכן']);
        $this->assertStringNotContainsString('✍️', $request->refresh()->preview);
    }

    public function test_a_remaining_included_unit_is_mentioned_but_never_promised(): void
    {
        $this->plan->update(['writing_price_agorot' => 1500, 'included_writings' => 2]);

        $request = SiteAgentRequest::create([
            'site_agent_subscriber_id' => $this->number->id, 'site_id' => $this->site->id, 'customer_id' => $this->customer->id,
            'message' => 'פוסט', 'operation' => SiteAgentRequest::OP_POST_CREATE, 'state' => SiteAgentRequest::AWAITING,
            'plan' => ['operation' => SiteAgentRequest::OP_POST_CREATE, 'fields' => ['content' => implode(' ', array_fill(0, 450, 'מילה'))], 'summary' => 'פוסט'],
            'preview' => '📝 פוסט חדש', 'expires_at' => now()->addHour(),
        ]);

        // The price is always stated; the allowance is a possibility, because
        // another number may use the last included unit before this "כן".
        $this->assertStringContainsString('17.70', $request->preview);
        $this->assertStringContainsString('אלא אם עדיין נשארו', $request->preview);
    }

    public function test_a_message_in_the_same_second_as_the_count_waits_for_the_next_charge(): void
    {
        // הזמן קפוא, כי "באותה שנייה" הוא מה שנבדק כאן ולא מה שהשעון מרשה.
        // sent_at נשמר בדיוק של שנייה, ו-RenewalBreakdown סופרת עד
        // now()->startOfSecond()->subSecond() — כך שבלי הקפאה די בגבול שנייה
        // שנחצה בין שליחת ההודעות לחיוב כדי שהן ייכנסו לחיוב הזה, והבדיקה
        // תיכשל על תזמון במקום על התנהגות. היא אכן נכשלה כך ב-CI.
        $this->freezeTime();
        $this->travelTo(SiteAgentArrearsBilling::period($this->subscription)['end']);

        $this->sendMessages(3);

        // Charged in the same second the messages went out: they were not on
        // this charge, so they are not stamped as billed by it.
        $this->charge(success: true, wait: false);

        $this->assertSame(0, SiteAgentUsage::whereNotNull('charge_id')->count());
        $this->assertSame(3, app(SiteAgentUsageMeter::class)->unbilled($this->subscription, now()->addMinute()));
    }

    public function test_messages_of_a_plan_that_stopped_pricing_them_are_closed_not_left_waiting(): void
    {
        $this->subscription->update(['site_agent_message_cap' => 3]);
        $this->sendMessages(3);
        $this->assertTrue(app(SiteAgentUsageMeter::class)->capReached($this->subscription->refresh()));

        $this->plan->update(['message_price_agorot' => null]);
        $this->chargeSucceeds();

        // Not billed, not stamped as paid, and no longer holding the ceiling shut.
        $this->assertSame(14900, $this->subscription->charges()->sole()->amount_agorot);
        $this->assertSame(0, SiteAgentUsage::whereNotNull('charge_id')->count());
        $this->assertSame(0, SiteAgentUsage::where('billable', true)->count());
        $this->assertFalse(app(SiteAgentUsageMeter::class)->capReached($this->subscription->refresh()));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $plan */
    private function applied(array $plan): SiteAgentRequest
    {
        return SiteAgentRequest::create([
            'site_agent_subscriber_id' => $this->number->id, 'site_id' => $this->site->id, 'customer_id' => $this->customer->id,
            'message' => 'כתיבה', 'operation' => SiteAgentRequest::OP_POST_CREATE, 'state' => SiteAgentRequest::APPLIED,
            'plan' => ['operation' => SiteAgentRequest::OP_POST_CREATE, 'summary' => 'כתיבה', ...$plan],
            'applied_at' => now(), 'expires_at' => now()->addHour(),
        ]);
    }

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

    private function charge(bool $success, bool $wait = true): void
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

        // The renewal counts up to a whole second behind now; what was sent a
        // moment ago belongs to it.
        if ($wait) {
            $end = SiteAgentArrearsBilling::period($this->subscription->refresh())['end'];

            if ($end->isFuture()) {
                $this->travelTo($end);
            } else {
                $this->travel(2)->seconds();
            }
        }

        ChargeSubscriptionJob::dispatchSync($this->subscription->id);
    }

    private function token(): int
    {
        return PaymentToken::factory()->create(['customer_id' => $this->customer->id])->id;
    }
}
