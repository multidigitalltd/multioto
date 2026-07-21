<?php

namespace Tests\Feature;

use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Jobs\CheckSlaBreachesJob;
use App\Models\Ticket;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SlaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.support.sla.first_response_hours.normal' => 8,
            'billing.support.sla.first_response_hours.urgent' => 2,
        ]);
    }

    private function ticket(array $overrides = [], ?string $createdAt = null): Ticket
    {
        $ticket = Ticket::create(array_merge([
            'channel' => TicketChannel::Email,
            'subject' => 'בדיקה',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Normal,
        ], $overrides));

        if ($createdAt !== null) {
            Ticket::whereKey($ticket->id)->update(['created_at' => $createdAt]);
            $ticket->refresh();
        }

        return $ticket;
    }

    public function test_an_open_unanswered_ticket_past_target_is_breached(): void
    {
        $ticket = $this->ticket(createdAt: now()->subHours(9)); // target 8h

        $this->assertSame('breached', $ticket->firstResponseSlaStatus());
    }

    public function test_a_reply_within_target_meets_the_sla(): void
    {
        $ticket = $this->ticket(
            ['first_response_at' => now()->subHours(6)],
            createdAt: now()->subHours(7),
        );

        $this->assertSame('met', $ticket->firstResponseSlaStatus());
        $this->assertTrue($ticket->firstResponseMet());
    }

    public function test_a_fresh_ticket_is_not_at_risk_yet(): void
    {
        $ticket = $this->ticket(createdAt: now()->subHour()); // 1h into an 8h target

        $this->assertSame('ok', $ticket->firstResponseSlaStatus());
    }

    public function test_priority_shortens_the_target(): void
    {
        // 3h old: fine for a normal ticket (8h) but breached for an urgent one (2h).
        $normal = $this->ticket(['priority' => TicketPriority::Normal], createdAt: now()->subHours(3));
        $urgent = $this->ticket(['priority' => TicketPriority::Urgent], createdAt: now()->subHours(3));

        $this->assertSame('ok', $normal->firstResponseSlaStatus());
        $this->assertSame('breached', $urgent->firstResponseSlaStatus());
    }

    public function test_a_ticket_waiting_on_the_customer_is_not_shown_as_breached(): void
    {
        // Past the target and unanswered, but the ball is in the customer's court.
        $ticket = $this->ticket(['status' => TicketStatus::Pending], createdAt: now()->subHours(9));

        $this->assertSame('na', $ticket->firstResponseSlaStatus());
    }

    public function test_the_breach_job_alerts_the_team_once_and_then_stays_quiet(): void
    {
        $this->ticket(createdAt: now()->subHours(9));

        $notifier = Mockery::mock(TeamNotifier::class);
        $notifier->shouldReceive('alert')->once();
        $this->app->instance(TeamNotifier::class, $notifier);

        (new CheckSlaBreachesJob)->handle($notifier);

        // Alerting stamps the ticket so a second run does not nag.
        $this->assertNotNull(Ticket::first()->sla_alerted_at);

        (new CheckSlaBreachesJob)->handle($notifier); // no further alert (once())
    }

    public function test_the_breach_job_ignores_answered_and_non_open_tickets(): void
    {
        // Answered (has a first response) — not a first-response breach.
        $this->ticket(['first_response_at' => now()], createdAt: now()->subHours(9));
        // Waiting on the customer — SLA clock not on us.
        $this->ticket(['status' => TicketStatus::Pending], createdAt: now()->subHours(9));

        $notifier = Mockery::mock(TeamNotifier::class);
        $notifier->shouldNotReceive('alert');
        $this->app->instance(TeamNotifier::class, $notifier);

        (new CheckSlaBreachesJob)->handle($notifier);

        $this->assertTrue(true); // shouldNotReceive asserts on teardown
    }
}
