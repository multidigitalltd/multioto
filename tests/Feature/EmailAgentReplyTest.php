<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Jobs\IngestEmailMessageJob;
use App\Mail\TicketReplyMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailAgentReplyTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(): Ticket
    {
        $customer = Customer::factory()->create(['email' => 'client@corp.com']);

        return Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'שאלה', 'status' => TicketStatus::Open,
        ]);
    }

    public function test_a_team_member_email_reply_is_sent_to_the_customer(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'agent@multi.digital']);
        $ticket = $this->ticket();

        [$event] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'agent-reply-1', [
            'MessageID' => 'agent-reply-1',
            'From' => 'Agent <agent@multi.digital>',
            'Subject' => "Re: שאלה {$ticket->emailTag()}",
            'StrippedTextReply' => 'הנה התשובה שלנו, טופל.',
            'TextBody' => "הנה התשובה שלנו, טופל.\n\n> ההודעה המקורית של הלקוח",
        ]);

        IngestEmailMessageJob::dispatchSync($event->id);

        // Delivered to the customer as an outbound agent message — NOT recorded
        // as a new inbound customer message.
        $out = $ticket->messages()->where('direction', MessageDirection::Outbound)->sole();
        $this->assertSame(MessageAuthor::Agent, $out->author);
        $this->assertSame('הנה התשובה שלנו, טופל.', $out->body);
        $this->assertSame(0, $ticket->messages()->where('direction', MessageDirection::Inbound)->count());
        Mail::assertSent(TicketReplyMail::class);
    }

    public function test_a_non_team_email_reply_is_treated_as_a_customer_message(): void
    {
        Mail::fake();
        $ticket = $this->ticket();

        // The customer themselves reply to the tagged thread — normal inbound.
        [$event] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'cust-reply-1', [
            'MessageID' => 'cust-reply-1',
            'From' => 'client@corp.com',
            'Subject' => "Re: שאלה {$ticket->emailTag()}",
            'TextBody' => 'עוד שאלה קטנה',
        ]);

        IngestEmailMessageJob::dispatchSync($event->id);

        $this->assertSame(1, $ticket->messages()->where('direction', MessageDirection::Inbound)->count());
        $this->assertSame(0, $ticket->messages()->where('direction', MessageDirection::Outbound)->count());
        Mail::assertNotSent(TicketReplyMail::class);
    }
}
