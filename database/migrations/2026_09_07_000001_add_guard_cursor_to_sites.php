<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far the panel has read into a site's intrusion-guard log.
 *
 * The guard on the site removes a threat whether or not the panel is up, and
 * writes what it did to a log it never clears on read. This cursor is how the
 * panel drains that log exactly once: everything after the last id it recorded.
 * Without it, a removal would either be reported every hour forever or be
 * dropped by a panel that crashed halfway through reporting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('guard_cursor')->default(0)->after('plugin_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('guard_cursor');
        });
    }
};
