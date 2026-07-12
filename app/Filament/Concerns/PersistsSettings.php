<?php

namespace App\Filament\Concerns;

use App\Providers\SettingsServiceProvider;

/**
 * Shared by the settings pages (mail / integrations / AI agent): re-apply the
 * stored settings onto the live config within the current request, so a value
 * just saved — or one about to be used by a connection test — is read back
 * immediately. The overlay otherwise runs only once, at boot.
 */
trait PersistsSettings
{
    protected function refreshConfig(): void
    {
        (new SettingsServiceProvider(app()))->boot();
    }
}
