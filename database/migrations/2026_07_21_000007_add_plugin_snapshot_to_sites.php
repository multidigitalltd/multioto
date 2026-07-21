<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last-seen set of installed plugin/theme identities per site, so the
 * plugin-change watcher can diff against it and alert the team when something
 * new is installed. Shape: { plugins: [...ids], themes: [...ids] }.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('plugin_snapshot')->nullable()->after('mcp_capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('plugin_snapshot');
        });
    }
};
