<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Enums\WebhookSource;
use App\Jobs\IngestEmailMessageJob;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\WebhookEvent;
use App\Providers\SettingsServiceProvider;
use App\Services\Support\TicketIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SupportIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.email.webhook_secret' => 'email-secret']);
    }

    // --- Inbound email ------------------------------------------------------

    public function test_email_webhook_fails_closed_without_a_secret(): void
    {
        config(['billing.email.webhook_secret' => null]);

        $this->post('/webhooks/email', ['From' => 'a@b.com'])->assertForbidden();
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_the_inbound_secret_set_in_the_panel_is_honoured_by_the_webhook(): void
    {
        // Simulate configuring the secret from הגדרות ← דואר (stored setting,
        // overlaid onto config) with nothing in .env.
        config(['billing.email.webhook_secret' => null]);
        Setting::put('email.webhook_secret', 'panel-secret');
        (new SettingsServiceProvider(app()))->boot();

        Queue::fake([IngestEmailMessageJob::class]);
        $payload = ['MessageID' => 'm-x', 'From' => 'a@b.com', 'Subject' => 's', 'TextBody' => 'hi'];

        // Wrong/absent secret is still rejected; the configured one is accepted.
        $this->post('/webhooks/email?secret=wrong', $payload)->assertForbidden();
        $this->post('/webhooks/email?secret=panel-secret', $payload)->assertOk();

        Queue::assertPushed(IngestEmailMessageJob::class, 1);
    }

    public function test_email_webhook_is_idempotent_and_queues_ingestion(): void
    {
        Queue::fake([IngestEmailMessageJob::class]);

        $payload = ['MessageID' => 'msg-1', 'From' => 'x@y.com', 'Subject' => 'עזרה', 'TextBody' => 'שלום'];

        $this->post('/webhooks/email?secret=email-secret', $payload)->assertOk();
        $this->post('/webhooks/email?secret=email-secret', $payload)->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        Queue::assertPushed(IngestEmailMessageJob::class, 1);
    }

    public function test_inbound_email_creates_ticket_matched_to_customer(): void
    {
        $customer = Customer::factory()->create(['email' => 'client@corp.com']);

        [$event] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'msg-2', [
            'MessageID' => 'msg-2',
            'From' => 'Client Name <Client@Corp.com>',
            'Subject' => 'האתר איטי',
            'TextBody' => 'הדפים נטענים לאט',
        ]);

        IngestEmailMessageJob::dispatchSync($event->id);

        $ticket = Ticket::sole();
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertSame(TicketChannel::Email, $ticket->channel);
        $this->assertSame('האתר איטי', $ticket->subject);
        // One inbound message (an automatic acknowledgement may sit beside it).
        $this->assertSame(1, $ticket->messages()->where('direction', MessageDirection::Inbound)->count());
    }

    public function test_email_replies_thread_onto_the_same_ticket(): void
    {
        $intake = app(TicketIntake::class);

        [$first] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'm-1', [
            'MessageID' => 'm-1', 'From' => 'a@b.com', 'Subject' => 'בעיה בהתחברות', 'TextBody' => 'לא מצליח',
        ]);
        IngestEmailMessageJob::dispatchSync($first->id);

        [$reply] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'm-2', [
            'MessageID' => 'm-2', 'From' => 'a@b.com', 'Subject' => 'Re: בעיה בהתחברות', 'TextBody' => 'עדיין לא',
        ]);
        IngestEmailMessageJob::dispatchSync($reply->id);

        $this->assertSame(1, Ticket::count());
        $this->assertSame(2, Ticket::sole()->messages()->where('direction', MessageDirection::Inbound)->count());
    }

    public function test_new_email_reopens_a_resolved_ticket(): void
    {
        $intake = app(TicketIntake::class);

        [$first] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'r-1', [
            'MessageID' => 'r-1', 'From' => 'a@b.com', 'Subject' => 'שאלה', 'TextBody' => 'מה קורה',
        ]);
        IngestEmailMessageJob::dispatchSync($first->id);
        Ticket::sole()->update(['status' => TicketStatus::Resolved]);

        [$second] = WebhookEvent::record(WebhookSource::Email, 'inbound_message', 'r-2', [
            'MessageID' => 'r-2', 'From' => 'a@b.com', 'Subject' => 'Re: שאלה', 'TextBody' => 'עוד משהו',
        ]);
        IngestEmailMessageJob::dispatchSync($second->id);

        $this->assertSame(TicketStatus::Open, Ticket::sole()->status);
    }

    // --- Web form -----------------------------------------------------------

    public function test_support_form_creates_a_ticket_and_matches_customer(): void
    {
        $customer = Customer::factory()->create(['email' => 'form@user.com']);

        $this->post('/support', [
            'name' => 'טסט',
            'email' => 'form@user.com',
            'subject' => 'רוצה שדרוג',
            'message' => 'אשמח לפרטים על מסלול גבוה יותר',
        ])->assertRedirect(route('support.form'));

        $ticket = Ticket::sole();
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertSame(TicketChannel::Form, $ticket->channel);
        $this->assertSame('רוצה שדרוג', $ticket->subject);
        $this->assertSame(MessageChannel::Email, $ticket->messages()->where('direction', MessageDirection::Inbound)->sole()->channel);
    }

    public function test_support_form_rejects_a_honeypot_submission(): void
    {
        $this->post('/support', [
            'name' => 'Bot',
            'email' => 'bot@spam.com',
            'subject' => 'spam',
            'message' => 'buy now',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertSame(0, Ticket::count());
    }

    public function test_support_form_validates_required_fields(): void
    {
        $this->post('/support', ['name' => '', 'email' => 'not-an-email'])
            ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

        $this->assertSame(0, Ticket::count());
    }
}
