<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Servers that already ran the 1.70.0 migration got allowed_modules as
     * plain json — which has NO equality operator in Postgres, so any SELECT
     * DISTINCT over users (the task/ticket assignee pickers) failed with a
     * 500. Convert in place to jsonb; fresh installs already create jsonb.
     * SQLite (tests/dev) stores both the same way — nothing to do there.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN allowed_modules TYPE jsonb USING allowed_modules::jsonb');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN allowed_modules TYPE json USING allowed_modules::json');
        }
    }
};
