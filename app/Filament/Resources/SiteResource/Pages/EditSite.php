<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // One-click jump from the settings "card" to the connection/monitor
            // tools hub (codes, live test, plugin download, token, Cloudflare),
            // which lives on the site's view page — so the tools are reachable
            // from here without duplicating every action into the edit screen.
            Actions\Action::make('tools')
                ->label('כלי חיבור וניטור')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('gray')
                ->url(fn (): string => SiteResource::getUrl('view', ['record' => $this->record])),
            Actions\DeleteAction::make(),
        ];
    }
}
