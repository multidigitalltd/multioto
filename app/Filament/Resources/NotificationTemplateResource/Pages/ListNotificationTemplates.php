<?php

namespace App\Filament\Resources\NotificationTemplateResource\Pages;

use App\Filament\Resources\NotificationTemplateResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListNotificationTemplates extends ListRecords
{
    protected static string $resource = NotificationTemplateResource::class;

    public function mount(): void
    {
        // Self-heal: make sure the built-in templates exist as editable rows
        // (idempotent; never overwrites operator edits).
        Artisan::call('app:seed-templates');

        parent::mount();
    }
}
