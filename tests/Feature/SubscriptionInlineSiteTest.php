<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Filament\Resources\SubscriptionResource\Pages\CreateSubscription;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A site added inline from the subscription form (without leaving to the Sites
 * screen) must be created under the subscription's selected customer — never
 * unassigned or under someone else.
 */
class SubscriptionInlineSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_inline_site_under_the_selected_customer(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();

        Livewire::test(CreateSubscription::class)
            ->fillForm(['customer_id' => $customer->id])
            ->callFormComponentAction('site_id', 'createOption', data: [
                'domain' => 'new-inline.co.il',
            ]);

        $site = Site::query()->where('domain', 'new-inline.co.il')->first();

        $this->assertNotNull($site, 'the inline site should have been created');
        $this->assertSame($customer->id, $site->customer_id);
        $this->assertSame(SiteStatus::Active, $site->status);
    }
}
