<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'אחזקת אתר '.fake()->unique()->word(),
            'price_agorot' => fake()->randomElement([9900, 14900, 24900, 39900]),
            'vat_applies' => true,
            'billing_interval' => BillingInterval::Monthly,
            'active' => true,
        ];
    }
}
