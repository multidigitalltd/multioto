<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Agent\SiteConnector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-discover a site's MCP tool catalog and cache it on the site row. Dispatched
 * when a site reports a new plugin version at check-in, so tools added by a
 * plugin update (menus, content, …) become usable immediately — without waiting
 * for someone to press "בדוק חיבור AI" by hand.
 *
 * Best-effort: a site that is briefly unreachable just keeps its previous
 * catalog and gets refreshed on the next check-in.
 */
class RefreshSiteCapabilitiesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(public int $siteId) {}

    public function handle(SiteConnector $connector): void
    {
        $site = Site::find($this->siteId);

        if (! $site || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return;
        }

        try {
            // sync() also classifies the site (store/brochure) from its plugins.
            $connector->sync($site);
        } catch (\Throwable $e) {
            Log::warning('RefreshSiteCapabilitiesJob: sync failed', ['site' => $this->siteId, 'error' => $e->getMessage()]);
        }
    }
}
