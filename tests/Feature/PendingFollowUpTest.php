<?php

namespace Tests\Feature;

use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\FollowUpPendingTicketsJob;
use App\Jobs\SendTicketNotificationJob;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PendingFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private function pendingTicket(): Ticket
    {
        $customer = Customer::factory()->create(['email' => 'lead@example.com']);

        return Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'שאלה על החשבונית',
            'status' => TicketStatus::Pending,
        ]);
    }

    public function test_entering_pending_stamps_the_clock_and_leaving_clears_it(): void
    {
        $ticket = $this->pendingTicket();

        // The observer stamped pending_since on the transition into Pending.
        $this->assertNotNull($ticket->fresh()->pending_since);
        $this->assertNull($ticket->fresh()->pending_reminded_at);

        $ticket->update(['status' => TicketStatus::Open]);

        $this->assertNull($ticket->fresh()->pending_since);
        $this->assertNull($ticket->fresh()->pending_reminded_at);
    }

    public function test_a_reminder_is_sent_once_after_reminder_days_of_silence(): void
    {
        Queue::fake();
        config(['billing.support.pending_followup' => ['enabled' => true, 'reminder_days' => 3, 'close_days' => 7]]);

        $ticket = $this->pendingTicket();
        $ticket->forceFill(['pending_since' => now()->subDays(4)])->saveQuietly();

        (new FollowUpPendingTicketsJob)->handle();

        Queue::assertPushed(SendTicketNotificationJob::class, fn ($job) => $job->ticketId === $ticket->id && $job->templateKey === 'ticket.reminder');
        $this->assertNotNull($ticket->fresh()->pending_reminded_at);
        $this->assertSame(TicketStatus::Pending, $ticket->fresh()->status);
    }

    public function test_the_reminder_is_not_sent_twice(): void
    {
        Queue::fake();
        config(['billing.support.pending_followup' => ['enabled' => true, 'reminder_days' => 3, 'close_days' => 7]]);

        $ticket = $this->pendingTicket();
        $ticket->forceFill([
            'pending_since' => now()->subDays(4),
            'pending_reminded_at' => now()->subDay(),
        ])->saveQuietly();

        (new FollowUpPendingTicketsJob)->handle();

        Queue::assertNotPushed(SendTicketNotificationJob::class);
    }

    public function test_each_pending_cycle_reminder_carries_a_distinct_dedupe_tag(): void
    {
        Queue::fake();
        config(['billing.support.pending_followup' => ['enabled' => true, 'reminder_days' => 3, 'close_days' => 7]]);

        $ticket = $this->pendingTicket();

        // First pending cycle: silent long enough → reminder.
        $ticket->forceFill(['pending_since' => now()->subDays(4), 'pending_reminded_at' => null])->saveQuietly();
        (new FollowUpPendingTicketsJob)->handle();

        // Customer replies (leaves Pending) then goes Pending again later: a new
        // cycle with a later pending_since and a cleared reminder stamp.
        $ticket->refresh();
        $ticket->forceFill(['pending_since' => now()->subDays(4)->addHour(), 'pending_reminded_at' => null])->saveQuietly();
        (new FollowUpPendingTicketsJob)->handle();

        $tags = [];
        Queue::assertPushed(SendTicketNotificationJob::class, function ($job) use ($ticket, &$tags) {
            if ($job->ticketId === $ticket->id && $job->templateKey === 'ticket.reminder') {
                $tags[] = $job->dedupeTag;
            }

            return true;
        });

        // Two reminders, each with its own cycle tag → the downstream status-only
        // dedupe cannot swallow the second send.
        $this->assertCount(2, $tags);
        $this->assertNotNull($tags[0]);
        $this->assertNotSame($tags[0], $tags[1]);
    }

    public function test_the_ticket_is_auto_closed_silently_after_close_days_of_silence(): void
    {
        Queue::fake();
        config(['billing.support.pending_followup' => ['enabled' => true, 'reminder_days' => 3, 'close_days' => 7]]);

        $ticket = $this->pendingTicket();
        $ticket->forceFill([
            'pending_since' => now()->subDays(8),
            'pending_reminded_at' => now()->subDays(4),
        ])->saveQuietly();

        (new FollowUpPendingTicketsJob)->handle();

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::Closed, $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
        // A quiet close: the customer is NOT told the ticket was closed — they
        // already ignored the reminder, and a reply still reopens it.
        Queue::assertNotPushed(SendTicketNotificationJob::class);
    }

    public function test_the_default_policy_closes_after_fourteen_silent_days(): void
    {
        Queue::fake();

        // No config override: this asserts the shipped policy, not a fixture.
        // The reminder is already spent, as it would be by day 14 in real life.
        $ticket = $this->pendingTicket();
        $ticket->forceFill([
            'pending_since' => now()->subDays(13),
            'pending_reminded_at' => now()->subDays(10),
        ])->saveQuietly();

        (new FollowUpPendingTicketsJob)->handle();
        $this->assertSame(TicketStatus::Pending, $ticket->fresh()->status);

        $ticket->forceFill(['pending_since' => now()->subDays(15)])->saveQuietly();
        (new FollowUpPendingTicketsJob)->handle();

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        // Closed without a word to the customer — that is the whole point.
        Queue::assertNotPushed(SendTicketNotificationJob::class);
    }

    public function test_a_ticket_waiting_since_before_the_clock_existed_is_still_closed(): void
    {
        Queue::fake();
        config(['billing.support.pending_followup' => ['enabled' => true, 'reminder_days' => 3, 'close_days' => 14]]);

        // Pending since before pending_since was ever stamped — the oldest
        // waiting tickets in the system, and the ones a whereNotNull filter
        // excluded from every sweep since the column was added.
        $ticket = $this->pendingTicket();
        $ticket->timestamps = false;
        $ticket->forceFill(['pending_since' => null, 'updated_at' => now()->subDays(40)])->saveQuietly();

        (new FollowUpPendingTicketsJob)->handle();

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        Queue::assertNotPushed(SendTicketNotificationJob::class);
    }

    public function test_an_unstamped_ticket_not_yet_closable_gets_its_clock_without_resetting_it(): void
    {
        Queue::fake();
        config(['billing.support.pending_followup' => ['enabled' => true, 'reminder_days' => 3, 'close_days' => 14]]);

        // Five days and already reminded: past the sweep's threshold, short of
        // the close, and with nothing else left to do — so the adoption is the
        // only thing this run does to it.
        $ticket = $this->pendingTicket();
        $ticket->timestamps = false;
        $ticket->forceFill([
            'pending_since' => null,
            'pending_reminded_at' => now()->subDays(2),
            'updated_at' => now()->subDays(5),
        ])->saveQuietly();

        (new FollowUpPendingTicketsJob)->handle();

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::Pending, $fresh->status);
        // Adopted at its real age, not at today: stamping the clock must not
        // also touch updated_at, which IS the clock here — that would reset the
        // wait to zero every night and the ticket would never close.
        $this->assertSame(5, (int) $fresh->pending_since->diffInDays(now()));
        $this->assertSame(5, (int) $fresh->updated_at->diffInDays(now()));
    }

    public function test_a_freshly_pending_ticket_is_left_alone(): void
    {
        Queue::fake();
        config(['billing.support.pending_followup' => ['enabled' => true, 'reminder_days' => 3, 'close_days' => 7]]);

        $ticket = $this->pendingTicket(); // pending_since = now

        (new FollowUpPendingTicketsJob)->handle();

        Queue::assertNotPushed(SendTicketNotificationJob::class);
        $this->assertSame(TicketStatus::Pending, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->pending_reminded_at);
    }

    public function test_the_mechanism_can_be_disabled(): void
    {
        Queue::fake();
        config(['billing.support.pending_followup' => ['enabled' => false, 'reminder_days' => 3, 'close_days' => 7]]);

        $ticket = $this->pendingTicket();
        $ticket->forceFill(['pending_since' => now()->subDays(30)])->saveQuietly();

        (new FollowUpPendingTicketsJob)->handle();

        Queue::assertNotPushed(SendTicketNotificationJob::class);
        $this->assertSame(TicketStatus::Pending, $ticket->fresh()->status);
    }
}
