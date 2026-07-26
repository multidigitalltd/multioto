<?php

namespace Tests\Feature;

use App\Filament\Resources\ChargeResource;
use App\Jobs\MonitorSiteJob;
use App\Models\Charge;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Uptime probe classification: 2xx/3xx are up; 401/403/429 are up-but-flagged
 * (bot protection blocking OUR probe — never shown as a plain green "תקין");
 * any other 4xx is a real homepage problem and counts as down, like 5xx.
 * Plus: the charges screen is a display-only ledger.
 */
class ProbeStatusClassificationTest extends TestCase
{
    use RefreshDatabase;

    private function probe(int $status): Site
    {
        config(['billing.monitoring.failures_to_incident' => 1]);
        $site = Site::factory()->create([
            'domain' => 'probe.example.com',
            'monitor_url' => 'https://probe.example.com',
            'monitor_enabled' => true,
        ]);
        Http::fake(['https://probe.example.com' => Http::response('x', $status)]);

        MonitorSiteJob::dispatchSync($site->id);

        return $site;
    }

    public function test_a_403_is_up_but_flagged_as_bot_protection_not_plain_ok(): void
    {
        $check = $this->probe(403)->monitorChecks()->latest('checked_at')->first();

        $this->assertTrue($check->is_up); // no false "site down" alarms for WAF-protected sites
        $this->assertStringContainsString('הגנת בוטים', (string) $check->error);
    }

    public function test_a_404_homepage_now_counts_as_down(): void
    {
        $site = $this->probe(404);
        $check = $site->monitorChecks()->latest('checked_at')->first();

        $this->assertFalse($check->is_up);
        $this->assertSame('HTTP 404', $check->error);
        $this->assertTrue($site->openIncident()->exists());
    }

    public function test_redirects_and_success_stay_plainly_up(): void
    {
        foreach ([200, 301] as $status) {
            $check = $this->probe($status)->monitorChecks()->latest('checked_at')->first();
            $this->assertTrue($check->is_up, "status {$status}");
            $this->assertNull($check->error, "status {$status}");
        }
    }

    public function test_the_charges_screen_is_display_only(): void
    {
        $this->actingAs(User::factory()->create());
        $charge = new Charge;

        $this->assertFalse(ChargeResource::canCreate());
        $this->assertFalse(ChargeResource::canEdit($charge));
        $this->assertFalse(ChargeResource::canDelete($charge));
        $this->assertFalse(ChargeResource::canDeleteAny());

        // The list stays reachable; create/edit routes no longer exist.
        $this->get(ChargeResource::getUrl())->assertOk();
        $this->assertArrayNotHasKey('create', ChargeResource::getPages());
        $this->assertArrayNotHasKey('edit', ChargeResource::getPages());
    }
}
