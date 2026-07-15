<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SystemLog;
use App\Services\Agent\SiteAgent;
use App\Services\Agent\SiteMemoryStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Runs the AI site operator in the background (heavy: several MCP + model
 * calls). The AI investigates read-only and files any fix as a manager-approval
 * proposal; this job records its written summary so the team can read what it
 * found, without anything being changed on the site.
 */
class InvestigateSiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $siteId, public string $goal) {}

    public function handle(SiteAgent $agent, SiteMemoryStore $memory): void
    {
        $site = Site::find($this->siteId);

        if (! $site) {
            return;
        }

        $summary = $agent->investigate($site, $this->goal);

        if (blank($summary)) {
            SystemLog::record('warning', 'ai', "אבחון AI לאתר {$site->domain} לא הניב תוצאה", ['site_id' => $site->id]);

            return;
        }

        // Keep the latest diagnosis on the site and in the system log; any fix
        // the AI proposed is already waiting in the approvals inbox.
        $memory->put($site, 'אבחון AI אחרון', Str::limit($summary, 2000), 'ai');
        SystemLog::record('info', 'ai', "אבחון AI לאתר {$site->domain}", [
            'site_id' => $site->id,
            'summary' => Str::limit($summary, 1000),
        ]);
    }
}
