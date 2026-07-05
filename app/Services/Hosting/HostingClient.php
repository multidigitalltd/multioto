<?php

namespace App\Services\Hosting;

use App\Models\Site;

/**
 * Suspend/restore a site on the hosting panel at the end of the dunning flow.
 *
 * The concrete panel API (cPanel/WHM, Plesk, DirectAdmin…) is an open decision
 * (§13) — implementations are swapped via config('billing.hosting.driver').
 */
interface HostingClient
{
    public function suspendSite(Site $site): void;

    public function restoreSite(Site $site): void;
}
