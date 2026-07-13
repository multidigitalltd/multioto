<?php

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\Customer;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'channel' => fake()->randomElement(['email', 'whatsapp']),
            'type' => fake()->randomElement(NotificationType::cases()),
            'recipient' => fake()->email(),
            'subject' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'status' => 'sent',
            'error' => null,
            'sent_at' => now(),
        ];
    }
}
