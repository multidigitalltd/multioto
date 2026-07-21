<?php

namespace Tests\Feature;

use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketLinkToCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_linking_a_whatsapp_enquiry_creates_a_contact_and_associates_the_ticket(): void
    {
        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => null,
            'contact_name' => 'שמעון',
            'contact_handle' => '+972529876543',
            'external_thread_ref' => '972529876543@c.us',
            'channel' => TicketChannel::Whatsapp,
            'subject' => 'תקלה',
            'status' => TicketStatus::Open,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->callAction('linkToCustomer', data: [
                'customer_id' => $customer->id,
                'name' => 'שמעון',
                'role' => 'בעלים',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($customer->id, $ticket->fresh()->customer_id);

        $contact = Contact::sole();
        $this->assertSame($customer->id, $contact->customer_id);
        $this->assertSame('בעלים', $contact->role);
        $this->assertSame('+972529876543', $contact->phone);
        $this->assertSame('972529876543@c.us', $contact->whatsapp_jid);
    }

    public function test_linking_an_email_enquiry_stores_the_email_on_the_contact(): void
    {
        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => null,
            'contact_name' => 'Dana',
            'contact_handle' => 'dana@lead.co.il',
            'channel' => TicketChannel::Email,
            'subject' => 'שאלה',
            'status' => TicketStatus::Open,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->callAction('linkToCustomer', data: ['customer_id' => $customer->id, 'name' => 'Dana'])
            ->assertHasNoActionErrors();

        $contact = Contact::sole();
        $this->assertSame('dana@lead.co.il', $contact->email);
        $this->assertNull($contact->phone);
        $this->assertSame($customer->id, $ticket->fresh()->customer_id);
    }

    public function test_the_action_is_hidden_for_an_already_identified_ticket(): void
    {
        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'שאלה',
            'status' => TicketStatus::Open,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->assertActionHidden('linkToCustomer');
    }
}
