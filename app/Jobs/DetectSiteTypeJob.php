<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Agent\McpClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Classify a site as a store/brochure from its installed plugins, via the
 * connected agent's `wp_plugin_list` tool (WooCommerce ⇒ store). Runs in the
 * background so it never blocks a request. By default it won't override a value
 * the team set by hand; $force re-classifies on explicit request.
 *
 * Best-effort: if the site isn't connected or doesn't expose the plugin tool,
 * it simply leaves the type unchanged.
 */
class DetectSiteTypeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(public int $siteId, public bool $force = false) {}

    public function handle(McpClient $mcp): void
    {
        $site = Site::find($this->siteId);

        if (! $site || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return;
        }

        // Already classified and not an explicit re-detect → nothing to do (and
        // no need to spend an MCP round-trip on the plugin list).
        if (! $this->force && $site->site_type !== null) {
            return;
        }

        // Only attempt when the site actually exposes the plugin-list tool.
        $tools = collect((array) data_get($site->mcp_capabilities, 'tools', []))->pluck('name');
        if (! $tools->contains('wp_plugin_list')) {
            return;
        }

        try {
            $result = $mcp->callTool($site, 'wp_plugin_list');
            $text = $mcp->textContent($result);
        } catch (\Throwable $e) {
            Log::warning('DetectSiteTypeJob: plugin list failed', ['site' => $this->siteId, 'error' => $e->getMessage()]);

            return;
        }

        if (trim($text) === '') {
            return;
        }

        $site->applyDetectedType($text, $this->force);
    }
}
