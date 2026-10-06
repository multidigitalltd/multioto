<?php

namespace App\Filament\Resources\SiteAgentMessageResource\Pages;

use App\Filament\Resources\SiteAgentMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListSiteAgentMessages extends ListRecords
{
    protected static string $resource = SiteAgentMessageResource::class;

    /** Nothing is written here — the transcript is the bot's own. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
