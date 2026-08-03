<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The moment this run first reached out to the destination.
 *
 * A failed run and an orphaned archive look identical from the row alone, and
 * they call for opposite treatment: a run that never got as far as uploading
 * left nothing behind and its row is safe to remove, while one that was killed
 * mid-upload — or whose upload succeeded and whose response was lost — may have
 * a whole archive sitting at the destination, and the row is the only thing
 * that names it.
 *
 * Recorded before the upload rather than inferred afterwards, because the two
 * cases are indistinguishable by then: an unreachable destination cannot say
 * whether anything is there. Rows that predate the marker are filled in as
 * having reached it, since "unknown" has to read as the answer that keeps the
 * row rather than the one that drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fresh = ! Schema::hasColumn('backups', 'upload_started_at');

        Schema::table('backups', function (Blueprint $table): void {
            if (! Schema::hasColumn('backups', 'upload_started_at')) {
                $table->timestamp('upload_started_at')->nullable();
            }
        });

        // Rows that predate the marker are filled in as though they DID reach
        // the destination. They were written before anything recorded the
        // answer, so nothing can now say which of them left an archive behind —
        // and the safe reading of "unknown" is the one that keeps the row.
        //
        // It costs those rows nothing in practice: deleting an object that is
        // not there succeeds, so as soon as the destination can be reached
        // again they clear normally. Only a row whose destination is still
        // unreachable stays, which is exactly the case that should.
        if ($fresh) {
            DB::table('backups')
                ->whereNull('upload_started_at')
                ->update(['upload_started_at' => DB::raw('COALESCE(finished_at, created_at)')]);
        }
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn('upload_started_at');
        });
    }
};
