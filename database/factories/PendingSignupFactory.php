<?php

namespace Database\Factories;

use App\Enums\BusinessType;
use App\Models\PendingSignup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingSignup>
 */
class PendingSignupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact_name' => fake()->name(),
            'business_number' => (string) fake()->numerify('#########'),
            'business_type' => BusinessType::LicensedDealer->value,
            'vat_exempt' => false,
            'email' => fake()->unique()->safeEmail(),
            'phone' => '05'.fake()->numerify('########'),
            'domain' => null,
            'payment_method' => 'credit_card',
            'terms_accepted_at' => now(),
            'security_card_terms_at' => now(),
            'signed_ip' => '127.0.0.1',
        ];
    }
}
