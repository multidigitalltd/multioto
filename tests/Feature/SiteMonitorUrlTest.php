<?php

namespace Tests\Feature;

use App\Jobs\MonitorSiteJob;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A site's monitor URL must always be well-formed https, even when the domain
 * was pasted with a scheme (including the malformed "https//…"), so the monitor
 * never fires at "https://https//…".
 */
class SiteMonitorUrlTest extends TestCase
{
    use RefreshDatabase;

    public static function schemeCases(): array
    {
        return [
            'malformed https//' => ['https//humantra.co.il', 'https://humantra.co.il'],
            'proper https' => ['https://example.co.il', 'https://example.co.il'],
            'proper http' => ['http://example.co.il', 'https://example.co.il'],
            'bare domain' => ['example.co.il', 'https://example.co.il'],
            'trailing slash' => ['https://example.co.il/', 'https://example.co.il'],
            'subdirectory kept' => ['https://example.co.il/blog', 'https://example.co.il/blog'],
        ];
    }

    #[DataProvider('schemeCases')]
    public function test_monitor_url_is_normalised(string $domain, string $expected): void
    {
        $site = Site::factory()->make(['domain' => $domain, 'monitor_url' => null]);

        // The saving hook cleans the stored domain…
        $site->save();
        $this->assertStringNotContainsString('https//', $site->domain);
        // …and the monitor URL is always well-formed.
        $this->assertSame($expected, $site->monitorUrl());
    }

    public function test_an_explicit_monitor_url_is_used_and_normalised(): void
    {
        $site = Site::factory()->create(['domain' => 'example.co.il', 'monitor_url' => 'https://check.example.co.il']);

        $this->assertSame('https://check.example.co.il', $site->monitorUrl());
    }

    public function test_an_explicit_http_monitor_url_keeps_its_scheme(): void
    {
        // A deliberately http-only health endpoint must not be forced to https.
        $site = Site::factory()->create(['domain' => 'example.co.il', 'monitor_url' => 'http://legacy.example.com/health']);

        $this->assertSame('http://legacy.example.com/health', $site->monitorUrl());
    }

    public function test_the_monitor_probes_the_clean_url_for_a_scheme_carrying_domain(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $site = Site::factory()->create(['domain' => 'https//humantra.co.il', 'monitor_url' => null]);

        MonitorSiteJob::dispatchSync($site->id);

        // The probe hits the clean host, never "https://https//…".
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://humantra.co.il'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'https//humantra'));
    }
}
