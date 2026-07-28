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

        $html = $this->homepage($site);

        if ($html === null) {
            // A timeout or an empty answer is NOT evidence that the site is
            // clean. Rewriting the list from an empty probe would silently
            // erase real SEO/broken-link findings and stamp a fresh scan date.
            SystemLog::record('info', 'monitoring',
                "סריקת ההזדמנויות לאתר {$site->domain} דולגה — דף הבית לא נטען. הממצאים הקודמים נשמרו.",
                ['site_id' => $site->id]);

            return;
        }

        $probe = [
            'seo' => $this->seoSignals($html),
            'broken_links' => $this->brokenLinks($site, $html),
            'php_version' => $this->phpVersion($site, $mcp),
        ];

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
            'has_description' => $this->hasMetaDescription($html),
            'has_og' => preg_match('/<meta[^>]*property\s*=\s*["\']og:/i', $html) === 1,
            'images_without_lazy' => $withoutLazy,
        ];
    }

    /**
     * A non-empty <meta name="description" content="…">. Attribute ORDER is
     * insignificant in HTML, so each meta tag is inspected on its own rather
     * than matched with one order-dependent pattern (which would invent a
     * "missing description" opportunity for a perfectly good page).
     */
    private function hasMetaDescription(string $html): bool
    {
        preg_match_all('/<meta\b[^>]*>/i', $html, $matches);

        foreach ($matches[0] ?? [] as $tag) {
            if (preg_match('/\bname\s*=\s*["\']?description["\']?/i', $tag) !== 1) {
                continue;
            }

            if (preg_match('/\bcontent\s*=\s*(["\'])(.*?)\1/is', $tag, $content) === 1
                && trim(html_entity_decode($content[2], ENT_QUOTES | ENT_HTML5)) !== '') {
                return true;
            }
        }

        return false;
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
            ->map(fn (string $href): ?string => $this->sameSiteUrl(trim($href), $site->domain, $base))
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

    /**
     * The absolute URL to probe, or null when the link does not belong to this
     * site. The host is PARSED and compared exactly — a raw prefix check would
     * accept `https://example.co.il@127.0.0.1/` (whose real host is localhost)
     * and `https://example.co.il.attacker.test/`, turning the queue worker into
     * a probe of internal services or a stranger's site.
     */
    private function sameSiteUrl(string $href, string $domain, string $base): ?string
    {
        if ($href === '') {
            return null;
        }

        // Root-relative links are ours by construction.
        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return $base.$href;
        }

        $parts = parse_url($href);

        if ($parts === false || ! isset($parts['host'])) {
            return null; // relative, mailto:, tel:, javascript:, or malformed
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        // Credentials in a URL only ever serve to disguise the real host here.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            return null;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $domain = strtolower(ltrim($domain, '.'));

        // Exactly the site's host, or a subdomain of it — never a suffix match.
        if ($host !== $domain && ! str_ends_with($host, '.'.$domain)) {
            return null;
        }

        return $href;
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
