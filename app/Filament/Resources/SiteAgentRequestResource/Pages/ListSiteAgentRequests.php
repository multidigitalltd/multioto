<?php

namespace App\Filament\Resources\SiteAgentRequestResource\Pages;

use App\Filament\Resources\SiteAgentRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListSiteAgentRequests extends ListRecords
{
    protected static string $resource = SiteAgentRequestResource::class;

    /** Nothing is created here — the journal is written by the agent itself. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
