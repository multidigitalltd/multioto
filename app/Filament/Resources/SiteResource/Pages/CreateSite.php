<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    /**
     * Land on the new site's page after creating it — that's the tools hub where
     * "קודי חיבור לתוסף" (and the rest of the connection tools) live, which can
     * only exist once the site row is saved.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
