<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * whether anything is there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            if (! Schema::hasColumn('backups', 'upload_started_at')) {
                $table->timestamp('upload_started_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn('upload_started_at');
        });
    }
};
