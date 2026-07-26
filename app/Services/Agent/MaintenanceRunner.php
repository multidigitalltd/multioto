<?php

namespace App\Services\Agent;

use App\Enums\SiteChangeStatus;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\SystemLog;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Executes an approved weekly-maintenance batch: update each outdated plugin
 * on the site, health-check the homepage after EVERY update, and stop the
 * moment the site stops answering — so one bad update never cascades into a
 * broken site with five more updates on top. Every update lands in the site
 * change journal (the before/after history), and the run is summarised in
 * the event log.
 */
class MaintenanceRunner
{
    /** Per-update MCP timeout — plugin downloads can be slow. */
    private const UPDATE_TIMEOUT_SECONDS = 120;

    public function __construct(
        private McpClient $mcp,
        private SiteChangeJournal $journal,
        private TeamNotifier $team,
    ) {}

    /**
     * The site's plugins with an update available, per the connected agent.
     *
     * @return list<array{slug: string, name: string, version: string}>
     */
    public function pendingUpdates(Site $site): array
    {
        $raw = $this->mcp->textContent($this->mcp->callTool($site, 'wp_plugin_list'));
        $rows = json_decode($raw, true);

        if (! is_array($rows)) {
            return [];
        }

        $updates = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! ($row['update_available'] ?? false)) {
                continue;
            }

            $file = (string) ($row['plugin'] ?? '');

            if ($file === '') {
                continue;
            }

            $updates[] = [
                'slug' => $file,
                'name' => (string) ($row['name'] ?? $file),
                'version' => (string) ($row['version'] ?? ''),
            ];
        }

        return $updates;
    }

    /** Run an approved maintenance batch (called by the ApprovalGate). */
    public function run(PendingAction $action): void
    {
        $site = Site::find((int) data_get($action->payload, 'site_id'));
        $updates = (array) data_get($action->payload, 'updates', []);

        if (! $site || $updates === []) {
            throw new \RuntimeException('האתר או רשימת העדכונים חסרים בהצעה.');
        }

        if (! config('agent.actions_enabled')) {
            throw new \RuntimeException('מנגנון פעולות ה-AI כבוי (kill-switch). יש להפעיל אותו בהגדרות הסוכן.');
        }

        $done = [];

        foreach ($updates as $update) {
            $slug = (string) ($update['slug'] ?? '');
            $name = (string) ($update['name'] ?? $slug);

            if ($slug === '') {
                continue;
            }

            try {
                $this->mcp->callTool($site, 'wp_plugin_update', ['plugin' => $slug], self::UPDATE_TIMEOUT_SECONDS);
            } catch (\Throwable $e) {
                $this->journal->record($site, "תחזוקה שבועית — עדכון {$name} נכשל", 'wp_plugin_update', ['plugin' => $slug],
                    initiatedBy: 'maintenance', pendingAction: $action, status: SiteChangeStatus::Failed);

                throw new \RuntimeException("עדכון התוסף {$name} נכשל: ".Str::limit($e->getMessage(), 150)
                    .($done !== [] ? ' (עודכנו לפני הכשל: '.implode(', ', $done).')' : ''));
            }

            $this->journal->record($site, "תחזוקה שבועית — עודכן {$name}", 'wp_plugin_update', ['plugin' => $slug],
                beforeState: 'גרסה קודמת: '.(string) ($update['version'] ?? '?'),
                initiatedBy: 'maintenance', pendingAction: $action);
            $done[] = $name;

            // The site must still answer after EVERY update — one broken
            // plugin must not be buried under the next four updates.
            if (! $this->healthy($site)) {
                $this->team->alert(
                    "🚨 תחזוקה שבועית נעצרה — {$site->domain} הפסיק להגיב",
                    "אחרי עדכון התוסף \"{$name}\" דף הבית של {$site->domain} הפסיק לענות תקין. "
                        .'התחזוקה נעצרה מיד (עודכנו: '.implode(', ', $done).'). בדקו את האתר — ייתכן שנדרש שחזור של התוסף.',
                    rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}",
                );

                throw new \RuntimeException("האתר הפסיק להגיב אחרי עדכון {$name} — התחזוקה נעצרה והצוות הותרע.");
            }
        }

        SystemLog::record('info', 'maintenance',
            "תחזוקה שבועית לאתר {$site->domain} הושלמה — עודכנו ".count($done).' תוספים: '.implode(', ', $done).'.',
            ['site_id' => $site->id, 'action_id' => $action->id]);
    }

    /** Is the homepage still answering with a successful status? */
    private function healthy(Site $site): bool
    {
        try {
            return Http::timeout((int) config('billing.monitoring.timeout_seconds', 10))
                ->get($site->homepageUrl())
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
