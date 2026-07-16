<?php

namespace Tests\Feature;

use App\Enums\AgentCommandOutcome;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Filament\Pages\AgentConsole;
use App\Filament\Widgets\AgentCommandWidget;
use App\Models\AgentCommand;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\CommandInterpreter;
use App\Services\Ai\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class CommandConsoleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mock the agent's tool-use loop: converse() runs $toolCalls through the real
     * handler (hitting the DB + approval gate) and returns $summary — so the real
     * ConsoleAgent tools are exercised without a live model.
     *
     * @param  list<array{0: string, 1: array}>  $toolCalls
     */
    private function fakeAgent(array $toolCalls, string $summary = 'בוצע'): void
    {
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('converse')->andReturnUsing(
            function (string $system, string $prompt, array $tools, callable $handler) use ($toolCalls, $summary): string {
                foreach ($toolCalls as [$name, $input]) {
                    $handler($name, $input);
                }

                return $summary;
            }
        );
        $this->app->instance(ClaudeClient::class, $claude);
    }

    private function openTicket(string $customerName): Ticket
    {
        return Ticket::create([
            'customer_id' => Customer::factory()->create(['name' => $customerName])->id,
            'channel' => TicketChannel::Email,
            'subject' => 'תקלה באתר',
            'status' => TicketStatus::Open,
        ]);
    }

    public function test_a_reply_instruction_proposes_a_ticket_reply(): void
    {
        $ticket = $this->openTicket('משה כהן');
        $this->fakeAgent([
            ['read_ticket', ['ticket_id' => $ticket->id]],
            ['propose_reply_ticket', ['ticket_id' => $ticket->id, 'reply_text' => 'היי משה, אנחנו על זה ונחזור אליך היום.']],
        ], summary: 'הצעתי תשובה לפנייה.');

        $command = app(CommandInterpreter::class)->run('תענה למשה בכרטיס הפתוח שאנחנו על זה');

        $this->assertSame(AgentCommandOutcome::Proposed, $command->outcome);
        $action = PendingAction::find($command->pending_action_id);
        $this->assertSame('ticket_reply', $action->type);
        $this->assertSame('היי משה, אנחנו על זה ונחזור אליך היום.', data_get($action->payload, 'reply'));
        $this->assertSame($ticket->id, $action->ticket_id);
    }

    public function test_a_payment_request_proposes_a_system_action(): void
    {
        $customer = Customer::factory()->create(['name' => 'דנה']);
        $this->fakeAgent([
            ['find_customer', ['name' => 'דנה']],
            ['propose_payment_request', ['customer_id' => $customer->id, 'amount_ils' => 300, 'description' => 'אחסון שנתי']],
        ]);

        $command = app(CommandInterpreter::class)->run('תשלח דרישת תשלום לדנה על 300 שקל אחסון שנתי');

        $this->assertSame(AgentCommandOutcome::Proposed, $command->outcome);
        $action = PendingAction::find($command->pending_action_id);
        $this->assertSame('system_action', $action->type);
        $this->assertSame('send_payment_request', data_get($action->payload, 'operation'));
        $this->assertSame(30000, data_get($action->payload, 'amount_agorot'));
    }

    public function test_an_unactionable_request_falls_back_to_a_task(): void
    {
        $this->fakeAgent([
            ['propose_task', ['title' => 'להתקשר לספק הדומיינים ולברר החידוש']],
        ]);

        $command = app(CommandInterpreter::class)->run('תדבר עם רשם הדומיינים על החידוש');

        $this->assertSame(AgentCommandOutcome::Proposed, $command->outcome);
        $this->assertSame('open_task', data_get(PendingAction::find($command->pending_action_id)->payload, 'operation'));
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
            ->with('תנקה קאש באתר example.co.il', Mockery::any(), Mockery::any())
            ->andReturn($result);
        $this->app->instance(CommandInterpreter::class, $interpreter);

        Livewire::test(AgentConsole::class)
            ->set('data.instruction', 'תנקה קאש באתר example.co.il')
            ->call('run')
            ->assertNotified();
    }

    public function test_the_embedded_command_widget_sends_to_the_interpreter(): void
    {
        config(['billing.ai.enabled' => true]); // the widget is gated on the AI being on
        $this->actingAs(User::factory()->create());

        $result = new AgentCommand(['instruction' => 'x', 'result' => 'הוגשה לאישור']);
        $result->outcome = AgentCommandOutcome::Proposed;

        $interpreter = Mockery::mock(CommandInterpreter::class);
        $interpreter->shouldReceive('run')->once()->andReturn($result);
        $this->app->instance(CommandInterpreter::class, $interpreter);

        Livewire::test(AgentCommandWidget::class)
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
