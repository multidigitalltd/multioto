<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A WhatsApp contact card (vCard) carries no text body, so it used to be dropped
 * on the way in. It must instead land in the conversation as a readable line —
 * the shared contact's name and phone.
 */
class WhatsappContactCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.waha.webhook_secret' => 'waha-secret']);
        // A new ticket triggers the automatic WhatsApp acknowledgement.
        Http::fake(['*/api/sendText' => Http::response(['id' => 'ack'])]);
    }

    public function test_a_shared_contact_card_becomes_a_readable_message(): void
    {
        $customer = Customer::factory()->create(['phone' => '+972501234567', 'whatsapp_jid' => null]);

        $vcard = "BEGIN:VCARD\nVERSION:3.0\nN:;דנה כהן;;;\nFN:דנה כהן\n"
            ."TEL;type=CELL;waid=972529998877:+972 52-999-8877\nEND:VCARD";

        $this->post('/webhooks/waha?secret=waha-secret', [
            'event' => 'message',
            'payload' => [
                'id' => 'wa-vcard-1',
                'from' => '972501234567@c.us',
                'body' => '',
                'vcard' => $vcard,
            ],
        ])->assertOk();

        $ticket = Ticket::sole();
        $this->assertSame($customer->id, $ticket->customer_id);

        $message = $ticket->messages()->where('direction', MessageDirection::Inbound)->sole();
        $this->assertStringContainsString('דנה כהן', $message->body);
        $this->assertStringContainsString('+972529998877', $message->body);
        $this->assertStringContainsString('איש קשר', $message->body);
    }

    public function test_the_same_card_repeated_across_fields_is_summarised_once(): void
    {
        Customer::factory()->create(['phone' => '+972501234567', 'whatsapp_jid' => null]);

        // WAHA often repeats the identical vCard in more than one field.
        $vcard = "BEGIN:VCARD\nVERSION:3.0\nFN:רון מזרחי\nTEL;waid=972521112233:+972521112233\nEND:VCARD";

        $this->post('/webhooks/waha?secret=waha-secret', [
            'event' => 'message',
            'payload' => [
                'id' => 'wa-vcard-dup',
                'from' => '972501234567@c.us',
                'body' => $vcard,
                'vcard' => $vcard,
                'vCards' => [$vcard],
            ],
        ])->assertOk();

        $body = Ticket::sole()->messages()->where('direction', MessageDirection::Inbound)->sole()->body;

        $this->assertSame(1, substr_count($body, 'רון מזרחי'), 'the contact should appear exactly once');
        // A single shared contact uses the singular heading, not the plural one.
        $this->assertStringContainsString('איש קשר שהתקבל:', $body);
        $this->assertStringNotContainsString('אנשי קשר שהתקבלו', $body);
    }

    public function test_multiple_contact_cards_are_all_listed(): void
    {
        Customer::factory()->create(['phone' => '+972501234567', 'whatsapp_jid' => null]);

        $card = fn (string $name, string $waid): string => "BEGIN:VCARD\nVERSION:3.0\nFN:{$name}\n"
            ."TEL;type=CELL;waid={$waid}:+{$waid}\nEND:VCARD";

        $this->post('/webhooks/waha?secret=waha-secret', [
            'event' => 'message',
            'payload' => [
                'id' => 'wa-vcard-2',
                'from' => '972501234567@c.us',
                'body' => '',
                'vCards' => [$card('אבי לוי', '972500000001'), $card('נועה בר', '972500000002')],
            ],
        ])->assertOk();

        $message = Ticket::sole()->messages()->where('direction', MessageDirection::Inbound)->sole();
        $this->assertStringContainsString('אבי לוי', $message->body);
        $this->assertStringContainsString('נועה בר', $message->body);
        $this->assertStringContainsString('אנשי קשר', $message->body);
    }
}
