<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity and timing for one restore attempt.
 *
 * A claim on its own cannot be released safely: the payload it was made for
 * may still be sitting in a queue, and clearing the claim would let a second
 * restore start while the first is on its way. With an attempt id, releasing
 * means rotating it — the old payload arrives, finds it is no longer the
 * current attempt, and stops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->string('restore_attempt', 36)->nullable();
            $table->timestamp('restore_queued_at')->nullable();
            $table->timestamp('restore_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropColumn(['restore_attempt', 'restore_queued_at', 'restore_started_at']);
        });
    }
};
