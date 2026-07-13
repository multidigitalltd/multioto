<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Jobs\SendTicketReplyJob;
use App\Mail\TicketReplyMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class RichReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_formatted_whatsapp_reply_is_delivered_as_whatsapp_markup(): void
    {
        config([
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default',
        ]);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w1'])]);

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'x', 'status' => TicketStatus::Open,
            'external_thread_ref' => '972501234567@c.us',
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp,
            'body' => 'שלום מודגש', 'body_html' => '<p>שלום <strong>מודגש</strong></p>',
            'author' => MessageAuthor::Agent,
        ]);

        SendTicketReplyJob::dispatchSync($message->id);

        // HTML became WhatsApp markup — no raw tags reach the customer.
        Http::assertSent(fn ($request): bool => str_contains($request->data()['text'], 'שלום *מודגש*')
            && ! str_contains($request->data()['text'], '<strong>'));
    }

    public function test_a_formatted_email_reply_carries_the_html_body(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'lead@example.com']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'x', 'status' => TicketStatus::Open,
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email,
            'body' => 'שלום מודגש', 'body_html' => '<p>שלום <strong>מודגש</strong></p>',
            'author' => MessageAuthor::Agent,
        ]);

        SendTicketReplyJob::dispatchSync($message->id);

        Mail::assertSent(TicketReplyMail::class, fn (TicketReplyMail $mail): bool => str_contains((string) $mail->bodyHtml, '<strong>מודגש</strong>'));
    }

    public function test_the_editor_stores_plain_and_html_bodies(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w1'])]);
        config(['billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default']);
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'x', 'status' => TicketStatus::Open, 'external_thread_ref' => '972501234567@c.us',
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->set('replyData.body', '<p>תשובה <strong>עם הדגשה</strong></p>')
            ->call('sendReply');

        $out = $ticket->messages()->where('direction', MessageDirection::Outbound)->sole();
        // Plain text is canonical; the HTML preserves the formatting.
        $this->assertSame('תשובה עם הדגשה', $out->body);
        $this->assertStringContainsString('<strong>עם הדגשה</strong>', $out->body_html);
    }

    public function test_an_ai_draft_can_be_loaded_into_the_editor_for_editing(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'x', 'status' => TicketStatus::Open,
        ]);
        $draft = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::InternalNote,
            'body' => "🤖 טיוטת תשובה (ביטחון: high) — לאישור לפני שליחה:\n\nשלום, הבעיה טופלה.",
            'author' => MessageAuthor::Ai,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->call('useDraft', $draft->id)
            ->assertSet('replyChannel', MessageChannel::Email->value)
            ->assertSet('replyData.body', fn ($body) => str_contains((string) $body, 'שלום, הבעיה טופלה.')
                && ! str_contains((string) $body, 'לאישור לפני שליחה'));
    }
}
