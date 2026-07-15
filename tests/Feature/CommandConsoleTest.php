<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\AgentCommandOutcome;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Pages\AgentConsole;
use App\Jobs\InvestigateSiteJob;
use App\Models\AgentCommand;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\CommandInterpreter;
use App\Services\Ai\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class CommandConsoleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mock the AI: the classifier call (system mentions "מנתב פקודות") returns
     * $classification; any other structured() call is treated as the reply draft.
     */
    private function fakeAi(array $classification, string $reply = 'תשובה מנוסחת'): void
    {
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('structured')->andReturnUsing(
            function (string $system, string $prompt, array $schema) use ($classification, $reply): array {
                return str_contains($system, 'מנתב פקודות') ? $classification : ['reply' => $reply];
            }
        );
        $this->app->instance(ClaudeClient::class, $claude);
    }

    private function openTicketFor(string $customerName): Ticket
    {
        return Ticket::create([
            'customer_id' => Customer::factory()->create(['name' => $customerName])->id,
            'channel' => TicketChannel::Email,
            'subject' => 'תקלה באתר',
            'status' => TicketStatus::Open,
        ]);
    }

    public function test_a_reply_instruction_drafts_and_proposes_a_ticket_reply(): void
    {
        $ticket = $this->openTicketFor('משה כהן');
        $this->fakeAi([
            'intent' => 'ticket_reply',
            'customer_name' => 'משה',
            'ticket_id' => null,
            'site_domain' => null,
            'detail' => 'אנחנו על זה ונחזור היום',
        ], reply: 'היי משה, אנחנו על זה ונחזור אליך היום. תודה!');

        $command = app(CommandInterpreter::class)->run('תענה למשה בכרטיס הפתוח שאנחנו על זה');

        $this->assertSame(AgentCommandOutcome::Proposed, $command->outcome);
        $this->assertSame($ticket->id, $command->ticket_id);

        $action = PendingAction::find($command->pending_action_id);
        $this->assertNotNull($action);
        $this->assertSame('ticket_reply', $action->type);
        $this->assertSame(ActionStatus::Pending, $action->status);
        $this->assertSame('היי משה, אנחנו על זה ונחזור אליך היום. תודה!', data_get($action->payload, 'reply'));
        $this->assertSame($ticket->id, $action->ticket_id);
    }

    public function test_a_site_instruction_dispatches_the_agent_to_that_site(): void
    {
        Queue::fake();
        $site = Site::factory()->create(['domain' => 'example.co.il', 'mcp_enabled' => true, 'mcp_endpoint' => 'https://example.co.il/mcp']);
        $this->fakeAi([
            'intent' => 'site_operation',
            'customer_name' => null,
            'ticket_id' => null,
            'site_domain' => 'example.co.il',
            'detail' => 'ניקוי קאש',
        ]);

        $command = app(CommandInterpreter::class)->run('תנקה קאש באתר example.co.il');

        $this->assertSame(AgentCommandOutcome::Dispatched, $command->outcome);
        $this->assertSame($site->id, $command->site_id);
        Queue::assertPushed(InvestigateSiteJob::class, fn (InvestigateSiteJob $job): bool => $job->siteId === $site->id && $job->goal === 'ניקוי קאש');
    }

    public function test_a_site_that_is_not_connected_asks_to_connect_it(): void
    {
        Queue::fake();
        Site::factory()->create(['domain' => 'offline.co.il', 'mcp_enabled' => false]);
        $this->fakeAi([
            'intent' => 'site_operation',
            'site_domain' => 'offline.co.il',
            'detail' => 'ניקוי קאש',
        ]);

        $command = app(CommandInterpreter::class)->run('תנקה קאש באתר offline.co.il');

        $this->assertSame(AgentCommandOutcome::Unclear, $command->outcome);
        $this->assertStringContainsString('אינו מחובר', (string) $command->result);
        Queue::assertNothingPushed();
    }

    public function test_an_unresolvable_target_asks_for_clarification(): void
    {
        $this->fakeAi([
            'intent' => 'ticket_reply',
            'customer_name' => 'לקוח שלא קיים',
            'detail' => 'משהו',
        ]);

        $command = app(CommandInterpreter::class)->run('תענה ללקוח שלא קיים');

        $this->assertSame(AgentCommandOutcome::Unclear, $command->outcome);
        $this->assertSame(0, PendingAction::count());
    }

    public function test_it_fails_gracefully_when_the_ai_is_off(): void
    {
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(false);
        $this->app->instance(ClaudeClient::class, $claude);

        $command = app(CommandInterpreter::class)->run('תנקה קאש באתר X');

        $this->assertSame(AgentCommandOutcome::Failed, $command->outcome);
    }

    public function test_the_console_page_sends_an_instruction_to_the_interpreter(): void
    {
        $this->actingAs(User::factory()->create());

        $result = new AgentCommand(['instruction' => 'x', 'result' => 'הוגשה לאישור']);
        $result->outcome = AgentCommandOutcome::Proposed;

        $interpreter = Mockery::mock(CommandInterpreter::class);
        $interpreter->shouldReceive('run')->once()
            ->with('תנקה קאש באתר example.co.il', Mockery::any())
            ->andReturn($result);
        $this->app->instance(CommandInterpreter::class, $interpreter);

        Livewire::test(AgentConsole::class)
            ->set('data.instruction', 'תנקה קאש באתר example.co.il')
            ->call('run')
            ->assertNotified();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
