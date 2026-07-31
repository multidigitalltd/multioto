<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The list of external references a restore left without a row.
 *
 * Kept on the backup row because the backup table is the one place a restore
 * does not replace — anywhere else, the record of what the restore discarded
 * would be discarded by the restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->json('restore_report')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn('restore_report');
        });
    }
};
