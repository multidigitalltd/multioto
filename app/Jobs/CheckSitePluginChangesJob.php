<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Agent\McpClient;
use App\Services\Agent\SitePluginInventory;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Watch a connected site for newly-installed plugins/themes AND newly-added
 * administrator users. Reads the current inventory via the companion plugin's
 * wp_plugin_list / wp_theme_list / wp_admin_list tools, diffs it against the
 * last-seen snapshot, and alerts the team on any addition — a new admin nobody
 * on the team created is a classic compromise indicator.
 *
 * First sight of a site (or of a kind) is baselined silently — we only alert on
 * something that appears AFTER we already had a snapshot. Identities are
 * version-independent, so a plugin update is never mistaken for a new install.
 */
class CheckSitePluginChangesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(public int $siteId) {}

    public function handle(McpClient $mcp, TeamNotifier $team): void
    {
        $site = Site::with('customer')->find($this->siteId);

        if (! $site || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return;
        }

        $tools = collect((array) data_get($site->mcp_capabilities, 'tools', []))->pluck('name');

        $current = [];
        foreach (['plugins' => 'wp_plugin_list', 'themes' => 'wp_theme_list', 'admins' => 'wp_admin_list'] as $kind => $tool) {
            if (! $tools->contains($tool)) {
                continue;
            }

            try {
                $text = $mcp->textContent($mcp->callTool($site, $tool));
            } catch (\Throwable $e) {
                Log::warning('CheckSitePluginChangesJob: tool failed', ['site' => $this->siteId, 'tool' => $tool, 'error' => $e->getMessage()]);

                continue;
            }

            // Admin logins get their own verbatim parser — the plugin/theme
            // normalizer strips words an attacker could hide behind ("active").
            $ids = $kind === 'admins'
                ? SitePluginInventory::adminIdentities($text)
                : SitePluginInventory::identities($text);
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
            ->map(fn (array $item): string => match ($item[0]) {
                'themes' => '🎨 תבנית: '.$item[1],
                'admins' => '👤 מנהל חדש: '.$item[1],
                default => '🧩 תוסף: '.$item[1],
            })
            ->implode("\n");

        // A new ADMIN is a stronger signal than a new plugin — lead with it.
        $hasAdmin = collect($added)->contains(fn (array $item): bool => $item[0] === 'admins');

        $team->alert(
            $hasAdmin ? "🚨 משתמש מנהל חדש באתר {$site->domain}" : "🧩 התקנה חדשה באתר {$site->domain}",
            ($hasAdmin
                ? "זוהה משתמש מנהל (administrator) חדש באתר {$site->domain}"
                : "זוהתה התקנת תוסף/תבנית חדש/ה באתר {$site->domain}").
                ($site->customer ? " ({$site->customer->name})" : '').":\n{$lines}\n\n".
                ($hasAdmin
                    ? 'אם אף אחד מהצוות לא יצר את המשתמש הזה — ייתכן שהאתר נפרץ. בדקו מיד והסירו משתמש לא מוכר.'
                    : 'אם ההתקנה אינה מוכרת — כדאי לבדוק.'),
            rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}",
        );
    }
}
