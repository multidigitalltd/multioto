<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Jobs\IngestWhatsappMessageJob;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\WebhookEvent;
use App\Services\Support\TicketIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContactMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_secondary_contact_resolves_to_the_right_customer(): void
    {
        $customer = Customer::factory()->create(['email' => 'owner@acme.co.il', 'phone' => '+972500000001']);
        Contact::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'רכזת תפעול',
            'role' => 'תפעול',
            'email' => 'ops@acme.co.il',
        ]);

        $matched = app(TicketIntake::class)->matchCustomer(email: 'ops@acme.co.il');

        $this->assertNotNull($matched);
        $this->assertSame($customer->id, $matched->id);
    }

    public function test_a_direct_customer_match_wins_over_contacts(): void
    {
        $direct = Customer::factory()->create(['email' => 'hit@acme.co.il']);
        // A different customer whose contact also carries that email — the direct
        // customer field must take precedence.
        $other = Customer::factory()->create(['email' => 'other@x.co.il']);
        Contact::factory()->create(['customer_id' => $other->id, 'email' => 'hit@acme.co.il']);

        $matched = app(TicketIntake::class)->matchCustomer(email: 'hit@acme.co.il');

        $this->assertSame($direct->id, $matched->id);
    }

    public function test_no_match_returns_null(): void
    {
        Customer::factory()->create(['email' => 'known@acme.co.il']);

        $this->assertNull(app(TicketIntake::class)->matchCustomer(email: 'stranger@nowhere.co.il'));
    }

    public function test_an_inbound_whatsapp_from_a_contact_opens_a_ticket_on_the_customer(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $customer = Customer::factory()->create(['name' => 'Acme', 'phone' => '+972500000009']);
        Contact::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'טכנאי',
            'phone' => '+972529876543',
            'whatsapp_jid' => '972529876543@c.us',
        ]);

        [$event] = WebhookEvent::record(WebhookSource::Waha, 'message', 'wa-contact-1', [
            'payload' => [
                'id' => 'wa-contact-1',
                'from' => '972529876543@c.us',
                'body' => 'שלום, יש תקלה',
            ],
        ]);

        IngestWhatsappMessageJob::dispatchSync($event->id);

        $ticket = Ticket::query()->where('status', TicketStatus::Open)->firstOrFail();
        $this->assertSame($customer->id, $ticket->customer_id);
    }
}
