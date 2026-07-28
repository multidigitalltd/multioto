<?php

namespace Tests\Feature;

use App\Jobs\ScanSiteOpportunitiesJob;
use App\Models\MonitorCheck;
use App\Models\Site;
use App\Services\Agent\McpClient;
use App\Services\Growth\OpportunityRadar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * The opportunity radar: turning findings the platform already holds into a
 * priced, evidence-backed list of work the team can offer.
 */
class OpportunityRadarTest extends TestCase
{
    use RefreshDatabase;

    private function mcpWithoutTools(): McpClient
    {
        return Mockery::mock(McpClient::class);
    }

    public function test_a_clean_site_produces_no_opportunities(): void
    {
        $site = Site::factory()->create([
            'monitor_enabled' => true,
            'compliance_scan' => ['score' => 100, 'issues' => [], 'missing_docs' => []],
        ]);

        $this->assertSame([], (new OpportunityRadar)->build($site));
    }

    public function test_accessibility_and_document_findings_become_priced_opportunities(): void
    {
        $site = Site::factory()->create([
            'monitor_enabled' => true,
            'compliance_scan' => [
                'score' => 55,
                'issues' => [
                    ['key' => 'img_alt', 'severity' => 'critical', 'title' => '9 תמונות ללא טקסט חלופי', 'detail' => ''],
                    ['key' => 'link_text', 'severity' => 'warning', 'title' => 'קישורים לא ברורים', 'detail' => ''],
                ],
                'missing_docs' => [['key' => 'privacy', 'label' => 'מדיניות פרטיות', 'severity' => 'critical']],
            ],
        ]);

        $opportunities = (new OpportunityRadar)->build($site);
        $keys = collect($opportunities)->pluck('key')->all();

        $this->assertContains('accessibility', $keys);
        $this->assertContains('legal_docs', $keys);

        // The evidence must carry the numbers, so a quote is defensible.
        $a11y = collect($opportunities)->firstWhere('key', 'accessibility');
        $this->assertStringContainsString('55/100', $a11y['evidence']);
        $this->assertStringContainsString('תמונות ללא טקסט חלופי', $a11y['evidence']);
        $this->assertSame(config('growth.opportunities.prices.accessibility'), $a11y['price_agorot']);
    }

    public function test_a_slow_site_is_flagged_from_its_own_monitoring_history(): void
    {
        $site = Site::factory()->create(['monitor_enabled' => true]);

        foreach (range(1, 5) as $i) {
            MonitorCheck::create([
                'site_id' => $site->id,
                'checked_at' => now()->subHours($i),
                'is_up' => true,
                'status_code' => 200,
                'response_ms' => 4200,
            ]);
        }

        $opportunities = (new OpportunityRadar)->build($site->fresh());

        $speed = collect($opportunities)->firstWhere('key', 'speed');
        $this->assertNotNull($speed);
        $this->assertStringContainsString('4.2 שניות', $speed['evidence']);
    }

    public function test_a_fast_site_is_not_offered_a_speed_engagement(): void
    {
        $site = Site::factory()->create(['monitor_enabled' => true]);

        MonitorCheck::create([
            'site_id' => $site->id, 'checked_at' => now()->subHour(),
            'is_up' => true, 'status_code' => 200, 'response_ms' => 400,
        ]);

        $this->assertNull(collect((new OpportunityRadar)->build($site->fresh()))->firstWhere('key', 'speed'));
    }

    public function test_an_outdated_php_is_an_opportunity_and_a_current_one_is_not(): void
    {
        $site = Site::factory()->create(['monitor_enabled' => true]);
        $radar = new OpportunityRadar;

        $old = collect($radar->build($site, ['php_version' => '7.4']))->firstWhere('key', 'php_upgrade');
        $this->assertNotNull($old);
        $this->assertStringContainsString('7.4', $old['evidence']);

        $this->assertNull(collect($radar->build($site, ['php_version' => '8.3']))->firstWhere('key', 'php_upgrade'));
    }

    public function test_monitoring_is_offered_only_when_it_is_switched_off(): void
    {
        $radar = new OpportunityRadar;

        $watched = Site::factory()->create(['monitor_enabled' => true]);
        $unwatched = Site::factory()->create(['monitor_enabled' => false]);

        $this->assertNull(collect($radar->build($watched))->firstWhere('key', 'monitoring'));
        $this->assertNotNull(collect($radar->build($unwatched))->firstWhere('key', 'monitoring'));
    }

    public function test_the_job_finds_broken_links_and_seo_gaps_and_stores_the_total(): void
    {
        $site = Site::factory()->create(['domain' => 'example.co.il', 'monitor_enabled' => true]);

        // Homepage with two internal links and no meta description / OG tags.
        Http::fake([
            'https://example.co.il/' => Http::response('<html><body><a href="/good">טוב</a><a href="/gone">שבור</a></body></html>'),
            'https://example.co.il/good' => Http::response('ok'),
            'https://example.co.il/gone' => Http::response('', 404),
        ]);

        (new ScanSiteOpportunitiesJob($site->id))->handle(new OpportunityRadar, $this->mcpWithoutTools());

        $stored = $site->fresh()->opportunities;
        $keys = collect($stored['items'])->pluck('key')->all();

        $this->assertContains('broken_links', $keys);
        $this->assertContains('seo_basics', $keys);
        $this->assertSame(
            collect($stored['items'])->sum('price_agorot'),
            $stored['total_agorot'],
        );

        $broken = collect($stored['items'])->firstWhere('key', 'broken_links');
        $this->assertStringContainsString('/gone', $broken['evidence']);
    }

    public function test_known_vulnerabilities_become_an_opportunity(): void
    {
        // The scan job writes its results under 'items' — reading any other key
        // would silently hide every security engagement.
        $site = Site::factory()->create([
            'monitor_enabled' => true,
            'vulnerability_scan' => ['items' => [
                ['name' => 'Contact Form 7', 'severity' => 'high'],
                ['name' => 'Elementor', 'severity' => 'medium'],
            ]],
        ]);

        $vuln = collect((new OpportunityRadar)->build($site))->firstWhere('key', 'vulnerabilities');

        $this->assertNotNull($vuln);
        $this->assertStringContainsString('Contact Form 7', $vuln['evidence']);
    }

    public function test_a_meta_description_is_recognised_whatever_the_attribute_order(): void
    {
        $site = Site::factory()->create(['domain' => 'example.co.il', 'monitor_enabled' => true]);

        // content BEFORE name — valid HTML, and a common CMS output.
        Http::fake(['https://example.co.il/' => Http::response(
            '<html><head><title>כותרת</title><meta content="תיאור העמוד" name="description">'
            .'<meta property="og:title" content="x"></head><body></body></html>'
        )]);

        (new ScanSiteOpportunitiesJob($site->id))->handle(new OpportunityRadar, $this->mcpWithoutTools());

        $this->assertNull(collect($site->fresh()->opportunities['items'])->firstWhere('key', 'seo_basics'));
    }

    public function test_a_failed_homepage_probe_keeps_the_previous_findings(): void
    {
        $site = Site::factory()->create([
            'domain' => 'example.co.il',
            'monitor_enabled' => true,
            'opportunities' => ['scanned_at' => '2026-07-01T00:00:00+00:00', 'items' => [
                ['key' => 'broken_links', 'title' => 'תיקון קישורים שבורים', 'evidence' => '3 קישורים', 'price_agorot' => 40000, 'severity' => 'medium'],
            ], 'total_agorot' => 40000],
        ]);

        Http::fake(['*' => Http::response('', 503)]);

        (new ScanSiteOpportunitiesJob($site->id))->handle(new OpportunityRadar, $this->mcpWithoutTools());

        // An outage is not evidence that the site became clean.
        $stored = $site->fresh()->opportunities;
        $this->assertSame('2026-07-01T00:00:00+00:00', $stored['scanned_at']);
        $this->assertSame(40000, $stored['total_agorot']);
    }

    public function test_a_link_that_disguises_another_host_is_never_probed(): void
    {
        $site = Site::factory()->create(['domain' => 'example.co.il', 'monitor_enabled' => true]);

        // Credentials-in-URL (real host: 127.0.0.1) and a suffix look-alike.
        Http::fake([
            'https://example.co.il/' => Http::response(
                '<html><body><a href="https://example.co.il@127.0.0.1/admin">a</a>'
                .'<a href="https://example.co.il.attacker.test/x">b</a></body></html>'
            ),
            '*' => Http::response('', 404),
        ]);

        (new ScanSiteOpportunitiesJob($site->id))->handle(new OpportunityRadar, $this->mcpWithoutTools());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1')
            || str_contains($request->url(), 'attacker.test'));
    }

    public function test_external_links_are_never_probed(): void
    {
        $site = Site::factory()->create(['domain' => 'example.co.il', 'monitor_enabled' => true]);

        Http::fake([
            'https://example.co.il/' => Http::response('<html><body><a href="https://other.test/x">חוץ</a></body></html>'),
            '*' => Http::response('', 404),
        ]);

        (new ScanSiteOpportunitiesJob($site->id))->handle(new OpportunityRadar, $this->mcpWithoutTools());

        // A broken link on someone else's site is not work we can sell.
        $this->assertNull(collect($site->fresh()->opportunities['items'])->firstWhere('key', 'broken_links'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.test'));
    }
}
