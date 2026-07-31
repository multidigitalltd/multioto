<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which background jobs are still holding a claimed task.
 *
 * A task handed to the AI agent is marked "in progress" for the length of that
 * run. But the agent can start work that OUTLIVES the run — a site
 * investigation reports its findings minutes later — and the run that queued it
 * may then time out and be told the instruction failed. Without a persisted
 * hold, its failure handler gives the task back while the investigation is
 * still running, and the task can be delegated a second time.
 *
 * A LIST of holder tokens rather than a counter: one instruction may start more
 * than one investigation, and a job that dies is failed on a FRESH instance of
 * itself, so it cannot remember whether it already gave its hold back. Taking a
 * named token out of a list is the same whether it happens once or twice, while
 * a counter would let one job's second attempt eat another job's hold. The task
 * goes back to the humans when the list is empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'background_holds')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            $table->json('background_holds')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('background_holds');
        });
    }
};
