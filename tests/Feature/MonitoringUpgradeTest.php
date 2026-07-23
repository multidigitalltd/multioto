<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Filament\Resources\SiteResource\Pages\ViewSite;
use App\Filament\Widgets\SitesInTrouble;
use App\Jobs\CheckSslExpiryJob;
use App\Jobs\InvestigateSiteJob;
use App\Jobs\MonitorSiteJob;
use App\Jobs\SendDomainRenewalReminderJob;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use App\Services\Hosting\SiteDiagnostics;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class MonitoringUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_expected_keyword_marks_the_site_down_on_http_200(): void
    {
        config(['billing.monitoring.failures_to_incident' => 1]);

        $site = Site::factory()->create([
            'domain' => 'shop.example.com',
            'monitor_url' => 'https://shop.example.com',
            'expected_keyword' => 'הוסף לסל',
        ]);

        // HTTP 200 but the expected storefront text is gone (blank/defaced page).
        Http::fake(['https://shop.example.com' => Http::response('<html><body></body></html>', 200)]);

        MonitorSiteJob::dispatchSync($site->id);

        $check = $site->monitorChecks()->latest('checked_at')->first();
        $this->assertNotNull($check);
        $this->assertFalse($check->is_up);
        $this->assertSame('התוכן הצפוי חסר בעמוד', $check->error);
        $this->assertTrue($site->openIncident()->exists());
    }

    public function test_present_expected_keyword_keeps_the_site_up(): void
    {
        $site = Site::factory()->create([
            'domain' => 'shop.example.com',
            'monitor_url' => 'https://shop.example.com',
            'expected_keyword' => 'הוסף לסל',
        ]);

        Http::fake(['https://shop.example.com' => Http::response('<button>הוסף לסל</button>', 200)]);

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertTrue($site->monitorChecks()->latest('checked_at')->first()->is_up);
        $this->assertFalse($site->openIncident()->exists());
    }

    public function test_content_check_follows_redirects_to_the_final_page(): void
    {
        $site = Site::factory()->create([
            'domain' => 'shop.example.com',
            'monitor_url' => 'https://shop.example.com',
            'expected_keyword' => 'הוסף לסל',
        ]);

        // Bare domain 301-redirects to www; only the final page carries the
        // storefront text. Following redirects must land there — not open a
        // false incident on the 3xx body.
        Http::fake([
            'https://shop.example.com' => Http::response('', 301, ['Location' => 'https://www.shop.example.com']),
            'https://www.shop.example.com' => Http::response('<button>הוסף לסל</button>', 200),
        ]);

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertTrue($site->monitorChecks()->latest('checked_at')->first()->is_up);
        $this->assertFalse($site->openIncident()->exists());
    }

    public function test_ssl_expiry_alerts_the_team_once_then_re_arms_after_renewal(): void
    {
        config(['billing.monitoring.ssl_warn_days' => 14]);

        $site = Site::factory()->create(['domain' => 'expiring.example.com']);

        $diagnostics = Mockery::mock(SiteDiagnostics::class);
        // First run: 5 days left → inside the window. Second (same cert): still 5.
        // Third: renewed to 300.
        $diagnostics->shouldReceive('sslDaysLeft')->andReturn(5, 5, 300);
        $this->app->instance(SiteDiagnostics::class, $diagnostics);

        $team = Mockery::mock(TeamNotifier::class);
        // Alert fires exactly once across the two in-window runs…
        $team->shouldReceive('alert')->once();
        $this->app->instance(TeamNotifier::class, $team);

        CheckSslExpiryJob::dispatchSync($site->id);
        $this->assertSame(5, $site->refresh()->ssl_days_left);
        $this->assertNotNull($site->ssl_alerted_at);

        // Second run inside the window: no second alert (already armed).
        CheckSslExpiryJob::dispatchSync($site->id);

        // Renewed: flag clears so a future expiry can alert again.
        CheckSslExpiryJob::dispatchSync($site->id);
        $this->assertSame(300, $site->refresh()->ssl_days_left);
        $this->assertNull($site->ssl_alerted_at);
    }

    public function test_team_is_alerted_when_a_site_goes_down(): void
    {
        config(['billing.monitoring.failures_to_incident' => 1]);

        $site = Site::factory()->create(['domain' => 'down.example.com', 'monitor_url' => 'https://down.example.com']);
        Http::fake(['https://down.example.com' => Http::response('', 500)]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()
            ->withArgs(fn (string $title): bool => str_contains($title, 'לא זמין'));
        $this->app->instance(TeamNotifier::class, $team);

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertTrue($site->openIncident()->exists());
    }

    public function test_team_is_alerted_when_a_site_recovers(): void
    {
        $site = Site::factory()->create(['domain' => 'back.example.com', 'monitor_url' => 'https://back.example.com']);
        $site->incidents()->create(['started_at' => now()->subHour(), 'status' => IncidentStatus::Open]);
        Http::fake(['https://back.example.com' => Http::response('', 200)]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()
            ->withArgs(fn (string $title): bool => str_contains($title, 'חזר'));
        $this->app->instance(TeamNotifier::class, $team);

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertFalse($site->openIncident()->exists());
    }

    public function test_team_is_alerted_once_when_a_site_is_up_but_slow(): void
    {
        config(['billing.monitoring.slow_response_ms' => 4000]);

        $site = Site::factory()->create(['domain' => 'slow.example.com', 'monitor_url' => 'https://slow.example.com']);
        // Seed recent successful-but-slow probes so the average clears the sof.
        foreach (range(1, 4) as $i) {
            $site->monitorChecks()->create(['checked_at' => now()->subMinutes($i), 'is_up' => true, 'status_code' => 200, 'response_ms' => 8000]);
        }
        Http::fake(['https://slow.example.com' => Http::response('', 200)]);

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once()
            ->withArgs(fn (string $title): bool => str_contains($title, 'איטי'));
        $this->app->instance(TeamNotifier::class, $team);

        MonitorSiteJob::dispatchSync($site->id);
        $this->assertNotNull($site->refresh()->slow_alerted_at);

        // A second slow probe must not fire a second alert (already armed).
        MonitorSiteJob::dispatchSync($site->id);
    }

    public function test_an_incident_on_a_connected_site_dispatches_the_ai_operator(): void
    {
        config([
            'billing.monitoring.failures_to_incident' => 1,
            'agent.auto_investigate' => true,
        ]);
        Queue::fake([InvestigateSiteJob::class]);

        $site = Site::factory()->create([
            'domain' => 'connected.example.com',
            'monitor_url' => 'https://connected.example.com',
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://connected.example.com/wp-json/md-agent/v1/mcp',
            'mcp_secret' => 'site-secret',
        ]);
        Http::fake(['https://connected.example.com' => Http::response('', 500)]);
        $this->silenceTeamAndDiagnostics();

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertTrue($site->openIncident()->exists());
        Queue::assertPushed(InvestigateSiteJob::class, fn (InvestigateSiteJob $job): bool => $job->siteId === $site->id
            && str_contains($job->goal, 'incident #'));
    }

    public function test_an_incident_on_an_unconnected_site_does_not_dispatch_the_ai_operator(): void
    {
        config(['billing.monitoring.failures_to_incident' => 1]);
        Queue::fake([InvestigateSiteJob::class]);

        $site = Site::factory()->create([
            'domain' => 'plain.example.com',
            'monitor_url' => 'https://plain.example.com',
            'mcp_enabled' => false,
        ]);
        Http::fake(['https://plain.example.com' => Http::response('', 500)]);
        $this->silenceTeamAndDiagnostics();

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertTrue($site->openIncident()->exists());
        Queue::assertNotPushed(InvestigateSiteJob::class);
    }

    public function test_auto_investigate_can_be_switched_off(): void
    {
        config([
            'billing.monitoring.failures_to_incident' => 1,
            'agent.auto_investigate' => false,
        ]);
        Queue::fake([InvestigateSiteJob::class]);

        $site = Site::factory()->create([
            'domain' => 'muted.example.com',
            'monitor_url' => 'https://muted.example.com',
            'mcp_enabled' => true,
            'mcp_endpoint' => 'https://muted.example.com/wp-json/md-agent/v1/mcp',
            'mcp_secret' => 'site-secret',
        ]);
        Http::fake(['https://muted.example.com' => Http::response('', 500)]);
        $this->silenceTeamAndDiagnostics();

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertTrue($site->openIncident()->exists());
        Queue::assertNotPushed(InvestigateSiteJob::class);
    }

    /** Keep the down-path side effects (team alert + legacy diagnosis) inert. */
    private function silenceTeamAndDiagnostics(): void
    {
        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->zeroOrMoreTimes();
        $this->app->instance(TeamNotifier::class, $team);

        $diagnostics = Mockery::mock(SiteDiagnostics::class);
        $diagnostics->shouldReceive('run')->andReturn(['summary' => '', 'suggested_fix' => null]);
        $this->app->instance(SiteDiagnostics::class, $diagnostics);
    }

    public function test_sites_in_trouble_widget_visibility(): void
    {
        $this->actingAs(User::factory()->create());

        $healthy = Site::factory()->create(['ssl_days_left' => 200]);
        $this->assertFalse(SitesInTrouble::canView());

        // An SSL certificate inside the warning window makes it appear.
        $expiring = Site::factory()->create(['ssl_days_left' => 3]);
        $this->assertTrue(SitesInTrouble::canView());

        Livewire::test(SitesInTrouble::class)
            ->assertCanSeeTableRecords([$expiring])
            ->assertCanNotSeeTableRecords([$healthy]);
    }

    public function test_view_site_page_reports_uptime_and_average_response(): void
    {
        $this->actingAs(User::factory()->create());

        $site = Site::factory()->create(['ssl_days_left' => 40]);
        $site->monitorChecks()->create(['checked_at' => now(), 'is_up' => true, 'status_code' => 200, 'response_ms' => 100]);
        $site->monitorChecks()->create(['checked_at' => now(), 'is_up' => true, 'status_code' => 200, 'response_ms' => 300]);
        $site->monitorChecks()->create(['checked_at' => now(), 'is_up' => false, 'status_code' => 500, 'response_ms' => 0]);

        $page = Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()]);

        $stats = $page->instance()->getStatsProperty();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['up']);
        $this->assertEqualsWithDelta(66.67, $stats['uptime'], 0.01);
        $this->assertSame(200, $stats['avg_ms']); // avg of up-checks only: (100+300)/2
    }

    public function test_the_domain_renewal_reminder_button_dispatches_the_job(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $site = Site::factory()->create([
            'customer_id' => $customer->id,
            'domain_expiry_at' => now()->addDays(20)->toDateString(),
        ]);

        Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
            ->callAction('domainRenewalReminder');

        Queue::assertPushed(SendDomainRenewalReminderJob::class,
            fn ($job): bool => $job->siteId === $site->id);
    }

    public function test_the_domain_renewal_button_is_hidden_without_a_known_expiry(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::factory()->create(['customer_id' => Customer::factory(), 'domain_expiry_at' => null]);

        Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
            ->assertActionHidden('domainRenewalReminder');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
