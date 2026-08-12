<?php

namespace Tests\Feature;

use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
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
        // מי כתב מוצג כשם, והכתובת יורדת לשורת ההקשר מתחתיו.
        $this->assertSame('ישראל ישראלי', $ticket->senderName());
        $this->assertSame('israel@nowhere.test · לא מזוהה', $ticket->senderContext());
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
        $this->assertSame('משה כהן', $ticket->senderName());
        $this->assertSame('+972521234567 · לא מזוהה', $ticket->senderContext());
    }

    /**
     * לקוח מזוהה — ובכל זאת רואים מי כתב.
     *
     * קודם שם השולח נמחק ברגע שהפנייה הותאמה ללקוח, וכל פנייה נראתה כאילו
     * הגיעה מהעסק. אבל עסק אינו כותב הודעות: כותבים אותן אנשים, ולכל אחד מהם
     * עונים אחרת. שם העסק לא נעלם — הוא יורד לשורת ההקשר.
     */
    public function test_the_person_who_wrote_is_kept_alongside_the_customer(): void
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
        $this->assertSame('Someone Else', $ticket->contact_name);
        $this->assertSame('Someone Else', $ticket->senderName());
        $this->assertSame('לקוח ותיק · known@corp.test', $ticket->senderContext());
    }

    /**
     * וגם בוואטסאפ: השם של מי ששלח, לא של העסק.
     *
     * זה המקרה שדווח — חמישה אנשי קשר של אותו לקוח נראו על המסך כאותו פונה
     * אחד, כי השם היחיד שהוצג היה שם העסק.
     */
    public function test_a_whatsapp_contact_is_named_even_when_the_customer_is_known(): void
    {
        Customer::factory()->create([
            'name' => 'מספרת דוד',
            'phone' => '+972521234567',
            'whatsapp_jid' => '972521234567@c.us',
        ]);

        [$event] = WebhookEvent::record(WebhookSource::Waha, 'message', 'w-9', [
            'event' => 'message',
            'payload' => [
                'id' => 'w-9',
                'from' => '972521234567@c.us',
                'notifyName' => 'יוסי מהמשרד',
                'body' => 'האתר לא עולה',
            ],
        ]);
        IngestWhatsappMessageJob::dispatchSync($event->id);

        $ticket = Ticket::sole();

        $this->assertNotNull($ticket->customer_id);
        $this->assertSame('יוסי מהמשרד', $ticket->senderName());
        $this->assertStringContainsString('מספרת דוד', (string) $ticket->senderContext());
        // ולשורה אחת, להתראות ולהודעות לצוות.
        $this->assertStringContainsString('יוסי מהמשרד', $ticket->senderDescription());
        $this->assertStringContainsString('מספרת דוד', $ticket->senderDescription());
    }

    /** בלי שם שולח כלל — שם העסק הוא הזיהוי הטוב ביותר, ואין שורת הקשר מיותרת. */
    public function test_without_a_sender_name_the_business_name_stands_alone(): void
    {
        $customer = Customer::factory()->create(['name' => 'מספרת דוד']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Manual,
            'subject' => 'פנייה',
            'status' => TicketStatus::Open,
        ]);

        $this->assertSame('מספרת דוד', $ticket->senderName());
        $this->assertNull($ticket->senderContext());
        $this->assertSame('מספרת דוד', $ticket->senderDescription());
    }

    public function test_whatsapp_after_a_done_ticket_opens_a_new_one(): void
    {
        $send = function (string $id, string $body): void {
            [$event] = WebhookEvent::record(WebhookSource::Waha, 'message', $id, [
                'event' => 'message',
                'payload' => ['id' => $id, 'from' => '972521234567@c.us', 'notifyName' => 'משה', 'body' => $body],
            ]);
            IngestWhatsappMessageJob::dispatchSync($event->id);
        };

        // First contact opens a ticket; the team marks it handled (טופל).
        $send('w-1', 'האתר למטה');
        $first = Ticket::sole();
        $first->update(['status' => TicketStatus::Resolved]);

        // A later message from the same number is a NEW enquiry, not a revival.
        $send('w-2', 'עכשיו יש לי שאלה על חשבונית');

        $this->assertSame(2, Ticket::count());
        $this->assertSame(TicketStatus::Resolved, $first->fresh()->status);
        $this->assertSame(TicketStatus::Open, Ticket::latest('id')->first()->status);
    }
}
