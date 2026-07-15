<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageIntegrations;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ManageIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_linet_persists_every_field(): void
    {
        $this->actingAs(User::factory()->create());
        // The save runs the Linet connection check — keep it off the network.
        Http::fake(['*/search/account' => Http::response(['status' => 200, 'body' => []])]);

        Livewire::test(ManageIntegrations::class)
            ->fillForm([
                'linet.login_id' => 'LID',
                'linet.key' => 'KEY',
                'linet.company_id' => '3',
                'linet.doctype' => '9',
                'linet.doctype_proforma' => '30',
                'linet.vat_cat_taxable' => '100',
                'linet.vat_cat_exempt' => '102',
                'linet.payment_type' => '3',
            ])
            ->call('saveGroup', 'linet');

        $m = Setting::map();
        $this->assertSame('LID', $m['linet.login_id'] ?? null);
        $this->assertSame('KEY', $m['linet.key'] ?? null);
        $this->assertSame('3', $m['linet.company_id'] ?? null);
        $this->assertSame('9', $m['linet.doctype'] ?? null);
        $this->assertSame('30', $m['linet.doctype_proforma'] ?? null);
        $this->assertSame('100', $m['linet.vat_cat_taxable'] ?? null);

        // The saved proforma code overlays onto config for the issuer to use.
        (new SettingsServiceProvider(app()))->boot();
        $this->assertSame('30', config('billing.linet.doctype_proforma'));
    }

    public function test_clearing_an_optional_linet_payment_code_reverts_to_the_default(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['*/search/account' => Http::response(['status' => 200, 'body' => []])]);

        // A custom bank-transfer code is saved…
        Setting::put('linet.payment_type_bank_transfer', '7');
        (new SettingsServiceProvider(app()))->boot();
        $this->assertSame('7', config('billing.linet.payment_type_bank_transfer'));

        // …then blanked in the form. The override must be removed (not kept).
        Livewire::test(ManageIntegrations::class)
            ->fillForm(['linet.payment_type_bank_transfer' => ''])
            ->call('saveGroup', 'linet');

        $this->assertArrayNotHasKey('linet.payment_type_bank_transfer', Setting::map());

        // And a worker re-applying the overlay sees the default (null), not '7'.
        (new SettingsServiceProvider(app()))->boot();
        $this->assertNull(config('billing.linet.payment_type_bank_transfer'));
    }

    public function test_non_secret_settings_are_shown_on_load_but_secrets_are_hidden(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put('linet.doctype', '9');
        Setting::put('linet.key', 'super-secret');
        (new SettingsServiceProvider(app()))->boot();

        Livewire::test(ManageIntegrations::class)
            ->assertSet('data.linet.doctype', '9')   // non-secret: visible
            ->assertSet('data.linet.key', null);     // secret: never echoed
    }

    public function test_testing_a_connection_sets_the_inline_status_banner(): void
    {
        $this->actingAs(User::factory()->create());
        config([
            'billing.linet.base_url' => 'https://app.linet.test/api',
            'billing.linet.login_id' => 'lid',
            'billing.linet.key' => 'lhash',
            'billing.linet.company_id' => '3',
        ]);
        Http::fake(['*/search/account' => Http::response(['status' => 200, 'body' => []])]);

        $component = Livewire::test(ManageIntegrations::class)->call('testGroup', 'linet');

        // Inline banner is populated regardless of whether the toast renders.
        $this->assertStringContainsString('תקין', (string) $component->get('statusText'));
        $this->assertSame('success', $component->get('statusVariant'));
    }

    public function test_credentials_are_trimmed_so_a_pasted_space_cannot_reject_auth(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['*/search/account' => Http::response(['status' => 200, 'body' => []])]);

        Livewire::test(ManageIntegrations::class)
            ->fillForm([
                'linet.login_id' => '  G9TF8SbjbbAMfl6ITLEohIAsEJiRQUrc  ',
                'linet.key' => "34sA3Ru8uLrkEIsgkUdlMYjGm0FX-7HY\n",
                'linet.company_id' => '3',
            ])
            ->call('saveGroup', 'linet');

        $m = Setting::map();
        $this->assertSame('G9TF8SbjbbAMfl6ITLEohIAsEJiRQUrc', $m['linet.login_id'] ?? null);
        $this->assertSame('34sA3Ru8uLrkEIsgkUdlMYjGm0FX-7HY', $m['linet.key'] ?? null);
    }
}
