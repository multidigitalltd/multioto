<?php

namespace App\Filament\Resources\SiteAgentSubscriberResource\Pages;

use App\Filament\Resources\SiteAgentSubscriberResource;
use Filament\Resources\Pages\EditRecord;

class EditSiteAgentSubscriber extends EditRecord
{
    protected static string $resource = SiteAgentSubscriberResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
