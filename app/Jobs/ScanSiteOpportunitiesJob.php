<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SystemLog;
use App\Services\Agent\McpClient;
use App\Services\Growth\OpportunityRadar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Weekly opportunity sweep for one site: probes the few things the radar cannot
 * learn from stored scans (broken links on the homepage, SEO basics, the PHP
 * version), then asks OpportunityRadar to turn everything the platform knows
 * into a priced list of work worth offering.
 *
 * Read-only and bounded — a fixed number of link probes per site.
 */
class ScanSiteOpportunitiesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public function __construct(public int $siteId) {}

    public function failed(?\Throwable $e): void
    {
        SystemLog::record('error', 'monitoring',
            "סריקת הזדמנויות לאתר #{$this->siteId} נכשלה בשגיאה לא צפויה: ".($e?->getMessage() ?: 'שגיאה לא ידועה'),
            ['site_id' => $this->siteId]);
    }

    public function handle(OpportunityRadar $radar, McpClient $mcp): void
    {
        if (! config('growth.opportunities.enabled', true)) {
            return;
        }

        $site = Site::find($this->siteId);

        if (! $site || blank($site->domain)) {
            return;
        }

        $probe = ['broken_links' => [], 'seo' => [], 'php_version' => null];
        $html = $this->homepage($site);

        if ($html !== null) {
            $probe['seo'] = $this->seoSignals($html);
            $probe['broken_links'] = $this->brokenLinks($site, $html);
        }

        $probe['php_version'] = $this->phpVersion($site, $mcp);

        $opportunities = $radar->build($site, $probe);

        $site->update(['opportunities' => [
            'scanned_at' => now()->toIso8601String(),
            'items' => $opportunities,
            'total_agorot' => $radar->totalAgorot($opportunities),
        ]]);
    }

    private function homepage(Site $site): ?string
    {
        try {
            $response = Http::timeout((int) config('billing.monitoring.timeout_seconds', 10))
                ->withHeaders(['User-Agent' => 'MultiotoOpportunityScan/1.0'])
                ->get('https://'.$site->domain.'/');
        } catch (\Throwable) {
            return null;
        }

        $body = $response->successful() ? $response->body() : '';

        return trim($body) === '' ? null : $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function seoSignals(string $html): array
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $images);

        $withoutLazy = collect($images[0] ?? [])
            ->reject(fn (string $tag): bool => preg_match('/\bloading\s*=\s*["\']?lazy/i', $tag) === 1)
            ->count();

        return [
            'has_title' => preg_match('/<title[^>]*>\s*\S/i', $html) === 1,
            'has_description' => preg_match('/<meta[^>]*name\s*=\s*["\']description["\'][^>]*content\s*=\s*["\']\s*\S/i', $html) === 1,
            'has_og' => preg_match('/<meta[^>]*property\s*=\s*["\']og:/i', $html) === 1,
            'images_without_lazy' => $withoutLazy,
        ];
    }

    /**
     * Probe a bounded sample of the homepage's internal links and report those
     * that answer 404/410. Only same-domain links — a broken link on someone
     * else's site is not work we can sell.
     *
     * @return list<string>
     */
    private function brokenLinks(Site $site, string $html): array
    {
        preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\'#]+)["\']/i', $html, $matches);

        $sample = (int) config('growth.opportunities.link_sample', 15);
        $base = 'https://'.$site->domain;

        $links = collect($matches[1] ?? [])
            ->map(function (string $href) use ($base): ?string {
                $href = trim($href);

                if ($href === '' || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                    return null;
                }

                if (str_starts_with($href, '/')) {
                    return $base.$href;
                }

                return str_starts_with($href, $base) ? $href : null; // same site only
            })
            ->filter()
            ->unique()
            ->take($sample);

        $broken = [];

        foreach ($links as $url) {
            try {
                $response = Http::timeout(8)->withHeaders(['User-Agent' => 'MultiotoLinkCheck/1.0'])->get($url);
            } catch (\Throwable) {
                continue; // A timeout is not proof of a broken link.
            }

            if (in_array($response->status(), [404, 410], true)) {
                $broken[] = $url;
            }
        }

        return $broken;
    }

    /** The site's PHP version, when the agent plugin is connected. */
    private function phpVersion(Site $site, McpClient $mcp): ?string
    {
        if (! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return null;
        }

        $tools = collect((array) data_get($site->mcp_capabilities, 'tools', []))->pluck('name');

        if (! $tools->contains('wp_health')) {
            return null;
        }

        try {
            $health = json_decode($mcp->textContent($mcp->callTool($site, 'wp_health')), true);
        } catch (\Throwable) {
            return null;
        }

        $version = is_array($health) ? (string) ($health['php_version'] ?? '') : '';

        return $version !== '' ? $version : null;
    }
}
