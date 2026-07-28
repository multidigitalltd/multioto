<?php

namespace Tests\Feature;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use App\Enums\CustomerStatus;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Filament\Resources\BroadcastResource\Pages\ListBroadcasts;
use App\Models\Broadcast;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Notifications\DeliveryEvents;
use App\Services\Support\BroadcastAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What happened to an email after we handed it over.
 *
 * Everything here is reported by the provider — nothing is inferred. The two
 * facts worth protecting: a stranger cannot post events at us, and a bounce
 * actually stops us mailing a dead address again.
 */
class EmailDeliveryTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'delivery-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.email.webhook_secret' => self::SECRET]);
    }

    private function log(array $attributes = []): NotificationLog
    {
        return NotificationLog::create(array_merge([
            'channel' => 'email',
            'type' => NotificationType::Broadcast,
            'recipient' => 'dani@b.co.il',
            'subject' => 'עדכון',
            'status' => 'queued',
            'sent_at' => now(),
            'provider_message_id' => 'pm-1',
        ], $attributes));
    }

    private function event(array $payload, ?string $secret = self::SECRET)
    {
        return $this->postJson('/webhooks/email/delivery?secret='.urlencode((string) $secret), $payload);
    }

    /*
    | ----------------------------------------------------------------
    | The gate
    | ----------------------------------------------------------------
    */

    public function test_an_event_without_the_secret_is_refused(): void
    {
        $log = $this->log();

        $this->event(['RecordType' => 'Delivery', 'MessageID' => 'pm-1'], 'wrong')
            ->assertForbidden();

        $this->assertNull($log->fresh()->delivered_at);
    }

    public function test_no_configured_secret_means_nothing_is_accepted(): void
    {
        config(['billing.email.webhook_secret' => '']);

        // Fail closed: a blank secret must never read as "accept everything".
        $this->event(['RecordType' => 'Delivery', 'MessageID' => 'pm-1'], '')->assertForbidden();
    }

    /*
    | ----------------------------------------------------------------
    | Recording what happened
    | ----------------------------------------------------------------
    */

    public function test_a_delivery_event_marks_the_message_delivered(): void
    {
        $log = $this->log();

        $this->event([
            'RecordType' => 'Delivery',
            'MessageID' => 'pm-1',
            'Recipient' => 'dani@b.co.il',
            'DeliveredAt' => '2026-07-28T09:00:00Z',
        ])->assertOk();

        $log->refresh();

        $this->assertNotNull($log->delivered_at);
        $this->assertSame('sent', $log->status);
    }

    public function test_an_open_records_the_first_read_and_counts_the_rest(): void
    {
        $log = $this->log();

        foreach (['10:00:00', '12:00:00', '15:00:00'] as $time) {
            $this->event([
                'RecordType' => 'Open', 'MessageID' => 'pm-1',
                'ReceivedAt' => "2026-07-28T{$time}Z",
            ])->assertOk();
        }

        $log->refresh();

        // Forwarding a mail around must not rewrite when it was first read.
        $this->assertTrue($log->opened_at->equalTo(Carbon::parse('2026-07-28T10:00:00Z')));
        $this->assertSame(3, $log->open_count);

        // An open proves delivery even when that event never arrived.
        $this->assertNotNull($log->delivered_at);
    }

    public function test_an_open_without_a_delivery_event_stops_showing_the_mail_as_queued(): void
    {
        $log = $this->log();

        $this->event(['RecordType' => 'Open', 'MessageID' => 'pm-1'])->assertOk();

        // Open tracking can be the only signal enabled. A mail proven to have
        // been read must not sit in the history as "בתור" forever.
        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_an_open_after_a_bounce_does_not_undo_the_failure(): void
    {
        $log = $this->log(['status' => 'failed', 'bounced_at' => now()]);

        $this->event(['RecordType' => 'Open', 'MessageID' => 'pm-1'])->assertOk();

        $this->assertSame('failed', $log->fresh()->status);
    }

    public function test_the_exact_same_open_replayed_is_not_counted_again(): void
    {
        $log = $this->log();

        $payload = ['RecordType' => 'Open', 'MessageID' => 'pm-1', 'ReceivedAt' => '2026-07-28T10:00:00Z'];

        // A provider replays deliveries. A genuine second read differs by its
        // timestamp; an identical payload is the same read arriving twice.
        $this->event($payload)->assertOk();
        $this->event($payload)->assertOk();

        $this->assertSame(1, $log->fresh()->open_count);
    }

    public function test_the_same_delivery_event_replayed_is_recorded_once(): void
    {
        $log = $this->log();

        $payload = ['RecordType' => 'Delivery', 'MessageID' => 'pm-1'];

        $this->event($payload)->assertOk();
        $this->event($payload)->assertOk();

        // Providers replay: idempotency comes from webhook_events.
        $this->assertNotNull($log->fresh()->delivered_at);
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_an_event_that_failed_halfway_is_finished_by_the_providers_retry(): void
    {
        $log = $this->log();

        $flaky = new class extends DeliveryEvents
        {
            public int $calls = 0;

            public function apply(array $payload): bool
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new \RuntimeException('transient database error');
                }

                return parent::apply($payload);
            }
        };

        $this->instance(DeliveryEvents::class, $flaky);

        $payload = ['RecordType' => 'Delivery', 'MessageID' => 'pm-1'];

        try {
            $this->event($payload);
        } catch (\Throwable) {
            // The 500 is the point: the provider will resend.
        }

        $this->assertNull($log->fresh()->delivered_at);

        // Having RECORDED the event is not the same as having PROCESSED it —
        // otherwise the retry would find the row and drop the delivery for good.
        $this->event($payload)->assertOk();

        $this->assertNotNull($log->fresh()->delivered_at);
        $this->assertSame(1, WebhookEvent::count());
    }

    public function test_a_delivery_and_an_open_for_one_message_are_two_events_not_a_duplicate(): void
    {
        $log = $this->log();

        $this->event(['RecordType' => 'Delivery', 'MessageID' => 'pm-1'])->assertOk();
        $this->event(['RecordType' => 'Open', 'MessageID' => 'pm-1'])->assertOk();

        // Keying only on MessageID would let the Delivery swallow the Open.
        $this->assertSame(2, WebhookEvent::count());
        $this->assertSame(1, $log->fresh()->open_count);
    }

    /*
    | ----------------------------------------------------------------
    | Bounces
    | ----------------------------------------------------------------
    */

    public function test_a_hard_bounce_stops_us_mailing_that_address_again(): void
    {
        $customer = Customer::factory()->create([
            'status' => CustomerStatus::Active, 'email' => 'dani@b.co.il',
        ]);
        $log = $this->log(['customer_id' => $customer->id]);

        $this->event([
            'RecordType' => 'Bounce',
            'MessageID' => 'pm-1',
            'Type' => 'HardBounce',
            'Description' => 'The address does not exist',
            'Inactive' => true,
        ])->assertOk();

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertTrue($customer->fresh()->emailHasBounced());

        // The real payoff: the address leaves the audience.
        $this->assertSame(0, app(BroadcastAudience::class)
            ->reachable(BroadcastChannel::Email, null)->count());
    }

    public function test_a_soft_bounce_does_not_retire_the_address(): void
    {
        $customer = Customer::factory()->create([
            'status' => CustomerStatus::Active, 'email' => 'dani@b.co.il',
        ]);
        $this->log(['customer_id' => $customer->id]);

        // A full mailbox is not a reason to stop writing to a customer.
        $this->event([
            'RecordType' => 'Bounce', 'MessageID' => 'pm-1',
            'Type' => 'SoftBounce', 'Description' => 'Mailbox full',
        ])->assertOk();

        $this->assertFalse($customer->fresh()->emailHasBounced());
        $this->assertSame(1, app(BroadcastAudience::class)
            ->reachable(BroadcastChannel::Email, null)->count());
    }

    public function test_a_late_bounce_for_an_address_already_corrected_leaves_the_new_one_alone(): void
    {
        $customer = Customer::factory()->create([
            'status' => CustomerStatus::Active, 'email' => 'typo@b.co.il',
        ]);
        $log = $this->log(['customer_id' => $customer->id, 'recipient' => 'typo@b.co.il']);

        // Fixing the typo is exactly what someone does while the old address is
        // still bouncing.
        $customer->update(['email' => 'correct@b.co.il']);

        $this->event([
            'RecordType' => 'Bounce', 'MessageID' => 'pm-1',
            'Type' => 'HardBounce', 'Inactive' => true,
        ])->assertOk();

        // The message failed; the replacement address did not.
        $this->assertSame('failed', $log->fresh()->status);
        $this->assertFalse($customer->fresh()->emailHasBounced());
    }

    public function test_correcting_the_address_clears_the_bounce(): void
    {
        $customer = Customer::factory()->create([
            'status' => CustomerStatus::Active,
            'email' => 'typo@b.co.il',
            'email_bounced_at' => now(),
            'email_bounce_reason' => 'לא קיים',
        ]);

        $customer->update(['email' => 'correct@b.co.il']);

        // A new address has not bounced and must not inherit the old verdict.
        $this->assertFalse($customer->fresh()->emailHasBounced());
        $this->assertNull($customer->fresh()->email_bounce_reason);
    }

    public function test_a_spam_complaint_opts_the_customer_out_of_marketing(): void
    {
        $customer = Customer::factory()->create([
            'status' => CustomerStatus::Active, 'email' => 'dani@b.co.il',
        ]);
        $log = $this->log(['customer_id' => $customer->id]);

        $this->event(['RecordType' => 'SpamComplaint', 'MessageID' => 'pm-1'])->assertOk();

        // Pressing "spam" is the clearest opt-out there is.
        $this->assertNotNull($log->fresh()->complained_at);
        $this->assertTrue($customer->fresh()->hasOptedOutOfMarketing());
    }

    /*
    | ----------------------------------------------------------------
    | Matching
    | ----------------------------------------------------------------
    */

    public function test_an_event_for_a_message_we_never_sent_is_accepted_and_ignored(): void
    {
        // A provider replays old events; an unmatched one is noise, not a fault,
        // and answering anything but 200 makes it retry forever.
        $this->event(['RecordType' => 'Delivery', 'MessageID' => 'unknown', 'Recipient' => 'nobody@x.co.il'])
            ->assertOk();

        $this->assertSame(0, NotificationLog::whereNotNull('delivered_at')->count());
    }

    public function test_an_unknown_message_id_never_lands_on_a_different_message(): void
    {
        $log = $this->log();

        // Most of our mail is transactional and records no provider id. An event
        // for one of those must not be pinned onto the customer's newest
        // broadcast just because it went to the same address.
        $this->event(['RecordType' => 'Open', 'MessageID' => 'pm-other', 'Recipient' => 'dani@b.co.il'])
            ->assertOk();

        $this->assertSame(0, $log->fresh()->open_count);
        $this->assertNull($log->fresh()->opened_at);
    }

    public function test_an_event_without_a_message_id_falls_back_to_the_recent_recipient(): void
    {
        $log = $this->log(['provider_message_id' => null]);

        $this->event(['RecordType' => 'Delivery', 'Recipient' => 'dani@b.co.il'])->assertOk();

        $this->assertNotNull($log->fresh()->delivered_at);
    }

    public function test_an_event_never_rewrites_a_message_older_than_a_week(): void
    {
        $log = $this->log(['provider_message_id' => null, 'sent_at' => now()->subMonth()]);

        $this->event(['RecordType' => 'Delivery', 'Recipient' => 'dani@b.co.il'])->assertOk();

        $this->assertNull($log->fresh()->delivered_at);
    }

    /*
    | ----------------------------------------------------------------
    | Per-broadcast totals
    | ----------------------------------------------------------------
    */

    public function test_a_broadcast_still_awaiting_the_provider_shows_no_numbers_at_all(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $broadcast = Broadcast::create([
            'subject' => 'עדכון', 'body' => 'x', 'channel' => BroadcastChannel::Email,
            'status' => BroadcastStatus::Sent,
        ]);
        $this->log(['broadcast_id' => $broadcast->id]);

        // A log row exists the moment we hand the mail over, so counting rows
        // would show "0 נמסרו" a second after sending — which reads like a
        // failed send rather than one the provider has yet to report on.
        Livewire::test(ListBroadcasts::class)
            ->assertTableColumnStateSet('delivery', '—', $broadcast);

        $this->event(['RecordType' => 'Delivery', 'MessageID' => 'pm-1'])->assertOk();

        Livewire::test(ListBroadcasts::class)
            ->assertTableColumnStateSet('delivery', '1 נמסרו · 0 נפתחו', $broadcast);
    }

    public function test_each_broadcasts_numbers_stay_its_own(): void
    {
        $first = Broadcast::create([
            'subject' => 'ראשון', 'body' => 'x', 'channel' => BroadcastChannel::Email,
            'status' => BroadcastStatus::Sent,
        ]);
        $second = Broadcast::create([
            'subject' => 'שני', 'body' => 'y', 'channel' => BroadcastChannel::Email,
            'status' => BroadcastStatus::Sent,
        ]);

        $this->log(['broadcast_id' => $first->id, 'delivered_at' => now(), 'opened_at' => now()]);
        $this->log(['broadcast_id' => $first->id, 'delivered_at' => now()]);
        $this->log(['broadcast_id' => $second->id, 'delivered_at' => now()]);

        // Two broadcasts sent the same afternoon must not share a number.
        $this->assertSame(2, NotificationLog::where('broadcast_id', $first->id)->whereNotNull('delivered_at')->count());
        $this->assertSame(1, NotificationLog::where('broadcast_id', $first->id)->whereNotNull('opened_at')->count());
        $this->assertSame(1, NotificationLog::where('broadcast_id', $second->id)->whereNotNull('delivered_at')->count());
    }
}
