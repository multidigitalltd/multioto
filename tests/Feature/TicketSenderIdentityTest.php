<?php

namespace Tests\Feature;

use App\Enums\WebhookSource;
use App\Jobs\IngestEmailMessageJob;
use App\Jobs\IngestWhatsappMessageJob;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketSenderIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Stub any outbound WAHA call (the auto-acknowledgement) so the sync
        // ingest chain doesn't hit the network.
        Http::fake(['*' => Http::response(['id' => 'stub'])]);
    }

    public function test_unidentified_email_keeps_the_sender_name_and_address(): void
    {
        [$event] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'e-1', [
            'MessageID' => 'e-1',
            'From' => 'ישראל ישראלי <israel@nowhere.test>',
            'Subject' => 'שאלה כללית',
            'TextBody' => 'שלום, יש לי שאלה',
        ]);
        IngestEmailMessageJob::dispatchSync($event->id);

        $ticket = Ticket::sole();
        $this->assertNull($ticket->customer_id);
        $this->assertSame('ישראל ישראלי', $ticket->contact_name);
        $this->assertSame('israel@nowhere.test', $ticket->contact_handle);
        // The "from" shown to the team combines both.
        $this->assertSame('ישראל ישראלי · israel@nowhere.test', $ticket->senderName());
    }

    public function test_unidentified_whatsapp_keeps_the_pushname_and_phone(): void
    {

        [$event] = WebhookEvent::record(WebhookSource::Waha, 'message', 'w-1', [
            'event' => 'message',
            'payload' => [
                'id' => 'w-1',
                'from' => '972521234567@c.us',
                'notifyName' => 'משה כהן',
                'body' => 'היי, האתר למטה',
            ],
        ]);
        IngestWhatsappMessageJob::dispatchSync($event->id);

        $ticket = Ticket::sole();
        $this->assertNull($ticket->customer_id);
        $this->assertSame('משה כהן', $ticket->contact_name);
        $this->assertSame('+972521234567', $ticket->contact_handle);
        $this->assertSame('משה כהן · +972521234567', $ticket->senderName());
    }

    public function test_a_matched_customer_wins_over_the_captured_identity(): void
    {
        Customer::factory()->create(['name' => 'לקוח ותיק', 'email' => 'known@corp.test']);

        [$event] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'e-2', [
            'MessageID' => 'e-2',
            'From' => 'Someone Else <known@corp.test>',
            'Subject' => 'עדכון',
            'TextBody' => 'שלום',
        ]);
        IngestEmailMessageJob::dispatchSync($event->id);

        $ticket = Ticket::sole();
        $this->assertNotNull($ticket->customer_id);
        $this->assertNull($ticket->contact_name); // not stored for a matched customer
        $this->assertSame('לקוח ותיק', $ticket->senderName());
    }
}
