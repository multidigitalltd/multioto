<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TeamPushNotification;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A new ticket sends a browser (Web Push) alert to subscribed team members —
 * but only when Web Push is configured (VAPID keys) and the member subscribed.
 */
class TeamPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No team WhatsApp/email so the alert side-channels stay quiet in the test.
        config(['billing.waha.owner_number' => '', 'billing.notifications.team_email' => '']);
        Http::fake();
    }

    private function makeTicket(): Ticket
    {
        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Whatsapp,
            'subject' => 'בדיקה',
            'status' => TicketStatus::Open,
        ]);
        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Whatsapp,
            'body' => 'שלום',
            'author' => MessageAuthor::Customer,
        ]);

        return $ticket;
    }

    public function test_it_pushes_to_a_subscribed_member_when_configured(): void
    {
        config(['webpush.vapid.public_key' => 'pub', 'webpush.vapid.private_key' => 'priv']);
        Notification::fake();

        $subscribed = User::factory()->create();
        $subscribed->updatePushSubscription('https://push.example.com/a', 'k', 'a');
        $notSubscribed = User::factory()->create();

        app(TeamNotifier::class)->newTicket($this->makeTicket());

        Notification::assertSentTo($subscribed, TeamPushNotification::class);
        Notification::assertNotSentTo($notSubscribed, TeamPushNotification::class);
    }

    public function test_it_does_not_push_when_web_push_is_not_configured(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        Notification::fake();

        $subscribed = User::factory()->create();
        $subscribed->updatePushSubscription('https://push.example.com/b', 'k', 'a');

        app(TeamNotifier::class)->newTicket($this->makeTicket());

        // The in-panel bell still fires; only the browser push is gated off.
        Notification::assertNotSentTo($subscribed, TeamPushNotification::class);
    }
}
