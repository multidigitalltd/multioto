<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageAiAgent;
use App\Filament\Pages\ManageIntegrations;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_queued_jobs_refresh_the_settings_overlay_for_long_lived_workers(): void
    {
        // A setting changed in the panel AFTER a Horizon worker booted.
        Setting::put('linet.doctype', '9');

        // Simulate the worker's in-memory config having drifted stale since boot.
        config(['billing.linet.doctype' => 'STALE']);

        // Processing any job must re-apply the overlay (the provider registers a
        // Queue::before hook at boot), so the invoice job never sends stale codes.
        dispatch(fn () => null);

        $this->assertSame('9', config('billing.linet.doctype'));
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

        // Saving now runs a live connection check — fake Cardcom's endpoint.
        Http::fake(['*/LowProfile/Create' => Http::response(['ResponseCode' => 0, 'Url' => 'https://secure.cardcom.solutions/x'])]);

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

    public function test_ai_agent_page_saves_instructions_without_overwriting_a_blank_key(): void
    {
        Setting::put('ai.api_key', 'existing-key');
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageAiAgent::class)
            ->set('data.ai.enabled', true)
            ->set('data.ai.provider', 'openai')
            ->set('data.ai.persona', 'PERSONA שלי')
            ->set('data.ai.rules', 'כלל אחד')
            ->set('data.ai.api_key', '') // left blank → must not overwrite
            ->call('save');

        $stored = Setting::map();
        $this->assertSame('1', $stored['ai.enabled']);
        $this->assertSame('openai', $stored['ai.provider']);
        $this->assertSame('PERSONA שלי', $stored['ai.persona']);
        $this->assertSame('כלל אחד', $stored['ai.rules']);
        // Blank key field left the previously stored key intact.
        $this->assertSame('existing-key', $stored['ai.api_key']);
    }
}
