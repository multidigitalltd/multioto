<?php

namespace App\Filament\Resources\PluginProductResource\Pages;

use App\Filament\Resources\PluginProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPluginProducts extends ListRecords
{
    protected static string $resource = PluginProductResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('תוסף חדש')];
    }
}
