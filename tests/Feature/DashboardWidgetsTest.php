<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Widgets\Debtors;
use App\Filament\Widgets\PendingApprovals;
use App\Models\PendingAction;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_pending_approvals_widget_hidden_when_empty_and_shown_with_work(): void
    {
        $this->assertFalse(PendingApprovals::canView());

        PendingAction::create(['type' => 'ticket_reply', 'status' => ActionStatus::Pending, 'summary' => 'תשובה ללקוח', 'payload' => ['reply' => 'x']]);

        $this->assertTrue(PendingApprovals::canView());
        Livewire::test(PendingApprovals::class)->assertCanSeeTableRecords(PendingAction::all());
    }

    public function test_debtors_widget_lists_only_arrears(): void
    {
        $current = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);
        $late = Subscription::factory()->create(['status' => SubscriptionStatus::PastDue]);

        $this->assertTrue(Debtors::canView());
        Livewire::test(Debtors::class)
            ->assertCanSeeTableRecords([$late])
            ->assertCanNotSeeTableRecords([$current]);
    }
}
