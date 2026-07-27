<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteEvent;
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
        $changes = [];

        foreach ($current as $kind => $ids) {
            if (! array_key_exists($kind, $previous)) {
                // First time we see this kind — baseline it, don't alert.
                $snapshot[$kind] = $ids;

                continue;
            }

            $before = (array) $previous[$kind];

            foreach (array_values(array_diff($ids, $before)) as $id) {
                $changes[] = [$kind, $id, 'added'];
            }

            // A removal matters too: an admin quietly deleted, or a security
            // plugin uninstalled, is as telling as something newly installed.
            foreach (array_values(array_diff($before, $ids)) as $id) {
                $changes[] = [$kind, $id, 'removed'];
            }

            $snapshot[$kind] = $ids;
        }

        if ($changes !== []) {
            $this->recordFindings($site, $changes);
            $this->alert($team, $site, $changes);
        }

        $site->update(['plugin_snapshot' => $snapshot]);
    }

    /** Emoji + Hebrew noun per inventory kind. */
    private const KIND_LABELS = [
        'admins' => ['👤', 'משתמש מנהל'],
        'themes' => ['🎨', 'תבנית'],
        'plugins' => ['🧩', 'תוסף'],
    ];

    /**
     * Persist every change as a durable site finding, so the site page can show
     * the customer exactly what was detected and on which date — the team alert
     * itself is transient.
     *
     * @param  array<int, array{0: string, 1: string, 2: string}>  $changes
     */
    private function recordFindings(Site $site, array $changes): void
    {
        foreach ($changes as [$kind, $id, $direction]) {
            [, $noun] = self::KIND_LABELS[$kind] ?? ['•', $kind];
            $singular = ['admins' => 'admin', 'themes' => 'theme', 'plugins' => 'plugin'][$kind] ?? $kind;
            $isNewAdmin = $kind === 'admins' && $direction === 'added';

            SiteEvent::record(
                $site->id,
                "{$singular}_{$direction}",
                $isNewAdmin ? 'critical' : 'warning',
                $direction === 'added' ? "{$noun} חדש: {$id}" : "{$noun} הוסר: {$id}",
                $isNewAdmin ? 'משתמש מנהל שאיש מהצוות לא יצר הוא סימן מוכר לפריצה — יש לוודא מול הלקוח.' : null,
            );
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $changes
     */
    private function alert(TeamNotifier $team, Site $site, array $changes): void
    {
        $lines = collect($changes)
            ->map(function (array $item): string {
                [$icon, $noun] = self::KIND_LABELS[$item[0]] ?? ['•', $item[0]];

                return "{$icon} {$noun} ".($item[2] === 'added' ? 'חדש' : 'שהוסר').": {$item[1]}";
            })
            ->implode("\n");

        // A new ADMIN is a stronger signal than anything else here — lead with it.
        $hasNewAdmin = collect($changes)->contains(fn (array $item): bool => $item[0] === 'admins' && $item[2] === 'added');

        $team->alert(
            $hasNewAdmin ? "🚨 משתמש מנהל חדש באתר {$site->domain}" : "🧩 שינוי בהתקנות באתר {$site->domain}",
            ($hasNewAdmin
                ? "זוהה משתמש מנהל (administrator) חדש באתר {$site->domain}"
                : "זוהה שינוי בתוספים/תבניות/משתמשי הניהול באתר {$site->domain}").
                ($site->customer ? " ({$site->customer->name})" : '').":\n{$lines}\n\n".
                ($hasNewAdmin
                    ? 'אם אף אחד מהצוות לא יצר את המשתמש הזה — ייתכן שהאתר נפרץ. בדקו מיד והסירו משתמש לא מוכר.'
                    : 'אם השינוי אינו מוכר — כדאי לבדוק.'),
            rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}",
        );
    }
}
