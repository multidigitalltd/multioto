<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\SiteStatus;
use App\Jobs\MonitorSiteJob;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Site;
use App\Services\Automation\ApprovalGate;
use App\Services\Hosting\HostingClient;
use App\Services\Hosting\SiteDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SiteOperatorTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        $customer = Customer::factory()->create();

        return Site::create([
            'customer_id' => $customer->id,
            'domain' => 'client-site.co.il',
            'monitor_url' => 'https://client-site.co.il',
            'monitor_enabled' => true,
            'status' => SiteStatus::Active,
            'hosting_ref' => 'fly-123',
        ]);
    }

    public function test_diagnostics_reports_healthy_and_suggests_no_fix_when_the_site_is_up(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>', 200)]);
        $site = $this->site();

        $result = app(SiteDiagnostics::class)->run($site);

        $this->assertTrue($result['healthy']);
        $this->assertNull($result['suggested_fix']);
        $this->assertStringContainsString('האתר עונה', $result['summary']);
    }

    public function test_diagnostics_flags_a_403_as_not_healthy(): void
    {
        // A site answering 403 is NOT healthy — the server responds but refuses
        // to serve the site. No safe auto-fix; it needs a human.
        Http::fake(['*' => Http::response('forbidden', 403)]);
        $site = $this->site();

        $result = app(SiteDiagnostics::class)->run($site);

        $this->assertFalse($result['healthy']);
        $this->assertNull($result['suggested_fix']);
        $this->assertStringContainsString('403', $result['summary']);
        $this->assertStringNotContainsString('עונה תקין', $result['summary']);
    }

    public function test_diagnostics_suggests_restart_on_a_502(): void
    {
        Http::fake(['*' => Http::response('bad gateway', 502)]);
        $site = $this->site();

        $result = app(SiteDiagnostics::class)->run($site);

        $this->assertFalse($result['healthy']);
        $this->assertSame('restart', $result['suggested_fix']);
    }

    public function test_an_approved_site_fix_calls_the_hosting_driver(): void
    {
        $site = $this->site();

        // The reversible fix must reach the hosting client — and only on approval.
        $hosting = Mockery::mock(HostingClient::class);
        $hosting->shouldReceive('clearCache')->once()->with(Mockery::on(fn ($s) => $s->id === $site->id));
        $this->app->instance(HostingClient::class, $hosting);

        $action = PendingAction::create([
            'type' => 'site_fix',
            'status' => ActionStatus::Pending,
            'customer_id' => $site->customer_id,
            'summary' => 'ניקוי מטמון',
            'payload' => ['site_id' => $site->id, 'fix' => 'clear_cache'],
        ]);

        $result = app(ApprovalGate::class)->approve($action);

        $this->assertStringContainsString('בוצעה', $result);
        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
    }

    public function test_a_downed_site_opens_an_incident_and_proposes_a_fix_to_the_owner(): void
    {
        config([
            'billing.monitoring.timeout_seconds' => 5,
            'billing.monitoring.failures_to_incident' => 1, // fail once → incident
            'billing.hosting.driver' => 'log',
            'billing.waha.owner_number' => null, // proposal stays in the panel
        ]);
        // HEAD probe (monitor) and GET probe (diagnostics) both see a 502.
        Http::fake(['*' => Http::response('down', 502)]);
        $site = $this->site();

        MonitorSiteJob::dispatchSync($site->id);

        // Incident opened…
        $this->assertSame(1, $site->incidents()->count());
        // …and a reversible fix was proposed to the approval gate (a restart for a 502).
        $action = PendingAction::where('type', 'site_fix')->sole();
        $this->assertSame(ActionStatus::Pending, $action->status);
        $this->assertSame('restart', $action->payload['fix']);
        $this->assertSame('automation', $action->proposed_by);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
