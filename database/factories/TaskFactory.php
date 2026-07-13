<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'assigned_to' => null,
            'customer_id' => null,
            'ticket_id' => null,
            'status' => TaskStatus::Open,
            'priority' => TicketPriority::Normal,
            'due_at' => null,
            'completed_at' => null,
            'reminded_at' => null,
        ];
    }
}
