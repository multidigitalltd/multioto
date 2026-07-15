<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\MessageAuthor;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Resources\PendingActionResource\Pages\ListPendingActions;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\StyleLearner;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class OperatorFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function pending(Customer $customer): PendingAction
    {
        return PendingAction::create([
            'type' => 'site_action',
            'status' => ActionStatus::Pending,
            'customer_id' => $customer->id,
            'summary' => 'פעולה',
            'payload' => [],
            'proposed_by' => 'ai',
        ]);
    }

    public function test_bulk_approve_runs_only_the_pending_actions(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        $p1 = $this->pending($customer);
        $p2 = $this->pending($customer);
        $already = $this->pending($customer);
        $already->update(['status' => ActionStatus::Executed]);

        // approve() is called for the two pending rows, never for the executed one.
        $gate = Mockery::mock(ApprovalGate::class);
        $gate->shouldReceive('approve')->twice()->andReturn('בוצעה');
        $this->app->instance(ApprovalGate::class, $gate);

        Livewire::test(ListPendingActions::class)
            ->callTableBulkAction('approveSelected', [$p1, $p2, $already]);
    }

    public function test_bulk_approve_counts_a_failed_action_as_failed_not_done(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        $p1 = $this->pending($customer);

        // ApprovalGate swallows execution errors: it marks the row Failed and
        // returns a message rather than throwing. The batch must judge by the
        // final status, so this counts as failed — not done.
        $gate = Mockery::mock(ApprovalGate::class);
        $gate->shouldReceive('approve')->once()->andReturnUsing(function (PendingAction $a): string {
            $a->update(['status' => ActionStatus::Failed]);

            return 'הפעולה נכשלה';
        });
        $this->app->instance(ApprovalGate::class, $gate);

        Livewire::test(ListPendingActions::class)
            ->callTableBulkAction('approveSelected', [$p1])
            ->assertNotified();

        $this->assertSame(ActionStatus::Failed, $p1->fresh()->status);
    }

    public function test_bulk_reject_rejects_the_selected_pending_actions(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        $p1 = $this->pending($customer);
        $p2 = $this->pending($customer);

        Livewire::test(ListPendingActions::class)
            ->callTableBulkAction('rejectSelected', [$p1, $p2]);

        $this->assertSame(ActionStatus::Rejected, $p1->fresh()->status);
        $this->assertSame(ActionStatus::Rejected, $p2->fresh()->status);
    }

    private function ticketWithReply(): array
    {
        $ticket = Ticket::create([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => TicketChannel::Email,
            'subject' => 'x',
            'status' => TicketStatus::Open,
        ]);
        $reply = $ticket->messages()->create([
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Email,
            'body' => 'תשובת נציג',
            'author' => MessageAuthor::Agent,
        ]);
        $inbound = $ticket->messages()->create([
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Email,
            'body' => 'שאלת לקוח',
            'author' => MessageAuthor::Customer,
        ]);

        return [$ticket, $reply, $inbound];
    }

    public function test_rating_a_reply_stores_the_score(): void
    {
        $this->actingAs(User::factory()->create());
        [$ticket, $reply, $inbound] = $this->ticketWithReply();

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->call('rateMessage', $reply->id, 8);
        $this->assertSame(8, $reply->fresh()->quality_rating);

        // A customer message is not rateable — the score is ignored.
        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->call('rateMessage', $inbound->id, 9);
        $this->assertNull($inbound->fresh()->quality_rating);
    }

    public function test_the_score_is_clamped_to_1_10(): void
    {
        $this->actingAs(User::factory()->create());
        [$ticket, $reply] = $this->ticketWithReply();

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])->call('rateMessage', $reply->id, 99);
        $this->assertSame(10, $reply->fresh()->quality_rating);
    }

    public function test_style_learner_weights_high_rated_replies_and_avoids_low_rated(): void
    {
        $ticket = Ticket::create([
            'customer_id' => Customer::factory()->create()->id,
            'channel' => TicketChannel::Email, 'subject' => 'x', 'status' => TicketStatus::Open,
        ]);
        $reply = fn (string $body, ?int $rating = null) => $ticket->messages()->create([
            'direction' => MessageDirection::Outbound, 'channel' => MessageChannel::Email,
            'body' => $body, 'author' => MessageAuthor::Agent, 'quality_rating' => $rating,
        ]);
        $reply('BEST_ANSWER', 9);
        $reply('WORST_ANSWER', 2);
        foreach (range(1, 5) as $n) {
            $reply("recent reply {$n}");
        }

        $captured = null;
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('structured')->once()
            ->andReturnUsing(function ($system, $prompt, $schema) use (&$captured): array {
                $captured = $prompt;

                return ['summary' => 'סיכום סגנון'];
            });
        $this->app->instance(ClaudeClient::class, $claude);

        $summary = app(StyleLearner::class)->refresh();

        $this->assertSame('סיכום סגנון', $summary);
        $this->assertSame('סיכום סגנון', config('billing.ai.style_summary'));
        $this->assertSame('סיכום סגנון', Setting::map()['ai.style_summary'] ?? null);
        // The prompt labels the good example under "high-rated" and the bad one
        // under "avoid".
        $this->assertStringContainsString('דורגו גבוה', (string) $captured);
        $this->assertStringContainsString('BEST_ANSWER', (string) $captured);
        $this->assertStringContainsString('דורגו נמוך', (string) $captured);
        $this->assertStringContainsString('WORST_ANSWER', (string) $captured);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
