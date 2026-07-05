<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Hosting\HostingClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Suspend a site on the hosting panel at the end of the dunning flow.
 */
class SuspendSiteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $siteId) {}

    public function handle(HostingClient $hosting): void
    {
        $site = Site::find($this->siteId);

        if (! $site || $site->status === SiteStatus::Suspended) {
            return;
        }

        $hosting->suspendSite($site);

        $site->update(['status' => SiteStatus::Suspended]);
    }
}
