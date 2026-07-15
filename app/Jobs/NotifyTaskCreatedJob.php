<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Mail\NotificationMail;
use App\Models\Task;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Announce a newly created task the moment it lands: the assignees get told they
 * were handed a task; a task created with NO assignee goes to the managers
 * (admins) so it isn't missed. Each recipient gets an in-panel bell notification
 * AND an email — so it reaches them whether or not they're in the panel.
 *
 * Dispatched after the assignees are attached (Filament afterCreate / the
 * ticket→task action), so the recipient list is already correct.
 */
class NotifyTaskCreatedJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $taskId) {}

    public function handle(): void
    {
        $task = Task::with('assignees', 'customer')->find($this->taskId);

        if (! $task) {
            return;
        }

        $assignees = $task->assignees;
        $unassigned = $assignees->isEmpty();

        // Assigned → the assignees. Unassigned → the managers, so a stray task
        // still reaches someone who can pick it up.
        $recipients = $unassigned
            ? User::where('role', UserRole::Admin)->get()
            : $assignees;

        if ($recipients->isEmpty()) {
            return;
        }

        $title = $unassigned ? 'משימה חדשה ללא שיוך' : 'משימה חדשה שויכה אליך';
        $body = $this->body($task, $unassigned);
        $url = rtrim((string) config('app.url'), '/')."/admin/tasks/{$task->id}/edit";

        $this->notifyInPanel($recipients, $title, $body, $url);
        $this->notifyByEmail($recipients, $title, $body, $url);
    }

    /** @param  Collection<int, User>  $recipients */
    private function notifyInPanel(Collection $recipients, string $title, string $body, string $url): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->icon('heroicon-o-clipboard-document-check')
            ->actions([Action::make('view')->label('פתח משימה')->url($url)])
            ->sendToDatabase($recipients);
    }

    /** @param  Collection<int, User>  $recipients */
    private function notifyByEmail(Collection $recipients, string $title, string $body, string $url): void
    {
        $emails = $recipients->pluck('email')->filter()->unique()->all();

        if ($emails === []) {
            return;
        }

        Mail::to($emails)->send(new NotificationMail($title, $body."\n\nלצפייה: {$url}"));
    }

    private function body(Task $task, bool $unassigned): string
    {
        $lines = ["משימה: {$task->title}"];

        if ($task->customer) {
            $lines[] = "לקוח: {$task->customer->name}";
        }
        if ($task->due_at) {
            $lines[] = 'מועד יעד: '.$task->due_at->format('d/m/Y');
        }
        if ($unassigned) {
            $lines[] = 'המשימה נוצרה ללא שיוך — שייכו אותה למי שיטפל.';
        }

        return implode("\n", $lines);
    }
}
