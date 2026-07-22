<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ManageDataRetention;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManageDataRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_it_saves_retention_windows_and_overlays_config(): void
    {
        Livewire::test(ManageDataRetention::class)
            ->fillForm([
                'system.monitor_check_retention_days' => 120,
                'system.webhook_retention_days' => 45,
                'system.notification_retention_days' => 21,
                'system.log_retention_days' => 14,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // Stored as settings…
        $this->assertSame('120', Setting::map()['system.monitor_check_retention_days']);
        $this->assertSame('45', Setting::map()['system.webhook_retention_days']);

        // …and overlaid onto live config, where the prune jobs read them.
        $this->assertSame(120, (int) config('billing.system.monitor_check_retention_days'));
        $this->assertSame(45, (int) config('billing.system.webhook_retention_days'));
        $this->assertSame(21, (int) config('billing.system.notification_retention_days'));
        $this->assertSame(14, (int) config('billing.system.log_retention_days'));
    }

    public function test_monitor_retention_cannot_drop_below_the_monthly_report_window(): void
    {
        config(['billing.monitoring.monthly_report.window_days' => 30]);

        Livewire::test(ManageDataRetention::class)
            ->fillForm(['system.monitor_check_retention_days' => 10])
            ->call('save')
            ->assertHasFormErrors(['system.monitor_check_retention_days']);
    }

    public function test_the_page_is_admin_only(): void
    {
        $this->assertTrue(ManageDataRetention::canAccess());

        $this->actingAs(User::factory()->create(['role' => UserRole::Agent]));
        $this->assertFalse(ManageDataRetention::canAccess());
    }
}
