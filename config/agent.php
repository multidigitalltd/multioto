<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI site agent
    |--------------------------------------------------------------------------
    |
    | Settings for the agent that operates on customers' WordPress sites over
    | MCP. Each site runs our companion plugin (which can update itself from the
    | channel below), authenticates to us with a per-site token, and exposes an
    | MCP endpoint we call — always behind the human-approval gate.
    |
    */

    'plugin' => [
        // The current version of the companion plugin we ship. A site reporting
        // an older version is told to update itself from the download channel —
        // so we never have to re-install the plugin by hand on every site.
        'current_version' => env('AGENT_PLUGIN_VERSION', '1.0.0'),

        // Private disk + path prefix where release zips live: {path}/{version}.zip.
        'disk' => env('AGENT_PLUGIN_DISK', 'local'),
        'path' => env('AGENT_PLUGIN_PATH', 'agent-plugin'),

        // How long a signed plugin-download link stays valid, in minutes.
        'download_ttl_minutes' => (int) env('AGENT_PLUGIN_DOWNLOAD_TTL_MINUTES', 15),

        // Optional human-readable changelog URL surfaced to the site.
        'changelog_url' => env('AGENT_PLUGIN_CHANGELOG_URL', ''),
    ],

    'mcp' => [
        // Timeout for a single MCP call to a site, in seconds.
        'timeout_seconds' => (int) env('AGENT_MCP_TIMEOUT', 30),
    ],

    /*
    | Risk tiers for site tools, matched by name substring (first match wins,
    | highest tier checked first). Unknown tools default to tier 2 — "a change" —
    | so a tool we never classified still requires explicit human approval and
    | is never treated as read-only.
    |
    |   0 read-only · 1 safe/reversible · 2 change · 3 destructive
    |
    | Tier-3 tools only ever run on staging sites.
    */
    'risk' => [
        3 => ['exec', 'eval', 'sql', 'db_write', 'file_write', 'file_edit', 'delete', 'drop', 'remove'],
        1 => ['cache', 'restart', 'maintenance', 'transient'],
        0 => ['list', 'get', 'read', 'health', 'status', 'log', 'info', 'check', 'search'],
    ],

];
