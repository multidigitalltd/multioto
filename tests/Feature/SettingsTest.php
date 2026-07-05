<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
        config(['billing.linet.api_key' => 'env-key']);
        Setting::put('linet.api_key', '');

        (new SettingsServiceProvider($this->app))->boot();

        $this->assertSame('env-key', config('billing.linet.api_key'));
    }
}
