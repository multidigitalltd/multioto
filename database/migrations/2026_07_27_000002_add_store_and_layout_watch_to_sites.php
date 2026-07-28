<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two silent-failure watches that uptime monitoring cannot see:
 *  - store_pulse:    the last sales-pulse reading + when we last alerted, so a
 *                    shop that is "up" but stopped taking orders is caught.
 *  - layout_snapshot: the homepage's structural fingerprint, so an update that
 *                     breaks the header/menu is caught even though the page
 *                     still answers 200.
 *
 * jsonb (not json): Postgres cannot compare `json`, which breaks any later
 * DISTINCT/GROUP BY over the row. SQLite maps jsonb to text, so tests are fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->jsonb('store_pulse')->nullable();
            $table->jsonb('layout_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['store_pulse', 'layout_snapshot']);
        });
    }
};
