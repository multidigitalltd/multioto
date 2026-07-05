<?php

namespace Database\Factories;

use App\Enums\SiteStatus;
use App\Models\Customer;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'domain' => fake()->unique()->domainName(),
            'monitor_enabled' => true,
            'status' => SiteStatus::Active,
        ];
    }
}
