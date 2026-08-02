<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\TaskResource;
use App\Jobs\NotifyTaskCreatedJob;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

    /** @var list<int> who owned this task before this save */
    private array $ownersBefore = [];

    protected function beforeSave(): void
    {
        $this->ownersBefore = $this->record->assignees()->pluck('users.id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Somebody handed the task to somebody else — tell them.
     *
     * Assigning from this form used to be the one route that changed a task's
     * owner in silence: the agent announces its assignments, creation announces
     * itself, and a person picked here found out only if they happened to open
     * the tasks list. Only the newly added owners are told, so an ordinary save
     * (a title, a due date) notifies nobody.
     */
    protected function afterSave(): void
    {
        $added = array_values(array_diff(
            $this->record->assignees()->pluck('users.id')->map(fn ($id): int => (int) $id)->all(),
            $this->ownersBefore,
        ));

        if ($added === []) {
            return;
        }

        // Non-fatal, like every other announcement here: the assignment is
        // already saved by now, and letting a queue hiccup throw would tell the
        // operator the save FAILED. They would then save again — and the second
        // save sees the owner already attached, so nothing is new and the
        // notification is never sent at all, even once the queue is back. The
        // person in front of the screen is told instead, and can say a word.
        try {
            NotifyTaskCreatedJob::dispatch($this->record->id, $added);
        } catch (\Throwable $e) {
            Log::warning('EditTask: assignment notification not queued', [
                'task_id' => $this->record->id,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('השיוך נשמר, אך לא נשלחה התראה')
                ->body('תור העבודות לא זמין כרגע — עדכנו את מי ששויך ידנית.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * Status buttons right inside the task — the same one-click lifecycle a
     * ticket has ("סמן כטופלה"): start it, complete it, or reopen it, without
     * hunting for the status dropdown. Each keeps completed_at in sync and
     * refreshes the form so the page reflects the new status immediately.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('markInProgress')
                ->label('סמן כבביצוע')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === TaskStatus::Open)
                ->action(fn () => $this->changeStatus(TaskStatus::InProgress, 'המשימה סומנה כבביצוע')),

            Actions\Action::make('markDone')
                ->label('סמן כהושלם')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status !== TaskStatus::Done)
                ->requiresConfirmation()
                ->modalHeading('לסמן את המשימה כהושלמה?')
                ->action(fn () => $this->changeStatus(TaskStatus::Done, 'המשימה הושלמה 🎉')),

            Actions\Action::make('reopen')
                ->label('פתח מחדש')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === TaskStatus::Done)
                ->action(fn () => $this->changeStatus(TaskStatus::Open, 'המשימה נפתחה מחדש')),

            Actions\DeleteAction::make()->label('מחיקה'),
        ];
    }

    private function changeStatus(TaskStatus $status, string $message): void
    {
        $this->record->markStatus($status);

        // Sync ONLY the status field in the open form — never fillForm() here,
        // which would reload every field from the DB and silently discard any
        // unsaved edits (title, assignees, due date) the operator made before
        // clicking the button. The header actions re-read $this->record, so
        // their visibility already reflects the new status.
        $this->data['status'] = $status->value;

        Notification::make()->title($message)->success()->send();
    }
}
