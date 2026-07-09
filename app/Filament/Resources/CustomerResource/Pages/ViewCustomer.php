<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Customer 360° page — one place to see everything about a customer, with quick
 * actions (edit, send/copy card link) instead of hopping between screens.
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('עריכה'),
        ];
    }
}
