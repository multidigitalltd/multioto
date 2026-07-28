<?php

namespace App\Filament\Resources\BroadcastResource\Pages;

use App\Filament\Resources\BroadcastResource;
use App\Filament\Resources\BroadcastResource\Concerns\DerivesBroadcastStatus;
use Filament\Resources\Pages\CreateRecord;

class CreateBroadcast extends CreateRecord
{
    use DerivesBroadcastStatus;

    protected static string $resource = BroadcastResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->deriveBroadcastStatus($data);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'הדיוור נוצר';
    }
}
