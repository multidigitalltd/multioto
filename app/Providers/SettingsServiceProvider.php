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
        'linet.login_id' => 'billing.linet.login_id',
        'linet.key' => 'billing.linet.key',
        'linet.company_id' => 'billing.linet.company_id',
        'linet.doctype' => 'billing.linet.doctype',
        'linet.vat_cat_taxable' => 'billing.linet.vat_cat_taxable',
        'linet.vat_cat_exempt' => 'billing.linet.vat_cat_exempt',
        'linet.payment_type' => 'billing.linet.payment_type',
        'flywp.api_token' => 'billing.hosting.flywp.api_token',
        'flywp.server_id' => 'billing.hosting.flywp.server_id',
        'waha.api_key' => 'billing.waha.api_key',
        'waha.base_url' => 'billing.waha.base_url',
        'waha.session' => 'billing.waha.session',
        'postmark.token' => 'services.postmark.token',
        'postmark.account_token' => 'services.postmark.account_token',
        // Outbound mail identity — editable from the מייל settings screen so the
        // sender address/name can be changed without touching .env.
        'mail.mailer' => 'mail.default',
        'mail.from_address' => 'mail.from.address',
        'mail.from_name' => 'mail.from.name',
        'mail.reply_to' => 'billing.email.support_address',
        'ai.enabled' => 'billing.ai.enabled',
        'ai.provider' => 'billing.ai.provider',
        'ai.api_key' => 'billing.ai.api_key',
        'ai.base_url' => 'billing.ai.base_url',
        'ai.model' => 'billing.ai.model',
        'ai.persona' => 'billing.ai.persona',
        'ai.rules' => 'billing.ai.rules',
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
