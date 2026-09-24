<?php

namespace App\Filament\Resources\SiteInstallationResource\Pages;

use App\Filament\Resources\SiteInstallationResource;
use Filament\Resources\Pages\EditRecord;

class EditSiteInstallation extends EditRecord
{
    protected static string $resource = SiteInstallationResource::class;

    /**
     * Closing the request from here clears the credential too.
     *
     * The "הותקן" button on the table does it, and a state changed on this form
     * instead has to end the same way — otherwise the tidier route through the
     * screen is the one that leaves a customer's admin access behind.
     */
    protected function afterSave(): void
    {
        if ($this->record->isClosed()) {
            $this->record->clearAccess();
        }
    }
}
