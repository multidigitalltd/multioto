<?php

namespace App\Filament\Resources\SiteInstallationResource\Pages;

use App\Filament\Resources\SiteInstallationResource;
use Filament\Resources\Pages\ListRecords;

class ListSiteInstallations extends ListRecords
{
    protected static string $resource = SiteInstallationResource::class;

    /**
     * No "create" button. A row here is created by a customer asking for an
     * install; one opened by hand would be an install nobody bought and no
     * customer is waiting for.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
