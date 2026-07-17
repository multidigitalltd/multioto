<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\TaskResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTask extends EditRecord
{
    protected static string $resource = TaskResource::class;

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
