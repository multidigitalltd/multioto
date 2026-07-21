<?php

namespace App\Jobs;

use App\Jobs\Concerns\PausesForShabbat;
use App\Models\Site;
use App\Services\Agent\McpClient;
use App\Services\Agent\SitePluginInventory;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Watch a connected site for newly-installed plugins/themes. Reads the current
 * inventory via the companion plugin's wp_plugin_list / wp_theme_list tools,
 * diffs it against the last-seen snapshot, and alerts the team on any addition.
 *
 * First sight of a site (or of a kind) is baselined silently — we only alert on
 * something that appears AFTER we already had a snapshot. Identities are
 * version-independent, so a plugin update is never mistaken for a new install.
 */
class CheckSitePluginChangesJob implements ShouldQueue
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

    public function handle(McpClient $mcp, TeamNotifier $team): void
    {
        if ($this->rescheduledForShabbat()) {
            return;
        }

        $site = Site::with('customer')->find($this->siteId);

        if (! $site || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return;
        }

        $tools = collect((array) data_get($site->mcp_capabilities, 'tools', []))->pluck('name');

        $current = [];
        foreach (['plugins' => 'wp_plugin_list', 'themes' => 'wp_theme_list'] as $kind => $tool) {
            if (! $tools->contains($tool)) {
                continue;
            }

            try {
                $text = $mcp->textContent($mcp->callTool($site, $tool));
            } catch (\Throwable $e) {
                Log::warning('CheckSitePluginChangesJob: tool failed', ['site' => $this->siteId, 'tool' => $tool, 'error' => $e->getMessage()]);

                continue;
            }

            $ids = SitePluginInventory::identities($text);
            if ($ids !== []) {
                $current[$kind] = $ids;
            }
        }

        if ($current === []) {
            return; // Nothing readable this run — leave the snapshot untouched.
        }

        $previous = (array) $site->plugin_snapshot;
        $snapshot = $previous;
        $added = [];

        foreach ($current as $kind => $ids) {
            if (! array_key_exists($kind, $previous)) {
                // First time we see this kind — baseline it, don't alert.
                $snapshot[$kind] = $ids;

                continue;
            }

            foreach (array_values(array_diff($ids, (array) $previous[$kind])) as $id) {
                $added[] = [$kind, $id];
            }

            $snapshot[$kind] = $ids;
        }

        if ($added !== []) {
            $this->alert($team, $site, $added);
        }

        $site->update(['plugin_snapshot' => $snapshot]);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $added
     */
    private function alert(TeamNotifier $team, Site $site, array $added): void
    {
        $lines = collect($added)
            ->map(fn (array $item): string => ($item[0] === 'themes' ? '🎨 תבנית' : '🧩 תוסף').': '.$item[1])
            ->implode("\n");

        $team->alert(
            "🧩 התקנה חדשה באתר {$site->domain}",
            "זוהתה התקנת תוסף/תבנית חדש/ה באתר {$site->domain}".
                ($site->customer ? " ({$site->customer->name})" : '').":\n{$lines}\n\n".
                'אם ההתקנה אינה מוכרת — כדאי לבדוק.',
            rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}",
        );
    }
}
