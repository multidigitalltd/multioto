<?php

namespace App\Services\Agent;

use App\Models\Site;
use App\Services\Health\ConnectionResult;
use Illuminate\Support\Str;

/**
 * Connects a site to the agent: runs the MCP handshake, discovers the tools the
 * site exposes and caches them on the site row — so the planning layer knows
 * what each site can do without a network round-trip, and the panel can show a
 * live connection status.
 */
class SiteConnector
{
    public function __construct(private McpClient $mcp) {}

    /**
     * Handshake + tool discovery. Persists server info, the tool list and the
     * last-seen timestamp on the site. Returns the number of tools found.
     */
    public function sync(Site $site): int
    {
        $info = $this->mcp->initialize($site);
        $tools = $this->mcp->listTools($site);

        $site->forceFill([
            'mcp_capabilities' => [
                'server' => $info['serverInfo'] ?? [],
                'protocol' => $info['protocolVersion'] ?? McpClient::PROTOCOL_VERSION,
                // Only what the planner needs; input schemas are re-fetched live
                // before execution so we never act on a stale contract.
                'tools' => collect($tools)->map(fn (array $tool): array => [
                    'name' => (string) ($tool['name'] ?? ''),
                    'description' => Str::limit((string) ($tool['description'] ?? ''), 500),
                    // The MCP behaviour hints — the machine-verifiable signal we
                    // trust for read-only/destructive classification (a tool's
                    // NAME is never trusted as a security control).
                    'read_only' => (bool) data_get($tool, 'annotations.readOnlyHint', false),
                    'destructive' => (bool) data_get($tool, 'annotations.destructiveHint', false),
                ])->values()->all(),
            ],
            'mcp_last_seen_at' => now(),
        ])->save();

        return count($tools);
    }

    /** Panel "test connection" — human-readable outcome, never throws. */
    public function testConnection(Site $site): ConnectionResult
    {
        if (! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return ConnectionResult::notConfigured('חיבור ה-MCP לא הוגדר או כבוי לאתר הזה');
        }

        try {
            $count = $this->sync($site);

            return ConnectionResult::ok("מחובר — האתר חושף {$count} כלים");
        } catch (\Throwable $e) {
            return ConnectionResult::fail('החיבור נכשל: '.Str::limit(trim($e->getMessage()) ?: class_basename($e), 200));
        }
    }
}
