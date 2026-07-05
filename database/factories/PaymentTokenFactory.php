<?php

namespace Database\Factories;

use App\Enums\TokenStatus;
use App\Models\Customer;
use App\Models\PaymentToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentToken>
 */
class PaymentTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'cardcom_token' => fake()->uuid(),
            'card_last4' => fake()->numerify('####'),
            'card_brand' => fake()->randomElement(['Visa', 'Mastercard', 'Isracard']),
            'expiry_month' => fake()->numberBetween(1, 12),
            'expiry_year' => (int) now()->addYears(2)->format('Y'),
            'status' => TokenStatus::Active,
        ];
    }
}
