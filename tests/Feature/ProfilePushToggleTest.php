<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The profile screen shows a browser-notifications on/off section — but only
 * when Web Push is configured (VAPID keys).
 */
class ProfilePushToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profile_shows_the_push_toggle_when_configured(): void
    {
        config(['webpush.vapid.public_key' => 'pub', 'webpush.vapid.private_key' => 'priv']);

        $this->actingAs(User::factory()->create())
            ->get(route('filament.admin.auth.profile'))
            ->assertOk()
            ->assertSee('התראות דפדפן');
    }

    public function test_the_profile_hides_the_push_toggle_when_not_configured(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('filament.admin.auth.profile'))
            ->assertOk()
            ->assertDontSee('התראות דפדפן');
    }
}
