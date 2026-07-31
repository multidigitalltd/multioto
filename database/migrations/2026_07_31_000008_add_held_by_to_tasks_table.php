<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is holding a claimed task, when it is not the run that claimed it.
 *
 * A task handed to the AI agent is marked "in progress" for the length of that
 * run. But the agent can start work that OUTLIVES the run — a site
 * investigation reports its findings minutes later — and the run that queued it
 * may then time out and be told the instruction failed. Without a persisted
 * holder, its failure handler hands the task back while the investigation is
 * still running, and the task can be delegated a second time.
 *
 * So ownership is written down before the hand-off, and only the holder gives
 * the task back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'held_by')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('held_by')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('held_by');
        });
    }
};
