<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketNotificationJob;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Notifications\TemplateEngine;
use App\Services\Support\TicketIntake;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class NotificationTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_substitutes_placeholders_and_respects_overrides(): void
    {
        $engine = app(TemplateEngine::class);

        // Built-in default renders with data substituted.
        $rendered = $engine->render('ticket.received', 'email', [
            'customer_name' => 'ישראל', 'ticket_id' => 7, 'ticket_subject' => 'האתר איטי', 'business_name' => 'מולטי דיגיטל',
        ]);
        $this->assertStringContainsString('ישראל', $rendered['body']);
        $this->assertStringContainsString('#7', $rendered['subject']);

        // An operator-edited row overrides the default.
        NotificationTemplate::create(['key' => 'ticket.received', 'channel' => 'email', 'subject' => 'קיבלנו!', 'body' => 'היי {{customer_name}}']);
        $this->assertSame('היי ישראל', $engine->render('ticket.received', 'email', ['customer_name' => 'ישראל'])['body']);

        // Disabling a row silences the notification.
        NotificationTemplate::where('key', 'ticket.received')->update(['enabled' => false]);
        $this->assertNull($engine->render('ticket.received', 'email', []));
    }

    public function test_new_ticket_gets_an_acknowledgement_but_a_follow_up_message_does_not(): void
    {
        Queue::fake([SendTicketNotificationJob::class]);
        $customer = Customer::factory()->create();
        $intake = app(TicketIntake::class);

        $intake->recordInbound(TicketChannel::Whatsapp, MessageChannel::Whatsapp, $customer, 'האתר נפל', threadRef: '9725@c.us', externalMessageId: 'm1');
        Queue::assertPushed(SendTicketNotificationJob::class, fn ($job) => $job->templateKey === 'ticket.received');

        // Second message on the same thread — no second acknowledgement.
        $intake->recordInbound(TicketChannel::Whatsapp, MessageChannel::Whatsapp, $customer, 'עדכון', threadRef: '9725@c.us', externalMessageId: 'm2');
        Queue::assertPushed(SendTicketNotificationJob::class, 1);
    }

    public function test_whatsapp_ticket_ack_is_sent_via_waha_and_recorded_as_system_message(): void
    {
        config(['billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default']);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'wa-1'])]);

        $customer = Customer::factory()->create(['name' => 'ישראל כהן']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'תקלה', 'status' => TicketStatus::Open, 'external_thread_ref' => '972501234567@c.us',
        ]);

        (new SendTicketNotificationJob($ticket->id, 'ticket.received'))->handle(app(TemplateEngine::class), app(WahaClient::class), app(ClaudeClient::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendText')
            && str_contains($request->data()['text'], 'ישראל כהן'));
        $this->assertSame(1, $ticket->messages()->count());

        // Running again (retry / duplicate dispatch) must not send twice.
        (new SendTicketNotificationJob($ticket->id, 'ticket.received'))->handle(app(TemplateEngine::class), app(WahaClient::class), app(ClaudeClient::class));
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_an_automatic_ack_is_held_over_shabbat_and_resent_after(): void
    {
        config([
            'billing.shabbat.block_automations' => true,
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-07-18 12:00', 'Asia/Jerusalem')); // Shabbat
        Http::fake(['*/api/sendText' => Http::response(['id' => 'wa-1'])]);
        Queue::fake([SendTicketNotificationJob::class]);

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'תקלה', 'status' => TicketStatus::Open, 'external_thread_ref' => '972501234567@c.us',
        ]);

        (new SendTicketNotificationJob($ticket->id, 'ticket.received'))->handle(app(TemplateEngine::class), app(WahaClient::class), app(ClaudeClient::class));

        // Nothing was sent to the customer on Shabbat; the job was re-queued for later.
        Http::assertNothingSent();
        Queue::assertPushed(SendTicketNotificationJob::class, 1);
        $this->assertSame(0, $ticket->messages()->count());

        Carbon::setTestNow();
    }

    public function test_dynamic_ack_uses_ai_written_text_with_the_ticket_number(): void
    {
        config([
            'billing.ai.enabled' => true,
            'billing.ai.dynamic_ack' => true,
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default',
        ]);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'wa-1'])]);

        $customer = Customer::factory()->create(['name' => 'דנה']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'האתר איטי', 'status' => TicketStatus::Open, 'external_thread_ref' => '972501234567@c.us',
        ]);
        $ticket->messages()->create(['direction' => MessageDirection::Inbound, 'channel' => MessageChannel::Whatsapp, 'body' => 'האתר שלי איטי מאוד']);

        // The AI writes a bespoke ack (without the number); the job guarantees
        // the ticket number is appended. The customer's actual message must be
        // fed into the prompt so the ack can reference the specific issue.
        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->once()
            ->with(Mockery::any(), Mockery::on(fn (string $prompt): bool => str_contains($prompt, 'האתר שלי איטי מאוד')), Mockery::any())
            ->andReturn(['message' => 'היי דנה, קיבלנו את פנייתך ואנחנו כבר על זה.']);

        (new SendTicketNotificationJob($ticket->id, 'ticket.received'))->handle(app(TemplateEngine::class), app(WahaClient::class), $ai);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendText')
            && str_contains($request->data()['text'], 'קיבלנו את פנייתך')
            && str_contains($request->data()['text'], (string) $ticket->id));
    }

    public function test_a_disabled_template_opts_out_even_in_dynamic_ack_mode(): void
    {
        config([
            'billing.ai.enabled' => true, 'billing.ai.dynamic_ack' => true,
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default',
        ]);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'wa-1'])]);
        // The operator disabled the received-ack template — the opt-out.
        NotificationTemplate::create(['key' => 'ticket.received', 'channel' => 'whatsapp', 'body' => 'x', 'enabled' => false]);

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'תקלה', 'status' => TicketStatus::Open, 'external_thread_ref' => '972501234567@c.us',
        ]);

        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldNotReceive('structured'); // disabled → never compose/send

        (new SendTicketNotificationJob($ticket->id, 'ticket.received'))->handle(app(TemplateEngine::class), app(WahaClient::class), $ai);

        Http::assertNothingSent();
        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_dynamic_ack_falls_back_to_the_template_when_the_ai_is_off(): void
    {
        config([
            'billing.ai.dynamic_ack' => true, // on, but the AI itself is disabled
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k', 'billing.waha.session' => 'default',
        ]);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'wa-1'])]);

        $customer = Customer::factory()->create(['name' => 'רון']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'תקלה', 'status' => TicketStatus::Open, 'external_thread_ref' => '972501234567@c.us',
        ]);

        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(false);
        $ai->shouldNotReceive('structured');

        (new SendTicketNotificationJob($ticket->id, 'ticket.received'))->handle(app(TemplateEngine::class), app(WahaClient::class), $ai);

        // The template ack was still sent (the customer name comes from ticketData).
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendText'));
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_resolving_a_ticket_emails_the_customer(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'בעיה במייל', 'status' => TicketStatus::Open,
        ]);

        $ticket->update(['status' => TicketStatus::Resolved]);
        // The observer dispatches the job; on the sync queue it already ran.
        Mail::assertSent(NotificationMail::class, fn ($mail) => str_contains($mail->bodyText, 'הושלם'));
    }

    public function test_the_closing_notice_is_ai_written_when_dynamic_messages_are_on(): void
    {
        Mail::fake();
        config(['billing.ai.enabled' => true, 'billing.ai.dynamic_ack' => true]);

        $customer = Customer::factory()->create(['name' => 'נועה', 'email' => 'noa@test.co']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'תקלה', 'status' => TicketStatus::Open,
        ]);

        $ai = Mockery::mock(ClaudeClient::class);
        $ai->shouldReceive('isEnabled')->andReturn(true);
        $ai->shouldReceive('structured')->once()->andReturn(['message' => 'נועה, הפנייה טופלה ונסגרה. תודה שפנית!']);

        (new SendTicketNotificationJob($ticket->id, 'ticket.resolved'))->handle(app(TemplateEngine::class), app(WahaClient::class), $ai);

        Mail::assertSent(NotificationMail::class, fn ($mail): bool => str_contains($mail->bodyText, 'טופלה ונסגרה')
            && str_contains($mail->subjectLine, (string) $ticket->id));
    }

    public function test_team_gets_an_email_copy_when_the_copy_toggle_is_on(): void
    {
        Mail::fake();
        config([
            'billing.notifications.copy_customer_messages' => true,
            'billing.notifications.team_email' => 'team@multi.co',
        ]);

        $customer = Customer::factory()->create(['name' => 'איתי', 'email' => 'itay@test.co']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Email,
            'subject' => 'שאלה', 'status' => TicketStatus::Open,
        ]);

        (new SendTicketNotificationJob($ticket->id, 'ticket.received'))->handle(app(TemplateEngine::class), app(WahaClient::class), app(ClaudeClient::class));

        // The customer got the ack, and the team got a copy labelled as such.
        Mail::assertSent(NotificationMail::class, fn ($mail): bool => $mail->hasTo('itay@test.co'));
        Mail::assertSent(NotificationMail::class, fn ($mail): bool => $mail->hasTo('team@multi.co')
            && str_contains($mail->subjectLine, 'העתק')
            && str_contains($mail->subjectLine, 'איתי'));
    }
}
