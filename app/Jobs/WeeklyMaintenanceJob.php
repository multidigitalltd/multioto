<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SystemLog;
use App\Services\Agent\MaintenanceRunner;
use App\Services\Automation\ApprovalGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Proactive weekly maintenance for one connected site: list the plugins with
 * a pending update and PROPOSE the batch through the approval gate. With a
 * standing approval ("אשר תמיד" on תחזוקה שבועית) the batch runs immediately
 * and the owner just gets the report; otherwise it waits like any proposal.
 * The runner health-checks the homepage after every single update and stops
 * on the first sign of breakage.
 */
class WeeklyMaintenanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $siteId) {}

    public function handle(MaintenanceRunner $runner, ApprovalGate $gate): void
    {
        if (! config('agent.weekly_maintenance', true)) {
            return;
        }

        $site = Site::with('customer')->find($this->siteId);

        if (! $site || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return;
        }

        try {
            $updates = $runner->pendingUpdates($site);
        } catch (\Throwable $e) {
            SystemLog::record('warning', 'maintenance',
                "תחזוקה שבועית לאתר {$site->domain}: לא ניתן היה לקרוא את רשימת התוספים — ".$e->getMessage(),
                ['site_id' => $site->id]);

            return;
        }

        $max = (int) config('agent.weekly_maintenance_max_updates', 10);
        $skipped = count($updates) - $max;
        $updates = array_slice($updates, 0, $max);

        if ($updates === []) {
            return; // Everything is up to date — nothing to propose, no noise.
        }

        $names = implode(', ', array_column($updates, 'name'));

        $gate->propose(
            'maintenance_update',
            "תחזוקה שבועית לאתר {$site->domain} — עדכון ".count($updates)." תוספים: {$names}."
                .($skipped > 0 ? " (עוד {$skipped} ימתינו לשבוע הבא)" : '')
                .' כל עדכון מלווה בבדיקת תקינות של דף הבית, והתהליך נעצר מיד אם האתר מפסיק להגיב.',
            ['site_id' => $site->id, 'updates' => $updates],
            customerId: $site->customer_id,
            proposedBy: 'maintenance',
        );
    }
}
