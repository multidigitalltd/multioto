<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketReplyJob;
use App\Mail\TicketReplyMail;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReplySignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_email_signature_is_appended_to_an_email_reply(): void
    {
        config(['billing.notifications.reply_signature' => "בברכה,\nצוות Multi Digital"]);
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'lead@example.com']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'שאלה', 'status' => TicketStatus::Open,
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email,
            'body' => 'הטיפול הושלם.', 'author' => MessageAuthor::Agent,
        ]);

        SendTicketReplyJob::dispatchSync($message->id);

        Mail::assertSent(TicketReplyMail::class, fn (TicketReplyMail $mail): bool => str_contains($mail->bodyText, 'הטיפול הושלם.')
            && str_contains($mail->bodyText, 'צוות Multi Digital'));

        // The stored internal message stays exactly as the agent typed it.
        $this->assertSame('הטיפול הושלם.', $message->fresh()->body);
    }

    public function test_the_whatsapp_signature_is_appended_to_a_whatsapp_reply(): void
    {
        config([
            'billing.notifications.reply_signature_whatsapp' => '— צוות Multi Digital',
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default',
        ]);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w1'])]);

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'שאלה', 'status' => TicketStatus::Open,
            'external_thread_ref' => '972501234567@c.us',
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp,
            'body' => 'בדקנו, הכל תקין.', 'author' => MessageAuthor::Agent,
        ]);

        SendTicketReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendText')
            && str_contains($request->data()['text'], 'בדקנו, הכל תקין.')
            && str_contains($request->data()['text'], '— צוות Multi Digital'));
    }

    public function test_no_signature_configured_leaves_the_body_unchanged(): void
    {
        config(['billing.notifications.reply_signature' => null]);
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'lead@example.com']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'שאלה', 'status' => TicketStatus::Open,
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email,
            'body' => 'תשובה קצרה.', 'author' => MessageAuthor::Agent,
        ]);

        SendTicketReplyJob::dispatchSync($message->id);

        Mail::assertSent(TicketReplyMail::class, fn (TicketReplyMail $mail): bool => trim($mail->bodyText) === 'תשובה קצרה.');
    }
}
