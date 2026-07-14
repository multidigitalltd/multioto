<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use App\Mail\NotificationMail;
use App\Models\Task;
use App\Support\EmailList;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('משימה חדשה'),

            // Open a print-friendly page of all open tasks (auto-opens print).
            Actions\Action::make('printOpen')
                ->label('הדפסת משימות פתוחות')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(route('tasks.print'), shouldOpenInNewTab: true),

            // Email the same open-task list to a recipient (defaults to the team
            // address, or the current user when none is configured).
            Actions\Action::make('emailOpen')
                ->label('שליחת משימות למייל')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->form([
                    Forms\Components\TextInput::make('email')
                        ->label('לכתובת')
                        ->email()
                        ->required()
                        ->default(fn (): ?string => EmailList::parse(config('billing.notifications.team_email'))[0] ?? auth()->user()?->email),
                ])
                ->action(function (array $data): void {
                    $tasks = Task::openForReport();

                    if ($tasks->isEmpty()) {
                        Notification::make()->title('אין משימות פתוחות לשליחה')->warning()->send();

                        return;
                    }

                    Mail::to($data['email'])->send(new NotificationMail(
                        'משימות פתוחות — '.now()->format('d/m/Y'),
                        $this->tasksBody($tasks),
                    ));

                    Notification::make()->title('רשימת המשימות נשלחה ל'.$data['email'])->success()->send();
                }),
        ];
    }

    /**
     * A plain-text list of open tasks for the email body — one line per task
     * with the details the team needs to triage without opening the panel.
     *
     * @param  Collection<int, Task>  $tasks
     */
    private function tasksBody(Collection $tasks): string
    {
        $lines = $tasks->map(function (Task $task): string {
            $parts = array_filter([
                'עדיפות: '.($task->priority?->getLabel() ?? '—'),
                'אחראי: '.($task->assignee?->name ?? 'ללא'),
                filled($task->customer?->name) ? 'לקוח: '.$task->customer->name : null,
                'יעד: '.($task->due_at?->format('d/m/Y') ?? '—').($task->due_at && $task->due_at->isPast() ? ' (באיחור)' : ''),
            ]);

            return '• '.$task->title."\n   ".implode(' · ', $parts);
        });

        return 'רשימת המשימות הפתוחות ('.$tasks->count()."):\n\n".$lines->implode("\n\n");
    }
}
