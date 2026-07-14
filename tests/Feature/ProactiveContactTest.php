<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Mail\TicketReplyMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ProactiveContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_customer_opens_a_ticket_and_sends_over_whatsapp(): void
    {
        config(['billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default']);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w1'])]);
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['phone' => '+972501234567', 'whatsapp_jid' => '972501234567@c.us']);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('contactCustomer', [
                'channel' => 'whatsapp',
                'subject' => 'שאלה קצרה',
                'message' => '<p>מה <strong>שלומך</strong>?</p>',
            ]);

        $ticket = Ticket::where('customer_id', $customer->id)->sole();
        $this->assertSame(TicketChannel::Whatsapp, $ticket->channel);
        // The chat id is stored so the customer's reply threads back onto this ticket.
        $this->assertSame('972501234567@c.us', $ticket->external_thread_ref);

        $out = $ticket->messages()->where('direction', MessageDirection::Outbound)->sole();
        $this->assertSame('מה שלומך?', $out->body);
        $this->assertStringContainsString('<strong>שלומך</strong>', $out->body_html);

        // Delivered to the customer's WhatsApp, formatting converted to markup.
        $this->assertTrue(
            Http::recorded()->contains(fn ($pair) => ($pair[0]->data()['chatId'] ?? null) === '972501234567@c.us'
                && str_contains($pair[0]->data()['text'] ?? '', 'מה *שלומך*?')),
        );
    }

    public function test_contact_customer_can_send_by_email(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create(['email' => 'client@corp.com', 'phone' => null, 'whatsapp_jid' => null]);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('contactCustomer', [
                'channel' => 'email',
                'subject' => 'עדכון',
                'message' => '<p>רצינו לעדכן אותך</p>',
            ]);

        $ticket = Ticket::where('customer_id', $customer->id)->sole();
        $this->assertSame(TicketChannel::Email, $ticket->channel);
        Mail::assertSent(TicketReplyMail::class);
    }

    public function test_contact_customer_refuses_when_the_channel_is_unreachable(): void
    {
        $this->actingAs(User::factory()->create());
        // No email on file, but the operator picks email.
        $customer = Customer::factory()->create(['email' => null, 'phone' => '+972500000000']);

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('contactCustomer', [
                'channel' => 'email',
                'subject' => 'x',
                'message' => '<p>שלום</p>',
            ]);

        $this->assertSame(0, Ticket::where('customer_id', $customer->id)->count());
    }
}
