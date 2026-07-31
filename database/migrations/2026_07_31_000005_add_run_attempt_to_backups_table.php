<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which run a row belongs to.
 *
 * A backup job killed on timeout dies outside its own error handling, and the
 * failure handler has to find the row it left behind. "The latest running one"
 * is not that row when the job died before creating one — it is somebody else's
 * live backup, and marking it failed takes away its protection while its worker
 * is still writing the archive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->string('run_attempt', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn('run_attempt');
        });
    }
};
