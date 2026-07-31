<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token of the file journal whose restore committed.
 *
 * Written INSIDE the replacement transaction, so its presence is the same fact
 * as the transaction having committed. A restore killed a moment after the
 * commit leaves a journal on disk that looks exactly like a rolled-back one —
 * and putting those files back would strip the archive's files off a database
 * that is now the archive. This is what tells the two apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->string('restore_journal', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn('restore_journal');
        });
    }
};
