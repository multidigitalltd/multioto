<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each site can now be connected to the AI site agent: an MCP endpoint we call
 * (with a per-site secret, encrypted at rest), the version of our companion
 * plugin the site is running, and a hashed per-site token the plugin presents
 * to us for self-updates. Columns are added only if missing, so a partial run
 * can be replayed cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'mcp_endpoint')) {
                $table->string('mcp_endpoint')->nullable()->after('hosting_ref');
            }
            if (! Schema::hasColumn('sites', 'mcp_secret')) {
                // Encrypted at rest via the model cast — the key we present to
                // the site's MCP server.
                $table->text('mcp_secret')->nullable()->after('mcp_endpoint');
            }
            if (! Schema::hasColumn('sites', 'mcp_enabled')) {
                $table->boolean('mcp_enabled')->default(false)->after('mcp_secret');
            }
            if (! Schema::hasColumn('sites', 'environment')) {
                // production vs staging — gates which risk tiers may run.
                $table->string('environment')->default('production')->after('mcp_enabled');
            }
            if (! Schema::hasColumn('sites', 'mcp_capabilities')) {
                $table->json('mcp_capabilities')->nullable()->after('environment');
            }
            if (! Schema::hasColumn('sites', 'mcp_last_seen_at')) {
                $table->timestamp('mcp_last_seen_at')->nullable()->after('mcp_capabilities');
            }
            if (! Schema::hasColumn('sites', 'agent_plugin_version')) {
                $table->string('agent_plugin_version')->nullable()->after('mcp_last_seen_at');
            }
            if (! Schema::hasColumn('sites', 'agent_token')) {
                // A SHA-256 hash of the site's token — the plaintext is shown
                // once on generation and lives only in the site's plugin config.
                $table->string('agent_token', 64)->nullable()->unique()->after('agent_plugin_version');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter([
            'mcp_endpoint',
            'mcp_secret',
            'mcp_enabled',
            'environment',
            'mcp_capabilities',
            'mcp_last_seen_at',
            'agent_plugin_version',
            'agent_token',
        ], fn (string $column): bool => Schema::hasColumn('sites', $column)));

        if ($columns !== []) {
            Schema::table('sites', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
