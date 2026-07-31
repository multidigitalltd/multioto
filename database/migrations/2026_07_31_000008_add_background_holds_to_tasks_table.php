<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many background jobs are still holding a claimed task.
 *
 * A task handed to the AI agent is marked "in progress" for the length of that
 * run. But the agent can start work that OUTLIVES the run — a site
 * investigation reports its findings minutes later — and the run that queued it
 * may then time out and be told the instruction failed. Without a persisted
 * hold, its failure handler gives the task back while the investigation is
 * still running, and the task can be delegated a second time.
 *
 * A count rather than a flag because one instruction may start more than one
 * investigation: the task goes back to the humans when the LAST of them is
 * done, not when the first happens to finish.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'background_holds')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('background_holds')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('background_holds');
        });
    }
};
