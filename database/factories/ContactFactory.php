<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'site_id' => null,
            'name' => fake()->name(),
            'role' => 'מנהל/ת',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+9725'.fake()->numerify('########'),
            'whatsapp_jid' => null,
            'is_primary' => false,
        ];
    }
}
