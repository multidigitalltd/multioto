<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Filament\Pages\PaymentDemands;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\SendPaymentLinkJob;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "דרישות תשלום" screen: open a proforma demand (which issues the proforma
 * and emails a non-auto-charging link + bank transfer) and list existing demands.
 */
class PaymentDemandsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_a_demand_dispatches_a_proforma_payment_link_with_transfer(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['email' => 'c@example.co.il']);

        Livewire::test(PaymentDemands::class)
            ->callAction('newDemand', data: [
                'customer_id' => $customer->id,
                'description' => 'אחסון שנתי',
                'items' => [],
                'amount' => 250,
                'channel' => 'email',
            ])
            ->assertHasNoActionErrors();

        Queue::assertPushed(SendPaymentLinkJob::class, function (SendPaymentLinkJob $job) use ($customer): bool {
            return $job->customerId === $customer->id
                && $job->totalAgorot === 25000
                && $job->channel === 'email'
                // A demand never auto-charges: bank transfer (preferred) is
                // listed first, the manual card link second.
                && $job->methods === ['transfer', 'link'];
        });
    }

    public function test_a_demand_carries_the_chosen_pay_by_date(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['email' => 'c@example.co.il']);

        Livewire::test(PaymentDemands::class)
            ->callAction('newDemand', data: [
                'customer_id' => $customer->id,
                'description' => 'אחסון שנתי',
                'items' => [],
                'amount' => 250,
                'due_at' => now()->addDays(10)->toDateString(),
                'channel' => 'email',
            ])
            ->assertHasNoActionErrors();

        Queue::assertPushed(SendPaymentLinkJob::class, fn (SendPaymentLinkJob $job): bool => $job->dueAt === now()->addDays(10)->toDateString());
    }

    public function test_items_are_entered_net_and_vat_is_added_per_item(): void
    {
        Queue::fake();
        config(['billing.vat_rate' => 0.18]);
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['email' => 'c@example.co.il']);

        Livewire::test(PaymentDemands::class)
            ->callAction('newDemand', data: [
                'customer_id' => $customer->id,
                'description' => 'חבילה',
                'items' => [
                    // Net 100 + VAT → 118.
                    ['name' => 'אחסון', 'qty' => 1, 'unit_price' => 100, 'add_vat' => true],
                    // Net 50, no VAT, ×2 → 100.
                    ['name' => 'שירות פטור', 'qty' => 2, 'unit_price' => 50, 'add_vat' => false],
                ],
                'amount' => null,
                'channel' => 'email',
            ])
            ->assertHasNoActionErrors();

        Queue::assertPushed(SendPaymentLinkJob::class, function (SendPaymentLinkJob $job): bool {
            return $job->totalAgorot === 21800 // 11800 + (5000 × 2)
                && $job->lines[0]['unit_price_agorot'] === 11800
                && $job->lines[1]['unit_price_agorot'] === 5000;
        });
    }

    public function test_the_table_lists_only_sent_demands(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();

        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);
        // An ordinary (non-demand) charge must not appear.
        $plain = $this->charge($customer->id, ['demand_sent_at' => null]);

        Livewire::test(PaymentDemands::class)
            ->assertCanSeeTableRecords([$demand])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    public function test_mark_paid_finalises_the_demand_and_issues_the_tax_receipt(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        Livewire::test(PaymentDemands::class)
            ->callTableAction('markPaid', $demand)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ChargeStatus::Succeeded, $demand->fresh()->status);
        $this->assertNotNull($demand->fresh()->charged_at);
        Queue::assertPushed(IssueInvoiceJob::class,
            fn (IssueInvoiceJob $job): bool => $job->chargeId === $demand->id);
    }

    public function test_toggle_reminders_pauses_and_resumes_a_single_demand(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $demand = $this->charge($customer->id, ['demand_sent_at' => now(), 'demand_channel' => 'email']);

        Livewire::test(PaymentDemands::class)
            ->callTableAction('toggleReminders', $demand)
            ->assertHasNoTableActionErrors();
        $this->assertTrue($demand->fresh()->demand_reminders_paused);

        Livewire::test(PaymentDemands::class)
            ->callTableAction('toggleReminders', $demand)
            ->assertHasNoTableActionErrors();
        $this->assertFalse($demand->fresh()->demand_reminders_paused);
    }

    private function charge(int $customerId, array $overrides = []): Charge
    {
        return Charge::create(array_merge([
            'subscription_id' => null,
            'customer_id' => $customerId,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => 'דרישה',
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ], $overrides));
    }
}
