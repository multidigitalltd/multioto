<?php

namespace Tests\Feature;

use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Jobs\ClassifyTicketJob;
use App\Jobs\DraftReplyJob;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Ai\ClaudeClient;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class ClassifyTicketTest extends TestCase
{
    use RefreshDatabase;

    private function ticketWithMessage(): Ticket
    {
        $customer = Customer::factory()->create(['name' => 'עסק בדיקה']);
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'האתר נפל',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Normal,
        ]);
        $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'body' => 'זו בושה, האתר שלי למטה כבר שעתיים!',
            'author' => MessageAuthor::Customer,
        ]);

        return $ticket;
    }

    private function claudeReturning(array $result): ClaudeClient
    {
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('structured')->andReturn($result);

        return $claude;
    }

    public function test_an_angry_customer_is_escalated_and_the_team_alerted(): void
    {
        Bus::fake([DraftReplyJob::class]);
        $ticket = $this->ticketWithMessage();

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldReceive('alert')->once();

        (new ClassifyTicketJob($ticket->id))->handle(
            $this->claudeReturning([
                'priority' => 'normal', 'intent' => 'site_down',
                'category' => 'אתר למטה', 'summary' => 'האתר של הלקוח לא זמין',
                'sentiment' => 'angry',
            ]),
            $team,
        );

        $ticket->refresh();
        $this->assertSame('angry', $ticket->ai_sentiment);
        $this->assertSame('אתר למטה', $ticket->ai_topic);
        $this->assertSame('האתר של הלקוח לא זמין', $ticket->ai_summary);
        // Angry floors the priority at urgent even though the AI said "normal".
        $this->assertSame(TicketPriority::Urgent, $ticket->priority);
    }

    public function test_a_calm_ticket_is_not_escalated_or_alerted(): void
    {
        Bus::fake([DraftReplyJob::class]);
        $ticket = $this->ticketWithMessage();

        $team = Mockery::mock(TeamNotifier::class);
        $team->shouldNotReceive('alert');

        (new ClassifyTicketJob($ticket->id))->handle(
            $this->claudeReturning([
                'priority' => 'low', 'intent' => 'billing',
                'category' => 'שאלת חיוב', 'summary' => 'שאלה על חשבונית',
                'sentiment' => 'neutral',
            ]),
            $team,
        );

        $ticket->refresh();
        $this->assertSame('neutral', $ticket->ai_sentiment);
        $this->assertSame(TicketPriority::Low, $ticket->priority);
    }
}
