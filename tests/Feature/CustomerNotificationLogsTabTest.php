<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\NotificationLogsRelationManager;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerNotificationLogsTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_the_tab_lists_outbound_messages_for_this_customer_only(): void
    {
        $customer = Customer::factory()->create();
        $mine = NotificationLog::record('email', NotificationType::PaymentLink, 'me@x.co', 'בקשת תשלום', 'גוף ההודעה', $customer->id);
        $other = NotificationLog::record('email', NotificationType::Welcome, 'other@x.co', 'ברוך הבא', 'גוף אחר', Customer::factory()->create()->id);

        Livewire::test(NotificationLogsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('בקשת תשלום');
    }

    public function test_the_tab_is_read_only(): void
    {
        $customer = Customer::factory()->create();

        // No create action — this is an audit trail, not an editor.
        Livewire::test(NotificationLogsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])->assertActionDoesNotExist('create');
    }
}
