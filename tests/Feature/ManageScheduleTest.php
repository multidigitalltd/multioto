<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ManageSchedule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManageScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_it_saves_the_shabbat_and_service_day_settings_and_overlays_config(): void
    {
        Livewire::test(ManageSchedule::class)
            ->fillForm([
                'shabbat.block_automations' => false,
                'shabbat.resume_mode' => 'day_after',
                'shabbat.resume_time' => '09:30',
                'shabbat.candle_offset_minutes' => 22,
                'shabbat.havdalah_offset_minutes' => 50,
                'shabbat.latitude' => 31.7683,
                'shabbat.longitude' => 35.2137,
                'service_days.enabled' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // Stored as settings…
        $this->assertSame('0', Setting::map()['shabbat.block_automations']);
        $this->assertSame('day_after', Setting::map()['shabbat.resume_mode']);
        $this->assertSame('09:30', Setting::map()['shabbat.resume_time']);
        $this->assertSame('50', Setting::map()['shabbat.havdalah_offset_minutes']);
        $this->assertSame('0', Setting::map()['service_days.enabled']);

        // …and overlaid onto live config.
        $this->assertFalse((bool) config('billing.shabbat.block_automations'));
        $this->assertSame(50, (int) config('billing.shabbat.havdalah_offset_minutes'));
        $this->assertSame(35.2137, (float) config('billing.shabbat.longitude'));
        $this->assertFalse((bool) config('billing.service_days.enabled'));
    }

    public function test_the_page_is_admin_only(): void
    {
        $this->assertTrue(ManageSchedule::canAccess());

        $this->actingAs(User::factory()->create(['role' => UserRole::Agent]));
        $this->assertFalse(ManageSchedule::canAccess());
    }
}
