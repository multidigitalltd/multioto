<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedStarterPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_starter_plans_and_is_idempotent(): void
    {
        $this->artisan('app:seed-plans')->assertSuccessful();

        $this->assertSame(3, Plan::count());
        $this->assertDatabaseHas('plans', ['name' => 'אחזקה בסיסית', 'price_agorot' => 9900, 'active' => true]);

        // Re-running must not duplicate, and must not overwrite an edited price.
        Plan::where('name', 'אחזקה בסיסית')->update(['price_agorot' => 12900]);

        $this->artisan('app:seed-plans')->assertSuccessful();

        $this->assertSame(3, Plan::count());
        $this->assertSame(12900, Plan::where('name', 'אחזקה בסיסית')->value('price_agorot'));
    }
}
