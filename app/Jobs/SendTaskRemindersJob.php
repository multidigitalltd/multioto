<?php

namespace App\Jobs;

use App\Jobs\Concerns\PausesForShabbat;
use App\Mail\NotificationMail;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Daily reminder to each team member about their open tasks that are due today
 * or overdue. One email per assignee listing all their due tasks; each task is
 * reminded once (reminded_at), and the clock resets when a task is rescheduled
 * or reopened (see TaskObserver). Dispatched by the scheduler.
 */
class SendTaskRemindersJob implements ShouldQueue
{
    use PausesForShabbat;
    use Queueable;

    public function handle(): void
    {
        if ($this->rescheduledForShabbat()) {
            return;
        }

        if (! (bool) config('billing.support.task_reminders.enabled', true)) {
            return;
        }

        $due = Task::query()->due()->whereNull('reminded_at')->with('assignees')->get();

        // Fan a task out to each of its assignees — a shared task reminds everyone
        // it's on. Build one bucket of tasks per team member.
        $perAssignee = [];
        foreach ($due as $task) {
            foreach ($task->assignees as $user) {
                $perAssignee[$user->id]['user'] = $user;
                $perAssignee[$user->id]['tasks'][] = $task;
            }
        }

        foreach ($perAssignee as $entry) {
            $user = $entry['user'];
            $tasks = collect($entry['tasks']);

            if ($user instanceof User && filled($user->email)) {
                Mail::to($user->email)->send(new NotificationMail(
                    $this->subject($tasks->count()),
                    $this->body($user, $tasks),
                ));
            }
        }

        // Mark every reminded task (had at least one assignee) so it isn't retried
        // daily; the in-panel widget still shows it. Unassigned tasks are skipped
        // and re-evaluated next run.
        $reminded = $due->filter(fn (Task $task): bool => $task->assignees->isNotEmpty())->pluck('id');
        if ($reminded->isNotEmpty()) {
            Task::whereKey($reminded)->update(['reminded_at' => now()]);
        }
    }

    private function subject(int $count): string
    {
        return "יש לך {$count} משימות לטיפול היום";
    }

    /**
     * @param  Collection<int, Task>  $tasks
     */
    private function body(User $assignee, $tasks): string
    {
        $lines = ["שלום {$assignee->name},", '', 'המשימות הבאות ממתינות לך (להיום או באיחור):', ''];

        foreach ($tasks as $task) {
            $due = $task->due_at?->format('d/m/Y') ?? '';
            $lines[] = "• {$task->title}".($due !== '' ? " (יעד: {$due})" : '');
        }

        $lines[] = '';
        $lines[] = 'אפשר לצפות ולעדכן אותן במסך "משימות" בפאנל.';

        return implode("\n", $lines);
    }
}
