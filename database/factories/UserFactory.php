<?php

namespace Database\Factories;

use App\Enums\TwoFactorChannel;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Admin,
        ];
    }

    /** A day-to-day agent without admin (settings/team-management) access. */
    public function agent(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::Agent]);
    }

    /** A member who must confirm a one-time code after their password. */
    public function withTwoFactor(TwoFactorChannel $channel = TwoFactorChannel::Email): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_enabled' => true,
            'two_factor_channel' => $channel,
            'phone' => $channel === TwoFactorChannel::Whatsapp ? '0501234567' : ($attributes['phone'] ?? null),
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
