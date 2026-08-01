<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\CheckMoneyIntegrityJob;
use App\Jobs\HeartbeatJob;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\HealthHeartbeat;
use App\Models\Invoice;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\System\HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The machinery that watches the machinery.
 *
 * Everything the business does runs in a queued job dispatched by the
 * scheduler. When one of those stops, nothing fails — so these tests defend
 * the only two things that can tell the difference between a quiet night and a
 * dead one: a heartbeat that stopped, and money that no longer adds up.
 */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The backup check has its own tests; here it would report "no backup
        // has ever run" on every case and hide what is being asked.
        config(['backup.enabled' => false]);
    }

    private function alive(): void
    {
        HealthHeartbeat::beat(HealthHeartbeat::SCHEDULER);
        HealthHeartbeat::beat(HealthHeartbeat::QUEUE);
    }

    public function test_a_system_with_both_heartbeats_is_healthy(): void
    {
        $this->alive();

        $this->assertSame(HealthReport::OK, app(HealthReport::class)->status());
    }

    public function test_a_scheduler_that_stopped_reporting_is_down(): void
    {
        $this->alive();
        HealthHeartbeat::query()->whereKey(HealthHeartbeat::SCHEDULER)
            ->update(['beat_at' => now()->subHour()]);

        $report = app(HealthReport::class)->collect();

        $this->assertSame(HealthReport::DOWN, $report['status']);
        $this->assertSame(
            HealthReport::DOWN,
            collect($report['checks'])->firstWhere('key', 'scheduler')['status'],
        );
    }

    /**
     * The case a "can we reach the queue" check would miss entirely: jobs are
     * accepted, nothing throws, and nobody runs them.
     */
    public function test_a_queue_nobody_is_working_is_down(): void
    {
        $this->alive();
        HealthHeartbeat::query()->whereKey(HealthHeartbeat::QUEUE)
            ->update(['beat_at' => now()->subHours(2)]);

        $this->assertSame(HealthReport::DOWN, app(HealthReport::class)->status());
    }

    public function test_a_part_that_never_reported_is_not_treated_as_healthy(): void
    {
        // Nothing has ever beaten — a fresh install, or a worker that never
        // started. Silence is not proof of life either way.
        $this->assertSame(HealthReport::DOWN, app(HealthReport::class)->status());
    }

    /**
     * A backup that has not run is not "the system is down" — everything is
     * still working — but it is exactly the kind of thing that stays invisible
     * until the day it matters.
     */
    public function test_a_missing_backup_is_reported_as_needing_attention(): void
    {
        config(['backup.enabled' => true]);
        $this->alive();

        $report = app(HealthReport::class)->collect();

        $this->assertSame(HealthReport::DEGRADED, $report['status']);
        $this->assertSame(
            HealthReport::DEGRADED,
            collect($report['checks'])->firstWhere('key', 'backup')['status'],
        );
    }

    public function test_the_queue_heartbeat_is_stamped_by_the_job_itself(): void
    {
        (new HeartbeatJob)->handle();

        $this->assertNotNull(HealthHeartbeat::lastBeat(HealthHeartbeat::QUEUE));
    }

    /*
    | ----------------------------------------------------------------
    | The endpoint an external monitor asks
    | ----------------------------------------------------------------
    */

    public function test_the_health_endpoint_answers_without_details_by_default(): void
    {
        $this->alive();

        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_the_health_endpoint_fails_the_check_when_a_part_stopped(): void
    {
        // 503 is the whole point: an uptime monitor needs no configuration to
        // alarm on it.
        $this->getJson('/health')->assertStatus(503);
    }

    public function test_details_are_only_given_to_the_holder_of_the_token(): void
    {
        config(['health.token' => 'secret-token']);
        $this->alive();

        $this->getJson('/health')->assertOk()->assertJsonMissingPath('checks');
        $this->getJson('/health?token=wrong')->assertOk()->assertJsonMissingPath('checks');
        $this->getJson('/health?token=secret-token')->assertOk()->assertJsonPath('checks.0.key', 'database');
    }

    /*
    | ----------------------------------------------------------------
    | Does the money still add up
    | ----------------------------------------------------------------
    */

    private function customer(): Customer
    {
        return Customer::factory()->create();
    }

    private function charge(ChargeStatus $status, int $total = 11800, array $extra = []): Charge
    {
        return Charge::create(array_merge([
            'customer_id' => $this->customer()->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => $total,
            'currency' => 'ILS',
            'status' => $status,
            'attempt_number' => 1,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ], $extra));
    }

    public function test_a_clean_month_says_nothing_at_all(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $charge = $this->charge(ChargeStatus::Succeeded);
        Invoice::create([
            'charge_id' => $charge->id,
            'customer_id' => $charge->customer_id,
            'linet_document_id' => 'D-1',
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'issued_at' => now(),
        ]);

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertNothingSent();
        $this->assertSame(0, SystemLog::where('source', 'billing')->count());
    }

    public function test_money_taken_without_an_invoice_is_reported(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $charge = $this->charge(ChargeStatus::Succeeded);
        // Older than the grace the async invoice job is given.
        $charge->timestamps = false;
        $charge->forceFill(['created_at' => now()->subHours(6)])->save();

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertSent(fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'ללא חשבונית')
            && str_contains($mail->bodyText, "חיוב #{$charge->id}"));
        $this->assertSame(1, SystemLog::where('source', 'billing')->where('level', 'error')->count());
    }

    public function test_an_invoice_on_a_charge_that_failed_is_reported(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $charge = $this->charge(ChargeStatus::Failed);
        Invoice::create([
            'charge_id' => $charge->id,
            'customer_id' => $charge->customer_id,
            'linet_document_id' => 'D-2',
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'issued_at' => now(),
        ]);

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertSent(fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'חיוב שלא הצליח'));
    }

    public function test_a_document_that_disagrees_with_its_charge_is_reported(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $charge = $this->charge(ChargeStatus::Succeeded, total: 11800);
        Invoice::create([
            'charge_id' => $charge->id,
            'customer_id' => $charge->customer_id,
            'linet_document_id' => 'D-3',
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 23600, // billed twice what was taken
            'issued_at' => now(),
        ]);

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertSent(fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'פערי סכום'));
    }

    public function test_a_subscription_whose_charge_date_passed_is_reported(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);
        Carbon::setTestNow('2026-08-01 10:00:00');

        $customer = $this->customer();
        $plan = Plan::factory()->create();
        $token = PaymentToken::create([
            'customer_id' => $customer->id,
            'cardcom_token' => 'tok-1',
            'last_four' => '1234',
            'is_default' => true,
        ]);

        Subscription::create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'token_id' => $token->id,
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->subDay(),
        ]);

        (new CheckMoneyIntegrityJob)->handle();

        // The dispatcher runs every fifteen minutes — a day later means the
        // pipeline is stuck, not that the date has not arrived.
        Mail::assertSent(fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'עבר מועד החיוב'));
    }

    /**
     * A demand can sit unpaid for a fortnight. The invoice job starts when it
     * is PAID, so judging it by the day the demand was opened would report
     * every payment that came in this morning.
     */
    public function test_a_demand_paid_moments_ago_is_given_its_grace(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $charge = $this->charge(ChargeStatus::Succeeded, extra: ['charged_at' => now()->subMinutes(5)]);
        $charge->timestamps = false;
        $charge->forceFill(['created_at' => now()->subDays(9)])->save();

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertNothingSent();
    }

    /**
     * Charging pauses for Shabbat and Yom Tov by design, so a renewal due at
     * midnight is "late" every rest day. A report that cries wolf every
     * Saturday is a report nobody opens on Monday.
     */
    public function test_the_shabbat_pause_is_not_reported_as_money_going_missing(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);
        // Shabbat morning, with the pause switched on.
        config(['billing.shabbat.block_automations' => true]);
        Carbon::setTestNow(Carbon::parse('2026-08-08 08:15', 'Asia/Jerusalem'));

        $customer = $this->customer();
        $token = PaymentToken::create([
            'customer_id' => $customer->id,
            'cardcom_token' => 'tok-2',
            'last_four' => '4321',
            'is_default' => true,
        ]);
        Subscription::create([
            'customer_id' => $customer->id,
            'plan_id' => Plan::factory()->create()->id,
            'token_id' => $token->id,
            'status' => SubscriptionStatus::Active,
            'next_charge_at' => now()->subHours(8),
        ]);

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertNothingSent();
    }

    /**
     * The invoice failed on a demand that was open for weeks before it was
     * paid. Judging the lookback window by the day the demand was opened would
     * file the finding away as ancient history the moment it happened.
     */
    public function test_a_long_open_demand_paid_today_is_still_checked(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $charge = $this->charge(ChargeStatus::Succeeded, extra: ['charged_at' => now()->subHours(6)]);
        $charge->timestamps = false;
        $charge->forceFill(['created_at' => now()->subDays(40)])->save();

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertSent(fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'ללא חשבונית'));
    }

    /**
     * A demand waiting for the customer is not a fault — that is what the due
     * date is for, and the reminders chase it. Listing every open demand each
     * morning is how the report stops being read.
     */
    public function test_an_ordinary_open_demand_is_not_called_stuck(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $charge = $this->charge(ChargeStatus::Pending, extra: [
            'cardcom_low_profile_id' => 'lp-1',
            'demand_sent_at' => now()->subDays(3),
            'due_at' => now()->addDays(11),
        ]);
        $charge->timestamps = false;
        $charge->forceFill(['created_at' => now()->subDays(3)])->save();

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertNothingSent();
    }

    public function test_a_charge_whose_outcome_was_never_learned_is_reported(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        // A hosted page opened for a walk-in charge, no demand behind it: we
        // asked Cardcom for money and never found out what happened.
        $charge = $this->charge(ChargeStatus::Pending, extra: ['cardcom_low_profile_id' => 'lp-2']);
        $charge->timestamps = false;
        $charge->forceFill(['created_at' => now()->subDays(2)])->save();

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertSent(fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'נתקעו'));
    }

    /**
     * The mail is best-effort — no team address configured, or a delivery that
     * failed. The log entry is then the only surviving copy, and "3 charges
     * without an invoice" without saying which three is a hunt with no needle.
     */
    public function test_the_log_entry_names_the_rows_and_not_only_the_counts(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => null]);

        $charge = $this->charge(ChargeStatus::Succeeded, extra: ['charged_at' => now()->subHours(6)]);

        (new CheckMoneyIntegrityJob)->handle();

        $log = SystemLog::where('source', 'billing')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString("חיוב #{$charge->id}", json_encode($log->context, JSON_UNESCAPED_UNICODE));
    }

    public function test_nothing_is_repaired_by_the_check(): void
    {
        Mail::fake();
        $charge = $this->charge(ChargeStatus::Succeeded);
        $charge->timestamps = false;
        $charge->forceFill(['created_at' => now()->subHours(6)])->save();

        (new CheckMoneyIntegrityJob)->handle();

        // Reporting only: money is never corrected by a background job.
        $this->assertSame(ChargeStatus::Succeeded, $charge->fresh()->status);
        $this->assertSame(0, Invoice::count());
    }
}
