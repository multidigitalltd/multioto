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
