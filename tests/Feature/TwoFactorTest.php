<?php

namespace Tests\Feature;

use App\Enums\TwoFactorChannel;
use App\Filament\Pages\ManageIntegrations;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\UserResource;
use App\Mail\NotificationMail;
use App\Models\User;
use App\Services\Auth\TwoFactorCode;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verified_code_is_consumed_and_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_code' => Hash::make('123456'),
            'two_factor_expires_at' => now()->addMinutes(5),
        ])->save();

        $codes = app(TwoFactorCode::class);

        $this->assertFalse($codes->verify($user, '000000'), 'wrong code rejected');
        $this->assertTrue($codes->verify($user->fresh(), '123456'), 'right code accepted');
        // Consumed on success — the same code can never be used twice.
        $this->assertFalse($codes->verify($user->fresh(), '123456'));
        $this->assertNull($user->fresh()->two_factor_code);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_code' => Hash::make('123456'),
            'two_factor_expires_at' => now()->subMinute(),
        ])->save();

        $this->assertFalse(app(TwoFactorCode::class)->verify($user, '123456'));
    }

    public function test_too_many_wrong_attempts_invalidate_the_code(): void
    {
        config(['twofactor.max_attempts' => 3]);
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_code' => Hash::make('123456'),
            'two_factor_expires_at' => now()->addMinutes(5),
        ])->save();

        $codes = app(TwoFactorCode::class);
        $codes->verify($user->fresh(), 'aaa');
        $codes->verify($user->fresh(), 'bbb');
        $codes->verify($user->fresh(), 'ccc');

        // The correct code no longer works — a fresh one must be requested.
        $this->assertNull($user->fresh()->two_factor_code);
        $this->assertFalse($codes->verify($user->fresh(), '123456'));
    }

    public function test_sending_a_code_emails_it_to_the_member(): void
    {
        Mail::fake();
        $user = User::factory()->withTwoFactor()->create();

        $this->assertTrue(app(TwoFactorCode::class)->send($user));

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m): bool => $m->hasTo($user->email)
            && preg_match('/\d{6}/', $m->bodyText) === 1);
        $this->assertNotNull($user->fresh()->two_factor_code);
        $this->assertNotNull($user->fresh()->two_factor_expires_at);
    }

    public function test_a_whatsapp_code_is_sent_over_waha(): void
    {
        $user = User::factory()->withTwoFactor(TwoFactorChannel::Whatsapp)->create();

        $this->mock(WahaClient::class, function ($mock): void {
            $mock->shouldReceive('normalizeChatId')->once()->andReturn('972501234567@c.us');
            $mock->shouldReceive('sendMessage')->once();
        });

        $this->assertTrue(app(TwoFactorCode::class)->send($user->fresh()));
    }

    public function test_whatsapp_two_factor_without_a_phone_never_locks_the_member_out(): void
    {
        $user = User::factory()->create([
            'two_factor_enabled' => true,
            'two_factor_channel' => TwoFactorChannel::Whatsapp,
            'phone' => null,
        ]);

        // Cannot deliver → the member is simply not challenged.
        $this->assertFalse($user->requiresTwoFactor());
        $this->assertFalse(app(TwoFactorCode::class)->send($user));
    }

    public function test_a_two_factor_member_is_challenged_and_can_confirm(): void
    {
        Mail::fake();
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user);

        // The panel bounces an unconfirmed member to the challenge screen.
        $this->get('/admin')->assertRedirect(route('two-factor.challenge'));

        // Landing on the challenge sends a code; capture it from the email.
        $this->get(route('two-factor.challenge'))->assertOk();

        $code = null;
        Mail::assertSent(NotificationMail::class, function (NotificationMail $m) use ($user, &$code): bool {
            if (! $m->hasTo($user->email)) {
                return false;
            }
            preg_match('/(\d{6})/', $m->bodyText, $matches);
            $code = $matches[1] ?? null;

            return true;
        });
        $this->assertNotNull($code);

        // A wrong code keeps the member out.
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse(session()->get('two_factor.confirmed', false));

        // The right code confirms the session.
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect();
        $this->assertTrue(session()->get('two_factor.confirmed'));

        // Now the challenge just forwards into the panel.
        $this->get(route('two-factor.challenge'))
            ->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_a_member_without_two_factor_reaches_the_panel_directly(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('two-factor.challenge'))
            ->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_admin_only_screens_are_gated_by_role(): void
    {
        $admin = User::factory()->create();
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent);
        $this->assertFalse(ManageIntegrations::canAccess(), 'agent blocked from settings');
        $this->assertFalse(UserResource::canAccess(), 'agent blocked from team management');
        // Day-to-day resources stay open to agents.
        $this->assertTrue(CustomerResource::canAccess());

        $this->actingAs($admin);
        $this->assertTrue(ManageIntegrations::canAccess());
        $this->assertTrue(UserResource::canAccess());
    }
}
