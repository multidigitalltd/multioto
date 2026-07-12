<?php

namespace App\Services\Hosting;

use App\Models\Site;

/**
 * Operate a customer's site on the hosting panel: the dunning suspend/restore
 * lever plus the safe, reversible "operator" fixes the WordPress agent proposes
 * (always after human approval via the ApprovalGate).
 *
 * The concrete panel API (FlyWP today) is swapped via
 * config('billing.hosting.driver'); 'log' records intent only.
 */
interface HostingClient
{
    public function suspendSite(Site $site): void;

    public function restoreSite(Site $site): void;

    /**
     * Clear the site's server/page cache — the most common, safest first-line
     * fix for "the site shows old content" / "changes aren't visible".
     */
    public function clearCache(Site $site): void;

    /**
     * Restart the site's PHP/web process — a safe recovery for a hung site
     * (502/504, white screen) that keeps all data intact.
     */
    public function restartSite(Site $site): void;
}
