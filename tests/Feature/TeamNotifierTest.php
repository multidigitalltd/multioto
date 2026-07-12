<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\NotifyTeamJob;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Notifications\TeamNotifier;
use App\Services\Support\TicketIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TeamNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_ticket_queues_a_team_alert_regardless_of_ai(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create();

        app(TicketIntake::class)->recordInbound(
            TicketChannel::Whatsapp, MessageChannel::Whatsapp, $customer,
            'האתר שלי נפל', threadRef: '9725@c.us', externalMessageId: 'm1',
        );

        Queue::assertPushed(NotifyTeamJob::class, fn ($job) => $job->event === 'new_ticket');
    }

    public function test_a_customer_reply_queues_a_reply_alert(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create();
        $intake = app(TicketIntake::class);

        $intake->recordInbound(TicketChannel::Whatsapp, MessageChannel::Whatsapp, $customer, 'ראשונה', threadRef: '9725@c.us', externalMessageId: 'm1');
        $intake->recordInbound(TicketChannel::Whatsapp, MessageChannel::Whatsapp, $customer, 'שנייה', threadRef: '9725@c.us', externalMessageId: 'm2');

        Queue::assertPushed(NotifyTeamJob::class, fn ($job) => $job->event === 'new_ticket');
        Queue::assertPushed(NotifyTeamJob::class, fn ($job) => $job->event === 'new_reply');
    }

    public function test_team_notifier_alerts_both_whatsapp_and_email(): void
    {
        config([
            'billing.waha.base_url' => 'https://waha.test', 'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default', 'billing.waha.owner_number' => '972500000000@g.us',
            'billing.notifications.team_email' => 'team@multidigital.co.il',
            'app.url' => 'https://app.multidigital.co.il',
        ]);
        Mail::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'w'])]);

        $customer = Customer::factory()->create(['name' => 'עסק כהן']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id, 'channel' => TicketChannel::Whatsapp,
            'subject' => 'תקלה באתר', 'status' => TicketStatus::Open,
        ]);

        app(TeamNotifier::class)->newTicket($ticket);

        // WhatsApp to the approvals group…
        Http::assertSent(fn ($request) => $request->data()['chatId'] === '972500000000@g.us'
            && str_contains($request->data()['text'], 'פנייה חדשה')
            && str_contains($request->data()['text'], 'עסק כהן'));
        // …and email to the team.
        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo('team@multidigital.co.il'));
    }
}
