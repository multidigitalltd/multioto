<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Automation\ManagementCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappManagementTest extends TestCase
{
    use RefreshDatabase;

    /** The WhatsApp management group we act on. */
    private const MGMT = '120363000000000000@g.us';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.waha.webhook_secret' => 'waha-secret',
            'billing.waha.base_url' => 'https://waha.test',
            'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default',
            'billing.waha.owner_number' => self::MGMT,
        ]);

        Http::fake(['*/api/sendText' => Http::response(['id' => 'reply-1'])]);
    }

    private function inbound(string $from, string $body, string $id = 'wa-1'): void
    {
        $this->post('/webhooks/waha?secret=waha-secret', [
            'event' => 'message',
            'payload' => ['id' => $id, 'from' => $from, 'body' => $body],
        ])->assertOk();
    }

    public function test_a_message_from_a_non_management_group_is_ignored(): void
    {
        $this->inbound('120363999999999999@g.us', 'שלום לכולם');

        $this->assertSame(0, Ticket::count());
    }

    public function test_status_and_channel_broadcasts_are_ignored(): void
    {
        $this->inbound('status@broadcast', 'my status', 'wa-status');
        $this->inbound('123456@newsletter', 'channel post', 'wa-news');

        $this->assertSame(0, Ticket::count());
    }

    public function test_the_management_group_can_open_a_ticket_by_command(): void
    {
        $customer = Customer::factory()->create(['phone' => '+972501234567']);

        $this->inbound(self::MGMT, 'כרטיס +972501234567 לבדוק את האתר');

        $ticket = Ticket::sole();
        $this->assertSame($customer->id, $ticket->customer_id);
        // The command message itself must NOT become a customer-facing message.
        $reply = Http::recorded()->last();
        $this->assertStringContainsString('נפתחה פנייה', $reply[0]->data()['text']);
    }

    public function test_opening_by_a_local_phone_number_matches_an_e164_customer(): void
    {
        // Customer stored in international form; operator types the local number.
        $customer = Customer::factory()->create(['phone' => '+972501234567']);

        $this->inbound(self::MGMT, 'כרטיס 0501234567 לבדוק את האתר');

        $this->assertSame($customer->id, Ticket::sole()->customer_id);
    }

    public function test_opening_the_same_command_twice_does_not_duplicate_the_ticket(): void
    {
        $commands = app(ManagementCommands::class);

        // A job retry replays the same WAHA message id — it must reuse the ticket.
        $commands->handle(self::MGMT, 'כרטיס +972500000000 בדיקה', 'wa-retry-1');
        $commands->handle(self::MGMT, 'כרטיס +972500000000 בדיקה', 'wa-retry-1');

        $this->assertSame(1, Ticket::count());
    }

    public function test_the_management_group_can_close_a_ticket_by_command(): void
    {
        $ticket = Ticket::create([
            'channel' => TicketChannel::Whatsapp,
            'subject' => 'בדיקה',
            'status' => TicketStatus::Open,
        ]);

        $this->inbound(self::MGMT, "סגור {$ticket->id}");

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    public function test_the_management_group_can_reply_to_a_ticket(): void
    {
        $customer = Customer::factory()->create(['whatsapp_jid' => '972501234567@c.us']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'בעיה', 'status' => TicketStatus::Open,
            'external_thread_ref' => '972501234567@c.us',
        ]);

        $this->inbound(self::MGMT, "ענה {$ticket->id} הבעיה טופלה, תודה");

        // An outbound agent message was recorded and delivered to the customer.
        $out = $ticket->messages()->where('direction', MessageDirection::Outbound)->sole();
        $this->assertSame('הבעיה טופלה, תודה', $out->body);
        $this->assertTrue(
            Http::recorded()->contains(fn ($pair) => ($pair[0]->data()['chatId'] ?? null) === '972501234567@c.us'
                && str_contains($pair[0]->data()['text'] ?? '', 'הבעיה טופלה, תודה')),
            'The reply was not delivered to the customer chat.'
        );
    }

    public function test_a_reply_to_a_missing_ticket_is_reported(): void
    {
        $this->inbound(self::MGMT, 'ענה 9999 שלום');

        $reply = Http::recorded()->last();
        $this->assertStringContainsString('לא נמצאה פנייה', $reply[0]->data()['text']);
    }

    public function test_management_chatter_never_opens_a_ticket(): void
    {
        $this->inbound(self::MGMT, 'סתם הודעה בקבוצה');

        // No ticket opened; the owner got the help menu back.
        $this->assertSame(0, Ticket::count());
        $reply = Http::recorded()->last();
        $this->assertStringContainsString('פקודות ניהול', $reply[0]->data()['text']);
    }
}
