<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Overlays admin-panel settings onto config at boot, so the thin API clients
 * keep reading plain config() while credentials can be managed from the UI.
 * A non-empty stored value wins over .env; anything blank falls back to .env.
 */
class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Setting key => config path. This is the allow-list of what the settings
     * page may override — nothing else in config is touchable from the panel.
     */
    public const MAP = [
        'cardcom.terminal_number' => 'billing.cardcom.terminal_number',
        'cardcom.api_name' => 'billing.cardcom.api_name',
        'cardcom.api_password' => 'billing.cardcom.api_password',
        'linet.api_key' => 'billing.linet.api_key',
        'linet.api_secret' => 'billing.linet.api_secret',
        'flywp.api_token' => 'billing.hosting.flywp.api_token',
        'flywp.server_id' => 'billing.hosting.flywp.server_id',
        'waha.api_key' => 'billing.waha.api_key',
    ];

    public function boot(): void
    {
        // Tolerate a not-yet-migrated database (fresh install, migrate step).
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $stored = Setting::map();
        } catch (Throwable) {
            return;
        }

        foreach (self::MAP as $settingKey => $configPath) {
            if (filled($stored[$settingKey] ?? null)) {
                config([$configPath => $stored[$settingKey]]);
            }
        }
    }
}
