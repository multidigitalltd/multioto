<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Jobs\SendDemandRemindersJob;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Billing\DemandDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DemandReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.demands.reminder_interval_days' => 3,
            'billing.demands.max_reminders' => 2,
            'billing.cardcom.reconcile_max_age_days' => 14,
            'mail.from.name' => 'מולטי דיגיטל',
        ]);
    }

    private function demand(array $overrides = []): Charge
    {
        // created_at is managed by the timestamps mechanism, so apply any override
        // via a direct update after creation.
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $customer = Customer::factory()->create(['email' => 'pay@example.co.il']);

        $charge = Charge::create(array_merge([
            'subscription_id' => null,
            'customer_id' => $customer->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => 'ייעוץ',
            'cardcom_pay_url' => 'https://secure.cardcom.test/lp/ABC',
            'demand_sent_at' => now()->subDays(4),
            'demand_channel' => 'email',
            'demand_reminder_count' => 0,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ], $overrides));

        if ($createdAt !== null) {
            Charge::whereKey($charge->id)->update(['created_at' => $createdAt]);
            $charge->refresh();
        }

        return $charge;
    }

    public function test_an_unpaid_demand_past_the_interval_is_reminded(): void
    {
        Mail::fake();
        $charge = $this->demand();

        (new SendDemandRemindersJob)->handle(app(DemandDispatcher::class));

        // The reminder goes out with the cancelable link and a reminder tone…
        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo('pay@example.co.il')
            && str_contains($mail->bodyText, 'תזכורת')
            && str_contains($mail->bodyText, '/billing/pay/'.$charge->id));

        // …and the charge is stamped so the next reminder spaces out and caps.
        $charge->refresh();
        $this->assertSame(1, $charge->demand_reminder_count);
        $this->assertTrue($charge->demand_sent_at->isAfter(now()->subMinute()));
    }

    public function test_a_recent_demand_is_not_reminded(): void
    {
        Mail::fake();
        $this->demand(['demand_sent_at' => now()->subDay()]); // within the 3-day interval

        (new SendDemandRemindersJob)->handle(app(DemandDispatcher::class));

        Mail::assertNothingSent();
    }

    public function test_reminders_stop_after_the_maximum(): void
    {
        Mail::fake();
        $this->demand(['demand_reminder_count' => 2]); // already at the cap

        (new SendDemandRemindersJob)->handle(app(DemandDispatcher::class));

        Mail::assertNothingSent();
    }

    public function test_a_paid_or_canceled_demand_is_not_reminded(): void
    {
        Mail::fake();
        $this->demand(['status' => ChargeStatus::Succeeded]);
        $this->demand(['status' => ChargeStatus::Canceled]);

        (new SendDemandRemindersJob)->handle(app(DemandDispatcher::class));

        Mail::assertNothingSent();
    }

    public function test_an_abandoned_old_demand_is_left_alone(): void
    {
        Mail::fake();
        // Older than the max-age window — stop chasing it.
        $this->demand(['created_at' => now()->subDays(30), 'demand_sent_at' => now()->subDays(20)]);

        (new SendDemandRemindersJob)->handle(app(DemandDispatcher::class));

        Mail::assertNothingSent();
    }

    public function test_reminders_can_be_disabled(): void
    {
        Mail::fake();
        config(['billing.demands.max_reminders' => 0]);
        $this->demand();

        (new SendDemandRemindersJob)->handle(app(DemandDispatcher::class));

        Mail::assertNothingSent();
    }
}
