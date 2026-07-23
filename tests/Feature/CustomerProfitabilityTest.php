<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\IncidentStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Filament\Pages\CustomerProfitability;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Billing\ProfitabilityReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic weights: ticket 30m, message 5m, incident 30m, ₪120/h.
        config([
            'billing.profitability.minutes_per_ticket' => 30,
            'billing.profitability.minutes_per_message' => 5,
            'billing.profitability.minutes_per_incident' => 30,
            'billing.profitability.hourly_cost_agorot' => 12000,
        ]);
    }

    private function succeededCharge(Customer $customer, int $totalAgorot, bool $viaSubscription = false): Charge
    {
        return Charge::create([
            'subscription_id' => Subscription::factory()->create(['customer_id' => $customer->id])->id,
            'customer_id' => $viaSubscription ? null : $customer->id,
            'amount_agorot' => $totalAgorot,
            'vat_agorot' => 0,
            'total_agorot' => $totalAgorot,
            'status' => ChargeStatus::Succeeded,
            'attempt_number' => 1,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
        ]);
    }

    public function test_revenue_load_and_profit_are_computed_per_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'לקוח רווחי']);

        // ₪300 direct + ₪200 attributed through the subscription = ₪500.
        $this->succeededCharge($customer, 30000);
        $this->succeededCharge($customer, 20000, viaSubscription: true);

        // Load: one ticket (30m) with two inbound messages (2×5m) + one incident (30m) = 70m.
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'עזרה', 'status' => TicketStatus::Open,
        ]);
        foreach (range(1, 2) as $i) {
            $ticket->messages()->create([
                'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Email,
                'body' => "הודעה {$i}", 'author' => MessageAuthor::Customer,
            ]);
        }
        Site::factory()->create(['customer_id' => $customer->id])
            ->incidents()->create(['started_at' => now()->subHours(2), 'status' => IncidentStatus::Resolved]);

        $rows = app(ProfitabilityReport::class)->rows(90);

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('לקוח רווחי', $row['name']);
        $this->assertSame(50000, $row['revenue_agorot']);
        $this->assertSame(1, $row['tickets']);
        $this->assertSame(2, $row['messages']);
        $this->assertSame(1, $row['incidents']);
        $this->assertSame(70, $row['minutes']);
        // 70m at ₪120/h = ₪140 → profit ₪360, margin 72%.
        $this->assertSame(14000, $row['cost_agorot']);
        $this->assertSame(36000, $row['profit_agorot']);
        $this->assertSame(72.0, $row['margin']);
    }

    public function test_unprofitable_customers_are_listed_first(): void
    {
        $profitable = Customer::factory()->create(['name' => 'משלם יפה']);
        $this->succeededCharge($profitable, 100000);

        // Pays nothing, opens four tickets — negative profit, must rank first.
        $drain = Customer::factory()->create(['name' => 'אוכל את העסק']);
        foreach (range(1, 4) as $i) {
            Ticket::create([
                'customer_id' => $drain->id, 'channel' => TicketChannel::Whatsapp,
                'subject' => "בעיה {$i}", 'status' => TicketStatus::Open,
            ]);
        }

        $rows = app(ProfitabilityReport::class)->rows(90);

        $this->assertSame(['אוכל את העסק', 'משלם יפה'], $rows->pluck('name')->all());
        $this->assertTrue($rows->first()['profit_agorot'] < 0);
        $this->assertNull($rows->first()['margin']);
    }

    public function test_activity_outside_the_window_is_excluded(): void
    {
        $customer = Customer::factory()->create();

        $old = $this->succeededCharge($customer, 50000);
        $old->created_at = now()->subDays(120);
        $old->save();

        $oldTicket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'ישן', 'status' => TicketStatus::Closed,
        ]);
        $oldTicket->created_at = now()->subDays(120);
        $oldTicket->save();

        $this->assertCount(0, app(ProfitabilityReport::class)->rows(90));
    }

    public function test_failed_charges_do_not_count_as_revenue(): void
    {
        $customer = Customer::factory()->create();
        $charge = $this->succeededCharge($customer, 10000);
        $charge->update(['status' => ChargeStatus::Failed]);

        $this->assertCount(0, app(ProfitabilityReport::class)->rows(90));
    }

    public function test_the_page_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Agent]));
        $this->assertFalse(CustomerProfitability::canAccess());

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->assertTrue(CustomerProfitability::canAccess());
    }

    public function test_the_page_renders_with_totals_and_rows(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $customer = Customer::factory()->create(['name' => 'חברת בדיקה בעמ']);
        $this->succeededCharge($customer, 42000);

        Livewire::test(CustomerProfitability::class)
            ->assertSeeText('חברת בדיקה בעמ')
            ->assertSeeText('₪420.00');
    }

    public function test_an_invalid_window_value_falls_back_to_the_default(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(CustomerProfitability::class)
            ->set('windowDays', 9999)
            ->assertSet('windowDays', 90);
    }
}
