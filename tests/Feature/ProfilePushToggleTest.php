<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The profile screen's browser-notifications section.
 *
 * It used to disappear entirely when Web Push was unconfigured, which is how
 * somebody waiting for notifications that never came found nothing on screen to
 * explain why. The section now always appears: with the toggle when the keys
 * exist, and with the one-time server command when they do not.
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

    /**
     * Unconfigured: the section stays, and says so. Hiding it answered the
     * question "why am I not getting notifications?" with an empty space.
     */
    public function test_the_profile_explains_instead_of_hiding_when_not_configured(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('filament.admin.auth.profile'))
            ->assertOk()
            ->assertSee('התראות דפדפן')
            ->assertSee('אינן מופעלות בשרת', false);
    }
}
