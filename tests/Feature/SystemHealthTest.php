<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Middleware\ThrottleHealthProbe;
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
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
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
        HealthHeartbeat::beat(HealthHeartbeat::WORKLOAD);
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

    /**
     * The two workers are separate processes: the private heartbeat queue can
     * go on answering long after the one running charges and invoices died.
     * That is a system reporting "ok" while nothing gets done.
     */
    public function test_a_workload_queue_that_stopped_moving_is_reported(): void
    {
        $this->alive();
        HealthHeartbeat::query()->whereKey(HealthHeartbeat::WORKLOAD)
            ->update(['beat_at' => now()->subHours(3)]);

        $report = app(HealthReport::class)->collect();

        // Degraded, not down: a long backup on that queue delays the beat in
        // exactly the same way, and an endpoint that calls a busy system dead
        // is one nobody trusts the next time it complains.
        $this->assertSame(HealthReport::DEGRADED, $report['status']);
        $this->assertSame(
            HealthReport::DEGRADED,
            collect($report['checks'])->firstWhere('key', 'workload')['status'],
        );
    }

    public function test_the_workload_heartbeat_queues_where_the_real_work_does(): void
    {
        // Same job, the ordinary queue — the isolated one cannot answer for it.
        $this->assertNotSame(HeartbeatJob::QUEUE, (new HeartbeatJob(HealthHeartbeat::WORKLOAD))->queue);

        (new HeartbeatJob(HealthHeartbeat::WORKLOAD))->handle();

        $this->assertNotNull(HealthHeartbeat::lastBeat(HealthHeartbeat::WORKLOAD));
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

    /**
     * A payment link the operator opened and sent by hand carries no demand
     * date at all — and it is still a customer taking their time, not a stalled
     * process. Nothing about it is a fault.
     */
    public function test_a_hosted_payment_page_sent_by_hand_is_not_called_stuck(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $charge = $this->charge(ChargeStatus::Pending, extra: ['cardcom_low_profile_id' => 'lp-2']);
        $charge->timestamps = false;
        $charge->forceFill(['created_at' => now()->subDays(2)])->save();

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertNothingSent();
    }

    /**
     * The mail is best-effort — no team address configured, or a delivery that
     * failed. The log entry is then the only surviving copy, and "3 charges
     * without an invoice" without saying which three is a hunt with no needle.
     */
    public function test_the_log_entry_names_every_row_even_past_the_email_cap(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        // More findings than the mail lists: the mail shows a sample, the log
        // keeps them all — it is the copy the mail points at, and the only one
        // when there is nobody to mail.
        $charges = collect(range(1, 13))->map(function (): Charge {
            $charge = $this->charge(ChargeStatus::Succeeded, extra: ['charged_at' => now()->subHours(6)]);

            return $charge;
        });

        (new CheckMoneyIntegrityJob)->handle();

        $context = json_encode(
            SystemLog::where('source', 'billing')->latest('id')->first()?->context,
            JSON_UNESCAPED_UNICODE,
        );

        foreach ($charges as $charge) {
            $this->assertStringContainsString("חיוב #{$charge->id}", (string) $context);
        }

        // …and the mail is still a readable sample rather than a wall.
        Mail::assertSent(fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'ועוד 3…'));
    }

    /**
     * A saved-card charge goes to Cardcom as "manual-{id}" and never has a
     * hosted page — and it is exactly the one that stays pending when the
     * worker dies between asking for the money and writing down the answer.
     */
    public function test_a_saved_card_charge_left_pending_is_reported_too(): void
    {
        Mail::fake();
        config(['billing.notifications.team_email' => 'team@example.com']);

        $charge = $this->charge(ChargeStatus::Pending);
        $charge->timestamps = false;
        $charge->forceFill(['created_at' => now()->subDays(2)])->save();

        (new CheckMoneyIntegrityJob)->handle();

        Mail::assertSent(fn (NotificationMail $mail): bool => str_contains($mail->bodyText, 'נתקעו')
            && str_contains($mail->bodyText, "חיוב #{$charge->id}"));
    }

    /**
     * The probe must never wait behind the work it is measuring: a heartbeat
     * queued behind a long backup would go stale and report a busy, healthy
     * system as a dead one.
     */
    public function test_the_heartbeat_rides_its_own_queue(): void
    {
        $this->assertSame(HeartbeatJob::QUEUE, (new HeartbeatJob)->queue);
        $this->assertContains(
            HeartbeatJob::QUEUE,
            (array) config('horizon.defaults.supervisor-1.queue'),
            'The heartbeat queue must be served by the worker, or /health reports a permanent false alarm.',
        );
    }

    /**
     * A database that TIMES OUT rather than refusing: every check below it
     * would wait out its own connect timeout in turn, and the endpoint whose
     * job is to say "down" quickly would instead hold the request open.
     */
    public function test_a_dead_database_is_answered_alone(): void
    {
        $original = config('database.default');

        try {
            config(['database.default' => 'no-such-connection']);
            $report = app(HealthReport::class)->collect();
        } finally {
            // Restored before the test ends: the rollback that follows needs
            // the real connection back.
            config(['database.default' => $original]);
        }

        $this->assertSame(HealthReport::DOWN, $report['status']);
        $this->assertCount(1, $report['checks']);
        $this->assertSame('database', $report['checks'][0]['key']);
    }

    /**
     * The endpoint must not need the database in order to report that the
     * database is gone. The session driver IS the database by default, and the
     * session opens in middleware — before the controller ever runs.
     */
    public function test_the_probe_reaches_the_controller_without_touching_the_database(): void
    {
        $route = Route::getRoutes()->getByName('health');
        $middleware = app(Router::class)->gatherRouteMiddleware($route);

        foreach ([StartSession::class, ValidateCsrfToken::class, ShareErrorsFromSession::class] as $stateful) {
            $this->assertNotContains($stateful, $middleware);
        }

        // Rate limiting stays, on a store of its own rather than the default
        // one (which is the database in production).
        $this->assertContains(ThrottleHealthProbe::class.':60', $middleware);
        $this->assertNotSame(config('cache.default'), config('health.throttle_store'));
    }

    /**
     * The counter has to survive a burst: read-then-write would let concurrent
     * requests overwrite each other's hit and crawl while everything got
     * through — the exact case a limiter is for.
     */
    public function test_the_probe_limiter_counts_every_hit(): void
    {
        config(['health.throttle_store' => 'array']);

        $middleware = new ThrottleHealthProbe;
        $request = Request::create('/health');
        $answer = fn () => $middleware->handle($request, fn (): Response => new Response('ok'), 2);

        $this->assertSame(200, $answer()->getStatusCode());
        $this->assertSame(200, $answer()->getStatusCode());
        $this->assertSame(429, $answer()->getStatusCode());
    }

    /** An unreachable counter must not silence the answer to "is the system alive". */
    public function test_the_probe_answers_even_when_its_counter_is_unreachable(): void
    {
        config(['health.throttle_store' => 'no-such-store']);
        $this->alive();

        $this->getJson('/health')->assertOk();
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
