<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageIntegrations;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_are_stored_encrypted_at_rest(): void
    {
        Setting::put('cardcom.api_password', 'super-secret');

        $raw = DB::table('settings')->where('key', 'cardcom.api_password')->value('value');

        $this->assertNotSame('super-secret', $raw);
        $this->assertSame('super-secret', Crypt::decryptString($raw));
        $this->assertSame('super-secret', Setting::map()['cardcom.api_password']);
    }

    public function test_stored_setting_overrides_env_config_at_boot(): void
    {
        config(['billing.cardcom.api_password' => 'from-env']);
        Setting::put('cardcom.api_password', 'from-ui');

        // Re-run the overlay as it would at boot.
        (new SettingsServiceProvider($this->app))->boot();

        $this->assertSame('from-ui', config('billing.cardcom.api_password'));
    }

    public function test_blank_setting_leaves_env_config_intact(): void
    {
        config(['billing.linet.login_id' => 'env-login']);
        Setting::put('linet.login_id', '');

        (new SettingsServiceProvider($this->app))->boot();

        $this->assertSame('env-login', config('billing.linet.login_id'));
    }

    public function test_saving_one_integration_group_does_not_touch_other_groups(): void
    {
        Setting::put('waha.api_key', 'existing-waha');

        $this->actingAs(User::factory()->create());

        Livewire::test(ManageIntegrations::class)
            ->set('data.cardcom.terminal_number', '1000')
            ->set('data.cardcom.api_name', 'api-user')
            ->set('data.waha.api_key', 'typed-but-not-saved')
            ->call('saveGroup', 'cardcom');

        $stored = Setting::map();

        // The cardcom group was persisted…
        $this->assertSame('1000', $stored['cardcom.terminal_number']);
        $this->assertSame('api-user', $stored['cardcom.api_name']);
        // …and the waha group was left untouched even though a value was typed.
        $this->assertSame('existing-waha', $stored['waha.api_key']);
    }

    public function test_save_group_ignores_unknown_groups_and_blank_fields(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageIntegrations::class)
            ->call('saveGroup', 'not-a-real-group')
            ->set('data.linet.login_id', '')
            ->call('saveGroup', 'linet');

        $this->assertArrayNotHasKey('linet.login_id', Setting::map());
    }
}
