<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Jobs\RestoreSiteJob;
use App\Jobs\SuspendSiteJob;
use App\Models\Site;
use App\Services\Hosting\HostingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlyWpHostingClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.hosting.driver' => 'flywp',
            'billing.hosting.flywp.base_url' => 'https://app.flywp.test/api/v1',
            'billing.hosting.flywp.api_token' => 'fly-token',
            'billing.hosting.flywp.server_id' => '99',
            'billing.hosting.flywp.maintenance_path' => 'servers/{server}/sites/{site}/maintenance',
        ]);
    }

    public function test_suspend_enables_maintenance_mode_on_flywp(): void
    {
        Http::fake(['https://app.flywp.test/*' => Http::response(['ok' => true])]);

        $site = Site::factory()->create(['hosting_ref' => '4242', 'status' => SiteStatus::Active]);

        (new SuspendSiteJob($site->id))->handle(app(HostingClient::class));

        $this->assertSame(SiteStatus::Suspended, $site->fresh()->status);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://app.flywp.test/api/v1/servers/99/sites/4242/maintenance'
                && $request['enabled'] === true
                && $request->hasHeader('Authorization', 'Bearer fly-token');
        });
    }

    public function test_restore_disables_maintenance_mode_on_flywp(): void
    {
        Http::fake(['https://app.flywp.test/*' => Http::response(['ok' => true])]);

        $site = Site::factory()->create(['hosting_ref' => '4242', 'status' => SiteStatus::Suspended]);

        (new RestoreSiteJob($site->id))->handle(app(HostingClient::class));

        $this->assertSame(SiteStatus::Active, $site->fresh()->status);
        Http::assertSent(fn ($request) => $request['enabled'] === false);
    }

    public function test_missing_hosting_ref_raises_before_any_call(): void
    {
        Http::preventStrayRequests();

        $site = Site::factory()->create(['hosting_ref' => null]);

        $this->expectException(\RuntimeException::class);

        app(HostingClient::class)->suspendSite($site);
    }
}
