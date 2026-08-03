<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far this run got towards the destination.
 *
 * A failed run and an orphaned archive look identical from the row alone, and
 * they call for opposite treatment: a run that never reached the destination
 * left nothing behind and its row is safe to remove, while one that was killed
 * mid-upload — or whose upload succeeded and whose response was lost — may have
 * a whole archive sitting there, and the row is the only thing that names it.
 *
 * Written as POSITIVE evidence in both directions, never inferred from an
 * absence. A row with nothing recorded here is one nothing can speak for — it
 * predates the column, or a worker still running the previous code wrote it
 * during a deployment — and those have to read as "may have left an archive",
 * which is the answer that keeps the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            if (! Schema::hasColumn('backups', 'upload_phase')) {
                $table->string('upload_phase', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn('upload_phase');
        });
    }
};
