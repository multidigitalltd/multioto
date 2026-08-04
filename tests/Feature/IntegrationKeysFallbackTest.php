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

    /**
     * מפתחות ההתחברות עם גוגל נשמרים גם דרך הטופס הזה.
     *
     * זו הנקודה של הטופס כולו: כשה-JavaScript של הדף לא נטען, זה המסלול
     * שעובד. הגדרה שאפשר לשמור רק דרך המסלול השבור אינה הגדרה.
     */
    public function test_the_google_sign_in_keys_can_be_saved_without_javascript(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post(route('integrations.security-keys.fallback'), [
            'google_client_id' => '  1234.apps.googleusercontent.com  ',
            'google_client_secret' => 'GOCSPX-secret',
            'google_allowed_domain' => 'multi-digital.co.il',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('integration_status', fn ($s): bool => $s['variant'] === 'success');

        $stored = Setting::map();

        $this->assertSame('1234.apps.googleusercontent.com', $stored['google.client_id'] ?? null);
        $this->assertSame('GOCSPX-secret', $stored['google.client_secret'] ?? null);
        $this->assertSame('multi-digital.co.il', $stored['google.allowed_domain'] ?? null);
    }

    /**
     * ביטול הגבלת הדומיין נאמר במפורש, כי שדה ריק אינו יכול לומר אותו.
     *
     * בכל שאר הטופס ריק פירושו "אל תיגע", ולכן להסרת ההגבלה יש תיבת סימון.
     */
    public function test_the_domain_fence_can_be_lifted_deliberately(): void
    {
        Setting::put('google.allowed_domain', 'multi-digital.co.il');
        $this->actingAs(User::factory()->create());

        $this->post(route('integrations.security-keys.fallback'), [
            'clear_google_allowed_domain' => '1',
        ])->assertRedirect();

        $this->assertSame('', Setting::map()['google.allowed_domain'] ?? null);
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
