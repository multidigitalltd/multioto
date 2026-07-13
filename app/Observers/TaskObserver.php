<?php

namespace App\Observers;

use App\Enums\TaskStatus;
use App\Models\Task;

/**
 * Keeps a task's derived fields honest: stamp completed_at when it is marked
 * done (and clear it if reopened), and reset the reminder clock whenever the
 * task is rescheduled or its status changes — so a reopened or postponed task
 * can be reminded again. Runs before save so it persists in the same write.
 */
class TaskObserver
{
    public function saving(Task $task): void
    {
        if ($task->isDirty('status')) {
            $task->completed_at = $task->status === TaskStatus::Done ? ($task->completed_at ?? now()) : null;
        }

        // A due-date or status change opens a fresh reminder cycle — but don't
        // undo the reminder job's own stamp (it writes reminded_at directly).
        if (($task->isDirty('due_at') || $task->isDirty('status')) && ! $task->isDirty('reminded_at')) {
            $task->reminded_at = null;
        }
    }
}
