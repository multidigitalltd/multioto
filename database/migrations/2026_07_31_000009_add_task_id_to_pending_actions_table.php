<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The task a proposal was filed on behalf of, when one was waiting on it.
 *
 * A task handed to the AI agent stays claimed while a fix it proposed waits for
 * a decision — the same as a proposal made in the foreground. Without the link,
 * approving or rejecting that fix could never hand the task back, and it would
 * stay "in progress" with nobody working on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pending_actions', 'task_id')) {
            return;
        }

        Schema::table('pending_actions', function (Blueprint $table): void {
            $table->unsignedBigInteger('task_id')->nullable()->after('ticket_id');
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::table('pending_actions', function (Blueprint $table): void {
            $table->dropIndex(['task_id']);
            $table->dropColumn('task_id');
        });
    }
};
