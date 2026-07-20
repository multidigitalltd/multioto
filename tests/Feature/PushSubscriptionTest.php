<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Storing/removing a team member's browser push subscription — team-only and
 * scoped to the signed-in user.
 */
class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_member_can_store_a_subscription(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/push-subscriptions', [
                'endpoint' => 'https://push.example.com/ep-1',
                'keys' => ['p256dh' => 'the-public-key', 'auth' => 'the-auth-token'],
                'contentEncoding' => 'aes128gcm',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_type' => $user->getMorphClass(),
            'subscribable_id' => $user->id,
            'endpoint' => 'https://push.example.com/ep-1',
            'public_key' => 'the-public-key',
            'auth_token' => 'the-auth-token',
        ]);
    }

    public function test_a_member_can_remove_a_subscription(): void
    {
        $user = User::factory()->create();
        $user->updatePushSubscription('https://push.example.com/ep-2', 'k', 'a');

        $this->actingAs($user)
            ->deleteJson('/push-subscriptions', ['endpoint' => 'https://push.example.com/ep-2'])
            ->assertOk();

        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint' => 'https://push.example.com/ep-2',
        ]);
    }

    public function test_a_guest_cannot_store_a_subscription(): void
    {
        $this->postJson('/push-subscriptions', [
            'endpoint' => 'https://push.example.com/ep-3',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertUnauthorized();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_the_endpoint_is_required(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/push-subscriptions', ['keys' => ['p256dh' => 'k', 'auth' => 'a']])
            ->assertUnprocessable();
    }

    public function test_a_member_who_has_not_cleared_2fa_cannot_store_a_subscription(): void
    {
        // Password authenticated, but the one-time code is not yet confirmed:
        // must not be able to register a push endpoint (and start receiving
        // team-notification content) before the second factor.
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)
            ->post('/push-subscriptions', [
                'endpoint' => 'https://push.example.com/attacker',
                'keys' => ['p256dh' => 'k', 'auth' => 'a'],
            ])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertDatabaseCount('push_subscriptions', 0);
    }
}
