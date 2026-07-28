<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\OnboardCustomer;
use App\Jobs\SendCardCaptureLinkJob;
use App\Models\Customer;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "לקוח חדש" wizard.
 *
 * A customer is a customer before there is anything to charge them for — a
 * one-off job, a prospect, an arrangement still being negotiated. Opening one
 * must not require inventing a subscription, because an invented subscription
 * starts a billing cycle nobody agreed to.
 */
class OnboardCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_the_wizard_opens_a_customer_with_a_site_and_a_subscription(): void
    {
        Queue::fake();

        Livewire::test(OnboardCustomer::class)
            ->fillForm([
                'name' => 'עסק חדש',
                'phone' => '0501234567',
                'email' => 'new@b.co.il',
                'business_type' => 'licensed_dealer',
                'domain' => 'example.co.il',
                'custom_name' => 'אחסון + תחזוקה',
                'price_override' => 250,
                'first_charge_at' => now()->format('Y-m-d'),
                'send_card_link' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::firstOrFail();

        $this->assertSame(1, Site::where('customer_id', $customer->id)->count());
        $this->assertSame(25000, Subscription::firstOrFail()->price_agorot_override);

        Queue::assertPushed(SendCardCaptureLinkJob::class);
    }

    public function test_a_customer_can_be_opened_without_a_subscription(): void
    {
        Queue::fake();

        Livewire::test(OnboardCustomer::class)
            ->fillForm([
                'name' => 'לקוח חד-פעמי',
                'phone' => '0507654321',
                'email' => 'oneoff@b.co.il',
                'business_type' => 'licensed_dealer',
                'domain' => 'oneoff.co.il',
                'skip_subscription' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::firstOrFail();

        $this->assertSame('לקוח חד-פעמי', $customer->name);
        $this->assertSame(1, Site::where('customer_id', $customer->id)->count());

        // Nothing to charge means no subscription and no card invite — the
        // card link is a step in a billing arrangement that does not exist yet.
        $this->assertSame(0, Subscription::count());
        Queue::assertNotPushed(SendCardCaptureLinkJob::class);
    }

    public function test_a_customer_without_a_subscription_does_not_need_a_domain_either(): void
    {
        Queue::fake();

        // A prospect may have no site with us at all. Demanding a domain would
        // block the very thing this option exists for.
        Livewire::test(OnboardCustomer::class)
            ->fillForm([
                'name' => 'ליד',
                'phone' => '0509999999',
                'email' => 'lead@b.co.il',
                'business_type' => 'licensed_dealer',
                'skip_subscription' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Customer::count());
        $this->assertSame(0, Site::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_a_domain_is_still_required_when_a_subscription_is_being_opened(): void
    {
        Livewire::test(OnboardCustomer::class)
            ->fillForm([
                'name' => 'עסק',
                'phone' => '0501111111',
                'email' => 'biz@b.co.il',
                'business_type' => 'licensed_dealer',
                'custom_name' => 'תחזוקה',
                'price_override' => 100,
                'first_charge_at' => now()->format('Y-m-d'),
            ])
            ->call('create')
            ->assertHasFormErrors(['domain']);

        $this->assertSame(0, Customer::count());
    }
}
