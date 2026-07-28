<?php

namespace App\Filament\Resources\BroadcastResource\Pages;

use App\Enums\BroadcastStatus;
use App\Filament\Resources\BroadcastResource;
use App\Filament\Resources\BroadcastResource\Actions\BroadcastSendActions;
use App\Filament\Resources\BroadcastResource\Concerns\DerivesBroadcastStatus;
use App\Models\Broadcast;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

class EditBroadcast extends EditRecord
{
    use DerivesBroadcastStatus;

    protected static string $resource = BroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sendTest')
                ->label('שלח בדיקה אליי')
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->visible(fn (Broadcast $record): bool => BroadcastSendActions::isSendable($record))
                ->mountUsing(fn () => $this->persistPendingEdits())
                ->action(fn (Broadcast $record) => BroadcastSendActions::sendTest($record->refresh())->send()),

            Actions\Action::make('sendNow')
                ->label('שלח עכשיו')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (Broadcast $record): bool => BroadcastSendActions::isSendable($record))
                // Persist BEFORE the modal renders, so the recipient count the
                // operator approves reflects what is on screen — a header action
                // otherwise reads the last-saved row, and an edited audience or
                // body would be confirmed against stale numbers and then sent.
                ->mountUsing(fn () => $this->persistPendingEdits())
                ->requiresConfirmation()
                ->modalHeading('שליחת דיוור ללקוחות')
                ->modalDescription(fn (Broadcast $record): HtmlString => BroadcastSendActions::confirmation($record->refresh()))
                ->modalSubmitActionLabel('שלח עכשיו')
                ->action(fn (Broadcast $record) => BroadcastSendActions::send($record->refresh())->send()),

            Actions\DeleteAction::make()
                // Deleting mid-send would leave the job writing to a missing row.
                ->visible(fn (Broadcast $record): bool => $record->status !== BroadcastStatus::Sending),
        ];
    }

    /**
     * Flush whatever is currently in the form to the row, so the send actions
     * act on what the operator sees rather than on the last save. Validation
     * failures surface as usual and abort the action.
     */
    protected function persistPendingEdits(): void
    {
        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // A broadcast already going out (or gone) keeps the status the job set —
        // recomputing it here would hand a finished send back to the scheduler.
        if (in_array($this->record->status, [BroadcastStatus::Sending, BroadcastStatus::Sent], true)) {
            unset($data['status']);

            return $data;
        }

        return $this->deriveBroadcastStatus($data);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'הדיוור נשמר';
    }
}
