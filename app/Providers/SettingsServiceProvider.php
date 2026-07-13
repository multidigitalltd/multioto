<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Queue;
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
        'linet.general_item_id' => 'billing.linet.general_item_id',
        'linet.income_account_exempt' => 'billing.linet.income_account_exempt',
        'flywp.api_token' => 'billing.hosting.flywp.api_token',
        'flywp.server_id' => 'billing.hosting.flywp.server_id',
        'waha.api_key' => 'billing.waha.api_key',
        'waha.base_url' => 'billing.waha.base_url',
        'waha.session' => 'billing.waha.session',
        'waha.owner_number' => 'billing.waha.owner_number',
        'notifications.team_email' => 'billing.notifications.team_email',
        'email.webhook_secret' => 'billing.email.webhook_secret',
        'notifications.reply_signature' => 'billing.notifications.reply_signature',
        'notifications.reply_signature_whatsapp' => 'billing.notifications.reply_signature_whatsapp',
        // Auto-generated when the operator enables inbound listening — not a
        // form field (see ManageIntegrations::setupWahaInbound).
        'waha.webhook_secret' => 'billing.waha.webhook_secret',
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
        // Public signup form — payment-method setup instructions (editable text).
        'signup.instructions.standing_order' => 'billing.signup.instructions.standing_order',
        'signup.instructions.bank_transfer' => 'billing.signup.instructions.bank_transfer',
        'signup.instructions.checks' => 'billing.signup.instructions.checks',
        'signup.tax_approval_notice' => 'billing.signup.tax_approval_notice',
        // Business logo (public-disk path) shown across customer-facing surfaces.
        'branding.logo_path' => 'billing.branding.logo_path',
        'branding.email_footer' => 'billing.branding.email_footer',
    ];

    public function boot(): void
    {
        $this->applyOverlay();

        // Horizon queue workers are long-lived: boot() runs once at startup, so
        // a credential/code changed in the panel afterwards would never reach a
        // running worker (the invoice job would keep sending the old value —
        // e.g. an empty doctype → Linet "invalid document type"). Re-apply the
        // overlay before every job so workers always use the current settings.
        Queue::before(fn () => $this->applyOverlay());
    }

    /**
     * Overlay stored settings onto config. A non-empty stored value wins over
     * .env; blanks fall back to .env. Tolerates a not-yet-migrated database.
     */
    protected function applyOverlay(): void
    {
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
