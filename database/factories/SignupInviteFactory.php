<?php

namespace Database\Factories;

use App\Models\SignupInvite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignupInvite>
 */
class SignupInviteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '05'.fake()->numerify('########'),
            'card_exempt' => false,
            'expires_at' => now()->addDays(14),
        ];
    }

    /** A manager waived the card for this one prospect, with a reason. */
    public function cardExempt(string $reason = 'לקוח ותיק בהסכם מיוחד'): static
    {
        return $this->state(fn (): array => [
            'card_exempt' => true,
            'exempt_reason' => $reason,
        ]);
    }
}
