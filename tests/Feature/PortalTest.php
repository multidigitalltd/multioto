<?php

namespace Tests\Feature;

use App\Enums\ChargeStatus;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Mail\NotificationMail;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_renders_for_a_guest(): void
    {
        $this->get(route('portal.login'))->assertOk()->assertSee('כניסה לאזור האישי');
    }

    public function test_a_known_email_is_mailed_a_signed_magic_link(): void
    {
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'dana@example.co']);

        $this->post(route('portal.login.send'), ['email' => 'DANA@example.co'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m): bool => $m->hasTo('dana@example.co')
            && str_contains($m->bodyText, '/portal/auth/'.$customer->id));
    }

    public function test_an_unknown_email_reveals_nothing_and_sends_no_link(): void
    {
        Mail::fake();

        $this->post(route('portal.login.send'), ['email' => 'nobody@example.co'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Mail::assertNothingSent();
    }

    public function test_a_guest_is_redirected_to_sign_in(): void
    {
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    }

    public function test_a_valid_magic_link_signs_the_customer_in(): void
    {
        $customer = Customer::factory()->create();

        $link = URL::temporarySignedRoute('portal.auth', now()->addMinutes(30), ['customer' => $customer->id]);

        $this->get($link)->assertRedirect(route('portal.dashboard'));
        $this->assertSame($customer->id, session('portal.customer_id'));
    }

    public function test_a_tampered_magic_link_is_rejected(): void
    {
        $customer = Customer::factory()->create();

        $this->get(route('portal.auth', ['customer' => $customer->id]).'?signature=deadbeef')
            ->assertForbidden();
    }

    public function test_a_customer_only_sees_their_own_invoices(): void
    {
        $mine = $this->invoiceFor(Customer::factory()->create(), 'INV-MINE-001');
        $other = $this->invoiceFor(Customer::factory()->create(), 'INV-OTHER-999');

        $this->withSession(['portal.customer_id' => $mine->customer_id])
            ->get(route('portal.invoices'))
            ->assertOk()
            ->assertSee('INV-MINE-001')
            ->assertDontSee('INV-OTHER-999');
    }

    public function test_the_tickets_page_lists_the_customers_tickets(): void
    {
        $customer = Customer::factory()->create();
        Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'תקלה בדומיין',
            'status' => TicketStatus::Open,
        ]);

        $this->withSession(['portal.customer_id' => $customer->id])
            ->get(route('portal.tickets'))
            ->assertOk()
            ->assertSee('תקלה בדומיין');
    }

    public function test_the_debt_page_lists_only_the_customers_open_demands_with_a_pay_link(): void
    {
        $mine = Customer::factory()->create();
        $other = Customer::factory()->create();

        $myCharge = $this->demandedCharge($mine, 24000, 'אחסון שנתי');
        $this->demandedCharge($other, 99900, 'חיוב של לקוח אחר');

        $this->withSession(['portal.customer_id' => $mine->id])
            ->get(route('portal.debt'))
            ->assertOk()
            ->assertSee('אחסון שנתי')
            ->assertDontSee('חיוב של לקוח אחר')
            // A signed, cancelable pay link for this charge.
            ->assertSee('/billing/pay/'.$myCharge->id);
    }

    public function test_a_pending_charge_without_a_demand_is_not_shown_as_debt(): void
    {
        $customer = Customer::factory()->create();

        // Pending but never demanded — mid-processing, not something we've asked
        // the customer to pay. It must not appear as open debt.
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);
        Charge::create([
            'subscription_id' => $subscription->id,
            'amount_agorot' => 5000, 'vat_agorot' => 900, 'total_agorot' => 5900,
            'status' => ChargeStatus::Pending, 'attempt_number' => 1,
            'description' => 'טרם נדרש',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
        ]);

        $this->withSession(['portal.customer_id' => $customer->id])
            ->get(route('portal.debt'))
            ->assertOk()
            ->assertSee('הכול משולם')
            ->assertDontSee('טרם נדרש');
    }

    public function test_the_dashboard_surfaces_open_debt_when_present(): void
    {
        $customer = Customer::factory()->create();
        $this->demandedCharge($customer, 15000, 'תוסף פרימיום');

        $this->withSession(['portal.customer_id' => $customer->id])
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('תשלומים פתוחים')
            ->assertSee(route('portal.debt'));
    }

    public function test_update_card_forwards_to_the_signed_card_page(): void
    {
        $customer = Customer::factory()->create();
        config(['billing.card_update_link_ttl_hours' => 24]);

        $this->withSession(['portal.customer_id' => $customer->id])
            ->get(route('portal.card'))
            ->assertRedirectContains('/billing/update-card/'.$customer->id);
    }

    public function test_logout_clears_the_portal_session(): void
    {
        $customer = Customer::factory()->create();

        $this->withSession(['portal.customer_id' => $customer->id])
            ->post(route('portal.logout'))
            ->assertRedirect(route('portal.login'));

        $this->assertNull(session('portal.customer_id'));
    }

    /** A pending charge with a payment demand issued (i.e. open debt) for a customer. */
    private function demandedCharge(Customer $customer, int $totalAgorot, string $description): Charge
    {
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);

        return Charge::create([
            'subscription_id' => $subscription->id,
            'amount_agorot' => (int) round($totalAgorot / 1.18),
            'vat_agorot' => $totalAgorot - (int) round($totalAgorot / 1.18),
            'total_agorot' => $totalAgorot,
            'status' => ChargeStatus::Pending,
            'attempt_number' => 1,
            'description' => $description,
            'demand_sent_at' => now()->subDays(2),
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
        ]);
    }

    /** Build a paid invoice attached to a fresh subscription for a customer. */
    private function invoiceFor(Customer $customer, string $allocation): Invoice
    {
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);

        $charge = Charge::create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'status' => ChargeStatus::Succeeded,
            'attempt_number' => 1,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
        ]);

        return Invoice::create([
            'charge_id' => $charge->id,
            'customer_id' => $customer->id,
            'linet_document_id' => 'LNT-'.$charge->id,
            'allocation_number' => $allocation,
            'amount_agorot' => 10000,
            'vat_agorot' => 1800,
            'total_agorot' => 11800,
            'issued_at' => now(),
        ]);
    }
}
