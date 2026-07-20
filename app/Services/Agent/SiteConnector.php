<?php

namespace App\Services\Agent;

use App\Jobs\DetectSiteTypeJob;
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
                'tools' => collect($tools)->map(fn (array $tool): array => [
                    'name' => (string) ($tool['name'] ?? ''),
                    'description' => Str::limit((string) ($tool['description'] ?? ''), 500),
                    // The MCP behaviour hints — the machine-verifiable signal we
                    // trust for read-only/destructive classification (a tool's
                    // NAME is never trusted as a security control).
                    'read_only' => (bool) data_get($tool, 'annotations.readOnlyHint', false),
                    'destructive' => (bool) data_get($tool, 'annotations.destructiveHint', false),
                    // A compact parameter spec, so the panel can build a real
                    // labelled form per tool instead of asking for raw JSON. The
                    // plugin still validates the actual call, so this is a UI
                    // convenience, not a trusted contract.
                    'params' => $this->compactParams(data_get($tool, 'inputSchema')),
                ])->values()->all(),
            ],
            'mcp_last_seen_at' => now(),
        ])->save();

        // Classify the site (store/brochure) from its plugins now that the tool
        // catalogue is fresh — best-effort and non-forced, so a manual choice is
        // preserved. Firing after EVERY sync (a manual "בדוק חיבור AI" or a
        // capability refresh) means existing sites get classified without waiting
        // for a plugin-version bump; an already-classified site is a no-op.
        DetectSiteTypeJob::dispatch($site->id);

        return count($tools);
    }

    /**
     * Flatten a tool's JSON-Schema input into a compact list the form builder
     * can render: one entry per top-level property with its type, description,
     * enum options and whether it is required.
     *
     * @param  mixed  $schema
     * @return list<array{name: string, type: string, description: string, enum: list<scalar>, required: bool}>
     */
    private function compactParams($schema): array
    {
        $properties = (array) data_get($schema, 'properties', []);
        $required = (array) data_get($schema, 'required', []);

        return collect($properties)
            ->map(fn ($definition, $name): array => [
                'name' => (string) $name,
                'type' => (string) (data_get($definition, 'type') ?: 'string'),
                'description' => Str::limit((string) data_get($definition, 'description', ''), 200),
                'enum' => array_values(array_filter((array) data_get($definition, 'enum', []), 'is_scalar')),
                'required' => in_array($name, $required, true),
            ])
            ->values()
            ->all();
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
