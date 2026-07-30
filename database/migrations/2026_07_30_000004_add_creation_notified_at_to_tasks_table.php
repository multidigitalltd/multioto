<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the "new task" notification for this row was enqueued.
 *
 * A task dictated into the WhatsApp management group is created inside a job
 * that retries. The row itself is idempotent (source_ref), but that alone made
 * the notification WORSE than not idempotent: on a retry the row already
 * exists, so "notify only when just created" would skip it forever — and an
 * unassigned task is never picked up by the reminder job either, so nobody
 * outside the group would ever learn about it.
 *
 * Stamped instead of inferred, so a retry can tell the difference between
 * "already told them" and "never got that far".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'creation_notified_at')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('creation_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('creation_notified_at');
        });
    }
};
