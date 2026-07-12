<?php

namespace Tests\Feature;

use App\Filament\Resources\SiteResource\Pages\ViewSite;
use App\Filament\Widgets\SitesInTrouble;
use App\Jobs\CheckSslExpiryJob;
use App\Jobs\MonitorSiteJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Hosting\SiteDiagnostics;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
