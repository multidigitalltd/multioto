<?php

namespace App\Filament\Resources\ServiceExceptionResource\Pages;

use App\Filament\Resources\ServiceExceptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageServiceExceptions extends ManageRecords
{
    protected static string $resource = ServiceExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('סימון יום מיוחד'),
        ];
    }
}
