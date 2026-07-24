<?php

namespace App\Jobs;

use App\Jobs\Concerns\PausesForShabbat;
use App\Models\Site;
use App\Models\SystemLog;
use App\Services\Agent\McpClient;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\SiteSecurityInventory;
use App\Services\Security\VulnerabilityFeedClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Security scan for one connected site: read the installed plugins/themes/core
 * over MCP, match each version against a public vulnerability feed (Wordfence
 * Intelligence by default), store the result on the site, and alert the team
 * about newly-appeared vulnerabilities (once — not on every run).
 *
 * Read-only on the customer site; it never changes anything. Honours the
 * Shabbat quiet period like the other outward jobs.
 */
class ScanSiteVulnerabilitiesJob implements ShouldQueue
{
    use PausesForShabbat;
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(public int $siteId) {}

    /** @return array<int, mixed> */
    protected function shabbatDispatchArgs(): array
    {
        return [$this->siteId];
    }

    public function handle(McpClient $mcp, VulnerabilityFeedClient $feed, TeamNotifier $team): void
    {
        if ($this->rescheduledForShabbat() || ! config('security.vulnerabilities.enabled', true)) {
            return;
        }

        $site = Site::with('customer')->find($this->siteId);

        if (! $site || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return;
        }

        ['components' => $components, 'types' => $readTypes] = $this->inventory($site, $mcp);

        // A run that can't complete keeps the last findings untouched, but must
        // leave a visible trace — an empty page tells the operator nothing.
        if ($readTypes === []) {
            $this->recordFailedRun($site, 'unreadable',
                "סריקת אבטחה לאתר {$site->domain} רצה אך לא הצליחה לקרוא את רשימת הרכיבים מהתוסף — ודאו שהתוסף באתר מעודכן ומחובר (בדוק חיבור AI).");

            return;
        }

        // The feed being unreachable is not a "clean" site — record it as unknown
        // so a fetch outage never reads as "no vulnerabilities".
        if (! $feed->available()) {
            Log::warning('ScanSiteVulnerabilitiesJob: vulnerability feed unavailable', ['site' => $this->siteId]);
            $this->recordFailedRun($site, 'feed_unavailable',
                "סריקת אבטחה לאתר {$site->domain}: פיד הפגיעויות (Wordfence) לא היה זמין — ננסה שוב בריצה הבאה.");

            return;
        }

        $newItems = [];
        foreach ($components as $component) {
            foreach ($feed->matches($component['type'], $component['slug'], $component['version']) as $vuln) {
                $newItems[] = [
                    'type' => $component['type'],
                    'slug' => $component['slug'],
                    'name' => $component['name'],
                    'version' => $component['version'],
                    'title' => $vuln['title'],
                    'severity' => $vuln['severity'],
                    'cvss' => $vuln['cvss'],
                    'cve' => $vuln['cve'],
                    'patched_in' => $vuln['patched_in'],
                    'link' => $vuln['link'],
                ];
            }
        }

        $previous = (array) data_get($site->vulnerability_scan, 'items', []);
        $previousKeys = collect($previous)->map(fn (array $i): string => self::key($i))->all();

        // Only the component types we actually read this run are refreshed;
        // findings for a type whose reader was missing or failed are kept, so a
        // transient wp_theme_list / wp_health failure never wipes (and later
        // re-alerts as "new") known theme/core vulnerabilities.
        $preserved = array_values(array_filter(
            $previous,
            fn (array $i): bool => ! in_array($i['type'] ?? '', $readTypes, true),
        ));

        $items = array_merge($preserved, $newItems);

        $site->update(['vulnerability_scan' => [
            'scanned_at' => now()->toIso8601String(),
            'items' => $items,
            'last_run_at' => now()->toIso8601String(),
            'last_run_status' => 'ok',
        ]]);

        // Alert only about vulnerabilities we hadn't already reported for this site.
        $fresh = array_values(array_filter($items, fn (array $i): bool => ! in_array(self::key($i), $previousKeys, true)));

        if ($fresh !== []) {
            $this->alert($team, $site, $fresh, count($items));
        }
    }

    /**
     * Read the installed plugins, themes and core version over MCP. Returns the
     * components found AND the set of component types actually read this run
     * (only a successful tool call counts) — so the caller can refresh only what
     * it re-scanned and preserve findings for types it could not read.
     *
     * @return array{components: list<array{type: string, slug: string, name: string, version: string}>, types: list<string>}
     */
    private function inventory(Site $site, McpClient $mcp): array
    {
        $tools = collect((array) data_get($site->mcp_capabilities, 'tools', []))->pluck('name');
        $components = [];
        $readTypes = [];

        // tool => [component type it covers, parser]
        $readers = [
            'wp_plugin_list' => ['plugin', fn (string $t): array => SiteSecurityInventory::plugins($t)],
            'wp_theme_list' => ['theme', fn (string $t): array => SiteSecurityInventory::themes($t)],
            'wp_health' => ['core', fn (string $t): array => array_values(array_filter([SiteSecurityInventory::core($t)]))],
        ];

        foreach ($readers as $tool => [$type, $parse]) {
            if (! $tools->contains($tool)) {
                continue;
            }

            try {
                $text = $mcp->textContent($mcp->callTool($site, $tool));
            } catch (\Throwable $e) {
                Log::warning('ScanSiteVulnerabilitiesJob: tool failed', ['site' => $this->siteId, 'tool' => $tool, 'error' => $e->getMessage()]);

                continue;
            }

            // The read succeeded (even if it returned nothing) — this type is now
            // authoritative for this run.
            $readTypes[] = $type;

            foreach ($parse($text) as $component) {
                $components[] = $component;
            }
        }

        return ['components' => $components, 'types' => $readTypes];
    }

    /** A stable identity for one finding, so the same vuln isn't re-alerted. */
    /**
     * Stamp an incomplete run on the stored scan (keeping all previous findings
     * and scanned_at) and surface the reason in the in-panel event log — so the
     * site page can say WHY there is no fresh result instead of showing nothing.
     */
    private function recordFailedRun(Site $site, string $status, string $message): void
    {
        $site->update(['vulnerability_scan' => array_merge((array) $site->vulnerability_scan, [
            'last_run_at' => now()->toIso8601String(),
            'last_run_status' => $status,
        ])]);

        SystemLog::record('warning', 'monitoring', $message, ['site_id' => $site->id]);
    }

    private static function key(array $item): string
    {
        return implode('|', [$item['type'] ?? '', $item['slug'] ?? '', $item['cve'] ?? $item['title'] ?? '']);
    }

    /**
     * @param  list<array<string, mixed>>  $fresh
     */
    private function alert(TeamNotifier $team, Site $site, array $fresh, int $total): void
    {
        $lines = collect($fresh)->take(10)->map(function (array $i): string {
            $sev = filled($i['severity'] ?? null) ? ' ['.$i['severity'].']' : '';
            $patch = filled($i['patched_in'] ?? null) ? " → תוקן ב-{$i['patched_in']}" : '';

            return "⚠️ {$i['name']} {$i['version']}{$sev}: {$i['title']}{$patch}";
        })->implode("\n");

        $more = count($fresh) > 10 ? "\n… ועוד ".(count($fresh) - 10) : '';

        $freshCount = count($fresh);
        $owner = $site->customer ? " ({$site->customer->name})" : '';

        $team->alert(
            "🛡️ פגיעות אבטחה באתר {$site->domain}",
            "נמצאו רכיבים פגיעים באתר {$site->domain}{$owner} — סה\"כ {$total} ממצאים, {$freshCount} חדשים:\n".
                "{$lines}{$more}\n\nמומלץ לעדכן את הרכיבים לגרסה מתוקנת.",
            rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}",
        );
    }
}
