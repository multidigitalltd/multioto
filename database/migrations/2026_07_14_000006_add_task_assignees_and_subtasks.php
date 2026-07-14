<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A task can now be assigned to SEVERAL team members (task_user pivot, replacing
 * the single assigned_to column) and can carry a checklist of sub-tasks
 * (subtasks JSON) that are ticked off one by one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_user', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'user_id']);
        });

        // Carry existing single assignees over to the pivot before dropping it.
        DB::table('tasks')->whereNotNull('assigned_to')->orderBy('id')->get(['id', 'assigned_to'])
            ->each(fn ($task) => DB::table('task_user')->insertOrIgnore([
                'task_id' => $task->id,
                'user_id' => $task->assigned_to,
            ]));

        Schema::table('tasks', function (Blueprint $table) {
            // Drop the composite index that references assigned_to before the
            // column, or SQLite refuses to drop it.
            $table->dropIndex(['assigned_to', 'status']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->json('subtasks')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('description')->constrained('users')->nullOnDelete();
            $table->dropColumn('subtasks');
        });

        // Best-effort restore: first assignee becomes the single assigned_to.
        DB::table('task_user')->orderBy('task_id')->get()->groupBy('task_id')
            ->each(fn ($rows, $taskId) => DB::table('tasks')->where('id', $taskId)
                ->update(['assigned_to' => $rows->first()->user_id]));

        Schema::dropIfExists('task_user');
    }
};
