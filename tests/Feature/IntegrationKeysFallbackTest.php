<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Health\ConnectionResult;
use App\Services\Health\IntegrationHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The classic (non-Livewire) fallback form for saving the security API keys —
 * the dependable path when the Livewire save button is broken client-side.
 */
class IntegrationKeysFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_save_keys_through_the_fallback_form(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('integrations.security-keys.fallback'), [
            'urlhaus_auth_key' => '  abuse-key-77  ',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('integration_status', fn ($s) => $s['variant'] === 'success'
            && str_contains($s['text'], 'abuse.ch Auth-Key: עודכן עכשיו ✓'));

        // Trimmed and stored; untouched fields stay untouched.
        $this->assertSame('abuse-key-77', Setting::map()['security.urlhaus_auth_key'] ?? null);
        $this->assertArrayNotHasKey('security.safe_browsing_key', Setting::map());
    }

    public function test_the_fallback_rejects_the_panel_password_as_autofill(): void
    {
        $this->actingAs(User::factory()->create(['password' => bcrypt('Panel-Pass-9!')]));

        $response = $this->post(route('integrations.security-keys.fallback'), [
            'safe_browsing_key' => 'Panel-Pass-9!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('integration_status', fn ($s) => $s['variant'] === 'danger');
        $this->assertArrayNotHasKey('security.safe_browsing_key', Setting::map());
    }

    public function test_a_non_admin_cannot_use_the_fallback(): void
    {
        $this->actingAs(User::factory()->agent()->create());

        $this->post(route('integrations.security-keys.fallback'), [
            'urlhaus_auth_key' => 'abuse-key-77',
        ])->assertForbidden();

        $this->assertArrayNotHasKey('security.urlhaus_auth_key', Setting::map());
    }

    public function test_the_fallback_connection_test_flashes_the_per_source_result(): void
    {
        $this->actingAs(User::factory()->create());

        $health = \Mockery::mock(IntegrationHealth::class);
        $health->shouldReceive('check')->with('security')
            ->andReturn(ConnectionResult::fail('URLhaus: ה-Auth-Key נדחה (403) — העתיקו מחדש את המפתח המלא מ-auth.abuse.ch ושמרו שוב'));
        $this->app->instance(IntegrationHealth::class, $health);

        $response = $this->post(route('integrations.security-keys.test'));

        $response->assertRedirect();
        $response->assertSessionHas('integration_status', fn ($s) => $s['variant'] === 'danger'
            && str_contains($s['text'], 'Auth-Key נדחה (403)'));
    }

    public function test_the_fallback_connection_test_is_admin_only(): void
    {
        $this->actingAs(User::factory()->agent()->create());

        $this->post(route('integrations.security-keys.test'))->assertForbidden();
    }

    public function test_an_empty_submit_changes_nothing_and_says_so(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put('security.urlhaus_auth_key', 'existing-key');

        $response = $this->post(route('integrations.security-keys.fallback'), []);

        $response->assertRedirect();
        $response->assertSessionHas('integration_status', fn ($s) => $s['variant'] === 'warning');
        $this->assertSame('existing-key', Setting::map()['security.urlhaus_auth_key'] ?? null);
    }
}
