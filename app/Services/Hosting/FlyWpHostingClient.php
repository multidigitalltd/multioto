<?php

namespace App\Services\Hosting;

use App\Models\Site;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * FlyWP hosting driver for the dunning suspend/restore flow.
 *
 * Suspension is done via FlyWP's maintenance mode — the most reversible,
 * least destructive lever: the site stays hosted and its data intact, visitors
 * see a maintenance page, and clearing it on payment restores the site in
 * seconds. `sites.hosting_ref` holds the FlyWP site id.
 *
 * Endpoints follow FlyWP's REST API; the exact maintenance-toggle path is
 * configurable (config('billing.hosting.flywp')) so it can be corrected to the
 * account's API version without a code change.
 */
class FlyWpHostingClient implements HostingClient
{
    public function suspendSite(Site $site): void
    {
        $this->toggleMaintenance($site, true);
    }

    public function restoreSite(Site $site): void
    {
        $this->toggleMaintenance($site, false);
    }

    public function clearCache(Site $site): void
    {
        $this->action($site, 'cache_path');
    }

    public function restartSite(Site $site): void
    {
        $this->action($site, 'restart_path');
    }

    /**
     * POST a configurable FlyWP action endpoint for a site. The path template
     * lives in config so it can track FlyWP's API version without a code change.
     */
    protected function action(Site $site, string $configKey): void
    {
        if (blank($site->hosting_ref)) {
            throw new RuntimeException("Site {$site->id} has no FlyWP hosting_ref; cannot manage on FlyWP.");
        }

        $config = config('billing.hosting.flywp');

        $path = str_replace(
            ['{server}', '{site}'],
            [$config['server_id'], $site->hosting_ref],
            $config[$configKey],
        );

        $this->client()->post($path)->throw();
    }

    protected function toggleMaintenance(Site $site, bool $enabled): void
    {
        if (blank($site->hosting_ref)) {
            throw new RuntimeException("Site {$site->id} has no FlyWP hosting_ref; cannot manage on FlyWP.");
        }

        $config = config('billing.hosting.flywp');

        $path = str_replace(
            ['{server}', '{site}'],
            [$config['server_id'], $site->hosting_ref],
            $config['maintenance_path'],
        );

        $response = $this->client()->post($path, ['enabled' => $enabled]);

        $response->throw();
    }

    protected function client(): PendingRequest
    {
        $config = config('billing.hosting.flywp');

        return Http::baseUrl($config['base_url'])
            ->withToken($config['api_token'])
            ->acceptJson()
            ->timeout(30);
    }
}
