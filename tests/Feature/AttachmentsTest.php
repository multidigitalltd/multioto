<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Jobs\IngestEmailMessageJob;
use App\Jobs\IngestWhatsappMessageJob;
use App\Jobs\SendTicketReplyJob;
use App\Mail\TicketReplyMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Support\AttachmentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AttachmentsTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG so finfo reports image/png. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function test_attachment_store_keeps_an_allowed_image_and_rejects_other_types(): void
    {
        Storage::fake('local');
        $store = app(AttachmentStore::class);

        $ok = $store->store(1, 'photo.png', base64_decode(self::PNG), 'image/png');
        $this->assertNotNull($ok);
        $this->assertSame('image/png', $ok['mime']);
        Storage::disk('local')->assertExists($ok['path']);

        // A PHP payload (even with an image name) is sniffed as non-image and dropped.
        $this->assertNull($store->store(1, 'shell.png', "<?php echo 'x'; ?>", 'image/png'));
    }

    public function test_the_stored_extension_comes_from_the_mime_not_the_filename(): void
    {
        Storage::fake('local');

        $meta = app(AttachmentStore::class)->store(1, 'invoice.php', base64_decode(self::PNG), 'image/png');

        $this->assertNotNull($meta);
        $this->assertStringEndsWith('.png', $meta['path']);   // never .php
        $this->assertStringEndsWith('.png', $meta['name']);
    }

    public function test_a_csv_is_stored_as_csv_not_txt(): void
    {
        Storage::fake('local');

        // Real CSV bytes — however libmagic sniffs them (text/csv or text/plain),
        // the file must be stored as ".csv", not the ".txt" the customer reported.
        $meta = app(AttachmentStore::class)->store(1, 'report.csv', "name,amount\nDana,100\n", 'text/csv');

        $this->assertNotNull($meta);
        $this->assertStringEndsWith('.csv', $meta['path']);
        $this->assertStringEndsWith('.csv', $meta['name']);
    }

    public function test_a_plain_text_sniff_keeps_a_safe_sender_extension(): void
    {
        Storage::fake('local');

        // Bytes that sniff as text/plain but arrived as ".csv" keep the ".csv"
        // (the exact case the customer hit — the widening allow-list).
        $meta = app(AttachmentStore::class)->store(1, 'notes.csv', "hello world\n", 'text/plain');

        $this->assertNotNull($meta);
        $this->assertSame('text/plain', $meta['mime']);
        $this->assertStringEndsWith('.csv', $meta['path']);
        $this->assertStringEndsWith('.csv', $meta['name']);
    }

    public function test_a_plain_text_file_with_a_dangerous_extension_falls_back_to_txt(): void
    {
        Storage::fake('local');

        // Allowed content (text/plain) but a non-safe extension → stored as .txt,
        // never .php. The text-extension widening is a fixed allow-list.
        $meta = app(AttachmentStore::class)->store(1, 'shell.php', "just some text\n", 'text/plain');

        $this->assertNotNull($meta);
        $this->assertStringEndsWith('.txt', $meta['path']);
        $this->assertStringEndsWith('.txt', $meta['name']);
    }

    public function test_inbound_email_attachment_is_decoded_and_stored(): void
    {
        Storage::fake('local');
        $customer = Customer::factory()->create(['email' => 'lead@example.com']);

        [$event] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'mail-att-1', [
            'From' => 'lead@example.com',
            'Subject' => 'קובץ מצורף',
            'TextBody' => 'צירפתי צילום מסך',
            'Attachments' => [[
                'Name' => 'screenshot.png',
                'Content' => self::PNG,
                'ContentType' => 'image/png',
                'ContentLength' => strlen(base64_decode(self::PNG)),
            ]],
        ]);

        IngestEmailMessageJob::dispatchSync($event->id);

        // The customer's inbound message carries the attachment (a later
        // outbound auto-acknowledgement must not be picked up instead).
        $message = Ticket::where('customer_id', $customer->id)->firstOrFail()
            ->messages()->where('direction', MessageDirection::Inbound)->first();

        $this->assertCount(1, $message->attachments);
        $this->assertSame('image/png', $message->attachments[0]['mime']);
        Storage::disk('local')->assertExists($message->attachments[0]['path']);
    }

    public function test_inbound_whatsapp_media_is_downloaded_and_stored(): void
    {
        Storage::fake('local');
        config(['billing.waha.api_key' => 'k']);

        Http::fake([
            'https://waha.test/media/abc.png' => Http::response(base64_decode(self::PNG), 200),
            '*' => Http::response('', 200), // swallow the auto-ack sendText
        ]);

        [$event] = WebhookEvent::record(WebhookSource::Waha, 'message', 'wa-att-1', [
            'payload' => [
                'id' => 'wa-att-1',
                'from' => '972501234567@c.us',
                'body' => '',
                'hasMedia' => true,
                'media' => ['url' => 'https://waha.test/media/abc.png', 'mimetype' => 'image/png', 'filename' => 'pic.png'],
            ],
        ]);

        IngestWhatsappMessageJob::dispatchSync($event->id);

        $message = Ticket::query()->firstOrFail()
            ->messages()->where('direction', MessageDirection::Inbound)->first();
        $this->assertCount(1, $message->attachments);
        $this->assertSame('image/png', $message->attachments[0]['mime']);
        Storage::disk('local')->assertExists($message->attachments[0]['path']);
    }

    public function test_the_attachment_route_streams_the_file_to_a_signed_in_user(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $path = 'attachments/1/test.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG));

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'x', 'status' => TicketStatus::Open,
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Email,
            'body' => 'x', 'author' => MessageAuthor::Customer,
            'attachments' => [['name' => 'test.png', 'mime' => 'image/png', 'size' => 68, 'path' => $path, 'disk' => 'local']],
        ]);

        $this->get(route('support.attachment', ['message' => $message->id, 'index' => 0]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Out-of-range index is a 404, not a leak.
        $this->get(route('support.attachment', ['message' => $message->id, 'index' => 5]))
            ->assertNotFound();
    }

    public function test_an_email_reply_delivers_its_attachment(): void
    {
        Storage::fake('local');
        Mail::fake();
        $path = 'attachments/1/reply.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG));

        $customer = Customer::factory()->create(['email' => 'lead@example.com']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'x', 'status' => TicketStatus::Open,
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email,
            'body' => 'מצורף', 'author' => MessageAuthor::Agent,
            'attachments' => [['name' => 'reply.png', 'mime' => 'image/png', 'size' => 68, 'path' => $path, 'disk' => 'local']],
        ]);

        SendTicketReplyJob::dispatchSync($message->id);

        Mail::assertSent(TicketReplyMail::class, function (TicketReplyMail $mail) use ($path): bool {
            return count($mail->files) === 1
                && $mail->files[0]['path'] === $path
                && count($mail->attachments()) === 1; // the file is actually attached
        });
    }

    public function test_a_whatsapp_reply_sends_an_image_via_the_image_endpoint(): void
    {
        Storage::fake('local');
        config(['billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default']);
        $path = 'attachments/1/reply.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG));

        Http::fake([
            '*/api/sendImage' => Http::response(['id' => 'f1']),
            '*' => Http::response(['id' => 't1']),
        ]);

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'x', 'status' => TicketStatus::Open, 'external_thread_ref' => '972501234567@c.us',
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp,
            'body' => 'הנה הקובץ', 'author' => MessageAuthor::Agent,
            'attachments' => [['name' => 'reply.png', 'mime' => 'image/png', 'size' => 68, 'path' => $path, 'disk' => 'local']],
        ]);

        SendTicketReplyJob::dispatchSync($message->id);

        // An image goes through /api/sendImage (not /api/sendFile), so it renders
        // inline on WhatsApp instead of failing/arriving as a broken document.
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendImage')
            && ($request->data()['file']['data'] ?? '') === self::PNG
            && ($request->data()['file']['filename'] ?? '') === 'reply.png');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'sendFile'));
    }

    public function test_a_whatsapp_reply_sends_a_document_via_the_file_endpoint(): void
    {
        Storage::fake('local');
        config(['billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default']);
        $path = 'attachments/1/report.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 fake');

        Http::fake([
            '*/api/sendFile' => Http::response(['id' => 'f1']),
            '*' => Http::response(['id' => 't1']),
        ]);

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'x', 'status' => TicketStatus::Open, 'external_thread_ref' => '972501234567@c.us',
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Whatsapp,
            'body' => 'הנה הקובץ', 'author' => MessageAuthor::Agent,
            'attachments' => [['name' => 'report.pdf', 'mime' => 'application/pdf', 'size' => 13, 'path' => $path, 'disk' => 'local']],
        ]);

        SendTicketReplyJob::dispatchSync($message->id);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendFile')
            && ($request->data()['file']['filename'] ?? '') === 'report.pdf');
    }

    public function test_agent_can_attach_a_file_to_a_reply_from_the_chat(): void
    {
        Storage::fake('local');
        Queue::fake([SendTicketReplyJob::class]);
        $this->actingAs(User::factory()->create());

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'x', 'status' => TicketStatus::Open,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->set('replyData.body', '<p>מצרף צילום מסך</p>')
            ->set('replyFiles', [UploadedFile::fake()->image('screenshot.jpg')])
            ->call('sendReply');

        $message = $ticket->messages()->where('direction', MessageDirection::Outbound)->sole();
        $this->assertCount(1, $message->attachments);
        $this->assertStringStartsWith('image/', $message->attachments[0]['mime']);
        Storage::disk('local')->assertExists($message->attachments[0]['path']);
        Queue::assertPushed(SendTicketReplyJob::class);
    }

    public function test_the_attachment_route_is_closed_to_guests(): void
    {
        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'x', 'status' => TicketStatus::Open,
        ]);
        $message = $ticket->messages()->create([
            'direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Email,
            'body' => 'x', 'author' => MessageAuthor::Customer,
            'attachments' => [['name' => 'test.png', 'mime' => 'image/png', 'size' => 1, 'path' => 'x', 'disk' => 'local']],
        ]);

        $this->get(route('support.attachment', ['message' => $message->id, 'index' => 0]))
            ->assertStatus(302); // redirected to login, never served
    }
}
