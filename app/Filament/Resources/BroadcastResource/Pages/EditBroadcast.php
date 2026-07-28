<?php

namespace App\Filament\Resources\BroadcastResource\Pages;

use App\Enums\BroadcastStatus;
use App\Filament\Resources\BroadcastResource;
use App\Filament\Resources\BroadcastResource\Actions\BroadcastSendActions;
use App\Models\Broadcast;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;

class EditBroadcast extends EditRecord
{
    protected static string $resource = BroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sendTest')
                ->label('שלח בדיקה אליי')
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->visible(fn (Broadcast $record): bool => BroadcastSendActions::isSendable($record))
                ->action(fn (Broadcast $record) => BroadcastSendActions::sendTest($record)->send()),

            Actions\Action::make('sendNow')
                ->label('שלח עכשיו')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (Broadcast $record): bool => BroadcastSendActions::isSendable($record))
                ->requiresConfirmation()
                ->modalHeading('שליחת דיוור ללקוחות')
                ->modalDescription(fn (Broadcast $record): HtmlString => BroadcastSendActions::confirmation($record))
                ->modalSubmitActionLabel('שלח עכשיו')
                ->action(fn (Broadcast $record) => BroadcastSendActions::send($record)->send()),

            Actions\DeleteAction::make()
                // Deleting mid-send would leave the job writing to a missing row.
                ->visible(fn (Broadcast $record): bool => $record->status !== BroadcastStatus::Sending),
        ];
    }

    /**
     * A draft that gets a future send time becomes scheduled; clearing the time
     * puts it back to a draft. The operator never picks a status by hand — that
     * is how a broadcast could previously be marked "נשלח" without a single
     * customer receiving it.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // A broadcast already going out (or gone) keeps the status the job set —
        // recomputing it here would hand a finished send back to the scheduler.
        if (in_array($this->record->status, [BroadcastStatus::Sending, BroadcastStatus::Sent], true)) {
            unset($data['status']);

            return $data;
        }

        $data['status'] = filled($data['scheduled_at'] ?? null)
            ? BroadcastStatus::Scheduled
            : BroadcastStatus::Draft;

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'הדיוור נשמר';
    }
}
