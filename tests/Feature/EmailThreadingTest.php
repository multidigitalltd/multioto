<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Jobs\IngestEmailMessageJob;
use App\Jobs\SendTicketReplyJob;
use App\Mail\TicketReplyMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\WebhookEvent;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailThreadingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['id' => 'stub'])]);
    }

    public function test_a_tagged_email_reply_threads_onto_the_ticket_from_any_sender(): void
    {
        // A ticket that did NOT originate from this email (e.g. opened manually),
        // already resolved.
        $ticket = Ticket::create([
            'channel' => TicketChannel::Manual,
            'subject' => 'בעיה באתר',
            'status' => TicketStatus::Resolved,
        ]);

        [$event] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'reply-1', [
            'MessageID' => 'reply-1',
            'From' => 'Someone Random <random@elsewhere.test>',
            'Subject' => "Re: בעיה באתר {$ticket->emailTag()}",
            'TextBody' => 'עדיין לא עובד',
        ]);
        IngestEmailMessageJob::dispatchSync($event->id);

        // No new ticket — the reply threaded onto the tagged one, which reopened.
        $this->assertSame(1, Ticket::count());
        $ticket->refresh();
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame(1, $ticket->messages()->where('direction', MessageDirection::Inbound)->count());
    }

    public function test_outbound_reply_subject_carries_the_ticket_tag(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create(['email' => 'client@corp.test']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'האתר איטי',
            'status' => TicketStatus::Open,
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Email,
            'body' => 'בדקנו, הכול תקין.',
            'author' => MessageAuthor::Agent,
        ]);

        (new SendTicketReplyJob($message->id))->handle(app(WahaClient::class));

        Mail::assertSent(TicketReplyMail::class, fn (TicketReplyMail $mail) => str_contains($mail->subjectLine, "[#{$ticket->id}]"));
    }
}
