<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The external message a task was opened from, when it was not opened by hand.
 *
 * A task dictated into the WhatsApp management group is created inside the
 * inbound-message job, and that job retries. Without a key tied to the message
 * itself, one sentence said once could become two identical tasks (and two
 * notifications) — so the message id is stored and enforced unique, the same
 * idempotency the ticket-opening path already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'source_ref')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('source_ref')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique(['source_ref']);
            $table->dropColumn('source_ref');
        });
    }
};
