<?php

namespace App\Services\Hosting;

use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * Placeholder driver until the real hosting panel API is chosen (§13).
 * Logs the intent so suspended sites can be handled manually in the interim.
 */
class LogHostingClient implements HostingClient
{
    public function suspendSite(Site $site): void
    {
        Log::channel('single')->warning('HostingClient(log): suspend requested', [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'hosting_ref' => $site->hosting_ref,
        ]);
    }

    public function restoreSite(Site $site): void
    {
        $this->record('restore', $site);
    }

    public function clearCache(Site $site): void
    {
        $this->record('clear-cache', $site);
    }

    public function restartSite(Site $site): void
    {
        $this->record('restart', $site);
    }

    private function record(string $operation, Site $site): void
    {
        Log::channel('single')->warning("HostingClient(log): {$operation} requested", [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'hosting_ref' => $site->hosting_ref,
        ]);
    }
}
