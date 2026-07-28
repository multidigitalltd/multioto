<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * broadcasts.segment was created as `json`. Postgres has no equality operator
 * for `json`, so any query that compares or de-duplicates rows carrying it —
 * a SELECT DISTINCT behind a Filament filter, for instance — fails outright.
 * `jsonb` behaves; on SQLite both map to text, so tests are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE broadcasts ALTER COLUMN segment TYPE jsonb USING segment::jsonb');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE broadcasts ALTER COLUMN segment TYPE json USING segment::json');
        }
    }
};
