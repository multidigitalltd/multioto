<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ManualCollection;
use App\Filament\Resources\ChargeResource;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\SubscriptionsRelationManager;
use App\Filament\Resources\NotificationTemplateResource;
use App\Filament\Resources\TicketResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Widgets\StatsOverview;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-user module access: an admin limits each agent to a subset of the
 * panel's modules (finance / support / management). Screens of an ungranted
 * module disappear from navigation AND 403 on direct URL access; admins and
 * unrestricted agents are unaffected.
 */
class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function agent(?array $modules): User
    {
        return User::factory()->create([
            'role' => UserRole::Agent,
            'allowed_modules' => $modules,
        ]);
    }

    public function test_an_agent_limited_to_support_cannot_reach_finance_screens(): void
    {
        $this->actingAs($this->agent(['support']));

        $this->get(ChargeResource::getUrl())->assertForbidden();
        $this->get(ManualCollection::getUrl())->assertForbidden();
        $this->get(CustomerResource::getUrl())->assertForbidden(); // ניהול
        $this->get(TicketResource::getUrl())->assertOk();          // תמיכה
    }

    public function test_navigation_entries_follow_the_grant(): void
    {
        $this->actingAs($this->agent(['finance']));

        $this->assertTrue(ChargeResource::canAccess());
        $this->assertTrue(ManualCollection::canAccess());
        $this->assertFalse(TicketResource::canAccess());
        $this->assertFalse(CustomerResource::canAccess());
    }

    public function test_an_unrestricted_agent_keeps_full_access(): void
    {
        // null = never limited (all existing users before this feature).
        $this->actingAs($this->agent(null));

        $this->get(ChargeResource::getUrl())->assertOk();
        $this->get(TicketResource::getUrl())->assertOk();
        $this->get(CustomerResource::getUrl())->assertOk();
    }

    public function test_an_admin_is_never_limited(): void
    {
        // Even a (nonsensical) restriction row on an admin must not apply.
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Admin,
            'allowed_modules' => ['support'],
        ]));

        $this->get(ChargeResource::getUrl())->assertOk();
        $this->get(CustomerResource::getUrl())->assertOk();
    }

    public function test_dashboard_widgets_respect_the_module_grant(): void
    {
        $this->actingAs($this->agent(['support']));
        $this->assertFalse(StatsOverview::canView());

        $this->actingAs($this->agent(['finance']));
        $this->assertTrue(StatsOverview::canView());
    }

    public function test_an_agent_with_no_modules_still_reaches_the_dashboard(): void
    {
        $this->actingAs($this->agent([]));

        $this->get('/admin')->assertOk();
        $this->get(ChargeResource::getUrl())->assertForbidden();
    }

    public function test_the_clustered_template_editor_is_gated_by_the_support_module(): void
    {
        // The notification-template editor sits in the settings cluster with
        // no navigation group of its own; it edits the customer-facing message
        // templates, so it follows the support module explicitly — a direct
        // URL must not slip past just because the group is null.
        $this->actingAs($this->agent(['management']));
        $this->get(NotificationTemplateResource::getUrl())->assertForbidden();

        $this->actingAs($this->agent(['support']));
        $this->get(NotificationTemplateResource::getUrl())->assertOk();

        $this->actingAs($this->agent(null));
        $this->get(NotificationTemplateResource::getUrl())->assertOk();
    }

    public function test_financial_operations_on_the_customer_page_require_the_finance_module(): void
    {
        // A management-only agent can open the customer page, but must get it
        // without the subscriptions tab (create/cancel/charge-now) and without
        // the charge / payment-link / card actions.
        $customer = Customer::factory()->create();

        $this->actingAs($this->agent(['management']));
        $this->assertFalse(
            SubscriptionsRelationManager::canViewForRecord($customer, ViewCustomer::class),
        );
        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertActionDoesNotExist('newCharge')
            ->assertActionDoesNotExist('paymentLink');

        $this->actingAs($this->agent(['management', 'finance']));
        $this->assertTrue(
            SubscriptionsRelationManager::canViewForRecord($customer, ViewCustomer::class),
        );
        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->assertActionExists('newCharge');
    }

    public function test_editing_an_unrestricted_user_keeps_the_null_grant(): void
    {
        // The checkbox list shows null as "everything checked"; saving it back
        // must store null again — not today's explicit key list — so modules
        // added in the future are granted automatically.
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $agent = $this->agent(null);

        Livewire::test(EditUser::class, ['record' => $agent->getRouteKey()])
            ->fillForm(['name' => 'שם חדש'])
            ->call('save');

        $this->assertNull($agent->refresh()->allowed_modules);
        $this->assertSame('שם חדש', $agent->name);
    }
}
