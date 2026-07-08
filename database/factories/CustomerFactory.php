<?php

namespace Database\Factories;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'business_number' => (string) fake()->numberBetween(510000000, 599999999),
            'business_type' => BusinessType::LicensedDealer,
            'vat_exempt' => false,
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+9725'.fake()->unique()->numerify('########'),
            'status' => CustomerStatus::Active,
        ];
    }

    public function vatExempt(): static
    {
        return $this->state([
            'business_type' => BusinessType::ExemptDealer,
            'vat_exempt' => true,
        ]);
    }
}
