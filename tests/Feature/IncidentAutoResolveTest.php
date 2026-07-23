<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\IncidentStatus;
use App\Enums\NotificationType;
use App\Jobs\MonitorSiteJob;
use App\Jobs\NotifyIncidentAutoResolvedJob;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\PendingAction;
use App\Models\Site;
use App\Services\Notifications\TeamNotifier;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The end-to-end auto-heal loop's last leg: a customer is proactively told
 * "זיהינו תקלה ותיקנו" when their site recovers after an APPROVED automation
 * fix executed during the incident — and told nothing otherwise.
 */
class IncidentAutoResolveTest extends TestCase
{
    use RefreshDatabase;

    /** A resolved incident + site + customer, with an optional executed fix. */
    private function siteWithIncident(?Customer $customer = null): array
    {
        $customer ??= Customer::factory()->create(['email' => 'owner@biz.co.il', 'phone' => '0501234567']);
        $site = Site::factory()->create(['customer_id' => $customer->id, 'domain' => 'healed.example.com']);
        $incident = $site->incidents()->create([
            'started_at' => now()->subMinutes(25),
            'resolved_at' => now(),
            'status' => IncidentStatus::Resolved,
        ]);

        return [$customer, $site, $incident];
    }

    private function executedFix(Site $site, string $type = 'site_fix'): PendingAction
    {
        return PendingAction::create([
            'type' => $type,
            'status' => ActionStatus::Executed,
            'customer_id' => $site->customer_id,
            'summary' => 'תיקון אוטומטי',
            'payload' => ['site_id' => $site->id, 'fix' => 'restart'],
            'proposed_by' => 'automation',
            'decided_at' => now()->subMinutes(10),
            'executed_at' => now()->subMinutes(10),
        ]);
    }

    public function test_recovery_after_an_executed_fix_notifies_the_customer_on_both_channels(): void
    {
        Mail::fake();
        $waha = Mockery::mock(WahaClient::class);
        $waha->shouldReceive('sendMessage')->once()
            ->withArgs(fn (string $to, string $body): bool => str_contains($body, 'healed.example.com'));
        $this->app->instance(WahaClient::class, $waha);

        [, $site, $incident] = $this->siteWithIncident();
        $this->executedFix($site);

        NotifyIncidentAutoResolvedJob::dispatchSync($site->id, $incident->id);

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m): bool => $m->hasTo('owner@biz.co.il')
            && str_contains($m->bodyText, 'healed.example.com')
            && str_contains($m->bodyText, 'הפעלה מחדש'));
        $this->assertSame(1, NotificationLog::where('type', NotificationType::IncidentResolved->value)
            ->where('channel', 'email')->count());
        $this->assertSame(1, NotificationLog::where('type', NotificationType::IncidentResolved->value)
            ->where('channel', 'whatsapp')->count());
    }

    public function test_a_site_that_recovered_on_its_own_sends_nothing(): void
    {
        Mail::fake();
        $waha = Mockery::mock(WahaClient::class);
        $waha->shouldNotReceive('sendMessage');
        $this->app->instance(WahaClient::class, $waha);

        [, $site, $incident] = $this->siteWithIncident();
        // No executed fix — the outage cleared without automation touching it.

        NotifyIncidentAutoResolvedJob::dispatchSync($site->id, $incident->id);

        Mail::assertNothingSent();
        $this->assertSame(0, NotificationLog::count());
    }

    public function test_a_fix_executed_before_the_incident_does_not_count(): void
    {
        Mail::fake();

        [, $site, $incident] = $this->siteWithIncident();
        // Executed long before this outage started — unrelated to it.
        PendingAction::create([
            'type' => 'site_fix',
            'status' => ActionStatus::Executed,
            'customer_id' => $site->customer_id,
            'summary' => 'תיקון ישן',
            'payload' => ['site_id' => $site->id, 'fix' => 'clear_cache'],
            'proposed_by' => 'automation',
            'decided_at' => now()->subDays(3),
            'executed_at' => now()->subDays(3),
        ]);

        NotifyIncidentAutoResolvedJob::dispatchSync($site->id, $incident->id);

        Mail::assertNothingSent();
    }

    public function test_a_team_members_manual_action_is_not_claimed_as_auto_heal(): void
    {
        Mail::fake();

        [, $site, $incident] = $this->siteWithIncident();
        // A team member ran a manual "פעולת AI" during the outage — maintenance,
        // not the automation loop; the customer must not be told "we auto-fixed".
        PendingAction::create([
            'type' => 'site_action',
            'status' => ActionStatus::Executed,
            'customer_id' => $site->customer_id,
            'summary' => 'פעולה ידנית של הצוות',
            'payload' => ['site_id' => $site->id, 'tool' => 'wp_cache_flush'],
            'proposed_by' => 'team',
            'decided_at' => now()->subMinutes(10),
            'executed_at' => now()->subMinutes(10),
        ]);

        NotifyIncidentAutoResolvedJob::dispatchSync($site->id, $incident->id);

        Mail::assertNothingSent();
    }

    public function test_a_fix_executed_after_recovery_does_not_count(): void
    {
        Mail::fake();

        [, $site, $incident] = $this->siteWithIncident();
        $incident->update(['resolved_at' => now()->subMinutes(5)]);
        // Executed AFTER the site already recovered (delayed queue) — it cannot
        // have fixed this incident.
        PendingAction::create([
            'type' => 'site_fix',
            'status' => ActionStatus::Executed,
            'customer_id' => $site->customer_id,
            'summary' => 'תיקון מאוחר',
            'payload' => ['site_id' => $site->id, 'fix' => 'clear_cache'],
            'proposed_by' => 'automation',
            'decided_at' => now()->subMinutes(2),
            'executed_at' => now()->subMinutes(2),
        ]);

        NotifyIncidentAutoResolvedJob::dispatchSync($site->id, $incident->id);

        Mail::assertNothingSent();
    }

    public function test_the_config_switch_silences_the_notification(): void
    {
        config(['billing.monitoring.notify_customer_after_auto_fix' => false]);
        Mail::fake();

        [, $site, $incident] = $this->siteWithIncident();
        $this->executedFix($site);

        NotifyIncidentAutoResolvedJob::dispatchSync($site->id, $incident->id);

        Mail::assertNothingSent();
    }

    public function test_recovery_dispatches_the_customer_notification_job(): void
    {
        $site = Site::factory()->create([
            'customer_id' => Customer::factory(),
            'domain' => 'up-again.example.com',
            'monitor_url' => 'https://up-again.example.com',
        ]);
        $incident = $site->incidents()->create(['started_at' => now()->subHour(), 'status' => IncidentStatus::Open]);
        Http::fake(['https://up-again.example.com' => Http::response('', 200)]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert');
        $this->app->instance(TeamNotifier::class, $team);

        Queue::fake([NotifyIncidentAutoResolvedJob::class]);

        MonitorSiteJob::dispatchSync($site->id);

        Queue::assertPushed(NotifyIncidentAutoResolvedJob::class,
            fn (NotifyIncidentAutoResolvedJob $job): bool => $job->siteId === $site->id && $job->incidentId === $incident->id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
