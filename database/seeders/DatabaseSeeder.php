<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local/staging demo data: an admin user, three plans, and a handful of
     * customers with sites + active subscriptions (one VAT-exempt).
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@multi.digital',
        ]);

        $plans = Plan::factory()->createMany([
            ['name' => 'אחזקה בסיסית', 'price_agorot' => 9900],
            ['name' => 'אחזקה עסקית', 'price_agorot' => 19900],
            ['name' => 'אחזקה פרימיום', 'price_agorot' => 39900],
        ]);

        Customer::factory()
            ->count(5)
            ->create()
            ->each(function (Customer $customer, int $i) use ($plans) {
                $site = Site::factory()->for($customer)->create();

                $subscription = Subscription::factory()
                    ->for($customer)
                    ->for($plans[$i % $plans->count()])
                    ->create(['site_id' => $site->id]);

                $subscription->token->update(['customer_id' => $customer->id]);
                $customer->update(['default_token_id' => $subscription->token_id]);
            });

        Customer::factory()->vatExempt()->create(['name' => 'עוסק פטור לדוגמה']);
    }
}
