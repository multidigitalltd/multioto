<?php

namespace App\Jobs;

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
    use Queueable;

    public function handle(): void
    {
        if (! (bool) config('billing.support.task_reminders.enabled', true)) {
            return;
        }

        Task::query()
            ->due()
            ->whereNull('reminded_at')
            ->whereNotNull('assigned_to')
            ->with('assignee')
            ->get()
            ->groupBy('assigned_to')
            ->each(function ($tasks): void {
                $assignee = $tasks->first()->assignee;

                if ($assignee instanceof User && filled($assignee->email)) {
                    Mail::to($assignee->email)->send(new NotificationMail(
                        $this->subject($tasks->count()),
                        $this->body($assignee, $tasks),
                    ));
                }

                // Mark reminded regardless of email presence, so a member without
                // an address isn't retried every day (the in-panel widget still
                // shows the task).
                Task::whereKey($tasks->pluck('id'))->update(['reminded_at' => now()]);
            });
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
