<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Concerns\InteractsWithCustomerCards;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    use InteractsWithCustomerCards;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        // Add a new card straight from the edit screen — the same secure
        // Cardcom card-capture flow as the customer 360° page, so the team
        // doesn't have to leave editing to open the customer view first.
        return [
            $this->cardLinkAction(),
            $this->syncCardAction(),
            Actions\DeleteAction::make(),
        ];
    }
}
