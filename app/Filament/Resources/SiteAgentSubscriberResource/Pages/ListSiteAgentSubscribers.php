<?php

namespace App\Filament\Resources\SiteAgentSubscriberResource\Pages;

use App\Filament\Resources\SiteAgentSubscriberResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSiteAgentSubscribers extends ListRecords
{
    protected static string $resource = SiteAgentSubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('חיבור מספר לאתר')];
    }
}
