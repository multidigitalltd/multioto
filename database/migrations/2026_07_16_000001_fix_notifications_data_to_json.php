<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair installs whose notifications.data landed as `text` (the original
 * create migration used text). Filament's in-panel bell queries it with
 * where('data->format', 'filament') — on PostgreSQL that needs a `json` column,
 * else every panel page 500s with "operator does not exist: text ->> unknown".
 *
 * PostgreSQL only: on SQLite/MySQL a json column already behaves as text and
 * the JSON path query works, so there is nothing to change (and SQLite can't
 * ALTER COLUMN TYPE anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Existing rows already hold valid JSON strings, so the cast is safe.
        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};
