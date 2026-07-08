<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'plan_id' => Plan::factory(),
            'token_id' => PaymentToken::factory(),
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subMonth()->toDateString(),
            'current_period_end' => now()->toDateString(),
            'next_charge_at' => now(),
            'dunning_stage' => 0,
        ];
    }

    public function configure(): static
    {
        // Keep the token owned by the same customer as the subscription.
        return $this->afterMaking(function (Subscription $subscription) {
            if ($subscription->token && $subscription->token->customer_id !== $subscription->customer_id) {
                $subscription->token->update(['customer_id' => $subscription->customer_id]);
            }
        });
    }
}
