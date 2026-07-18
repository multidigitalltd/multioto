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

    public function test_the_console_surfaces_the_real_ai_error_when_tool_use_returns_nothing(): void
    {
        // The AI is enabled but its tool-use call fails at the provider — the
        // console must show WHY, not a blank "no answer".
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('converse')->andReturn(null);
        $claude->shouldReceive('lastError')->andReturn('HTTP 400 — this model does not support tools');
        $this->app->instance(ClaudeClient::class, $claude);

        $command = app(CommandInterpreter::class)->run('כמה פניות פתוחות יש?');

        $this->assertSame(AgentCommandOutcome::Failed, $command->outcome);
        $this->assertStringContainsString('HTTP 400', $command->result);
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

    public function test_the_console_shows_the_agents_question_and_continues_on_the_next_reply(): void
    {
        $this->actingAs($user = User::factory()->create());
        $customer = Customer::factory()->create(['name' => 'משה']);

        // Turn 1: the agent asks (via need_clarification) instead of guessing.
        $this->fakeAgent([['need_clarification', ['question' => 'כמה לגבות ממשה?']]], summary: 'צריך סכום.');
        Livewire::test(AgentConsole::class)
            ->set('data.instruction', 'תשלח דרישת תשלום למשה')
            ->call('run')
            // The "waiting for your reply" banner is now shown with the question.
            ->assertSet('data.instruction', null)
            ->assertSeeText('כמה לגבות ממשה?');

        $this->assertSame(AgentCommandOutcome::Unclear, AgentCommand::latest('id')->first()->outcome);

        // Turn 2: the operator answers in the same box → it continues, not restarts.
        $capturedPrompt = null;
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('converse')->andReturnUsing(
            function (string $system, string $prompt, array $tools, callable $handler) use (&$capturedPrompt, $customer): string {
                $capturedPrompt = $prompt;
                $handler('propose_payment_request', ['customer_id' => $customer->id, 'amount_ils' => 300, 'description' => 'אחסון']);

                return 'בוצע';
            }
        );
        $this->app->instance(ClaudeClient::class, $claude);

        Livewire::test(AgentConsole::class)
            ->set('data.instruction', '300 שקל')
            ->call('run');

        $second = AgentCommand::latest('id')->first();
        $this->assertSame(AgentCommandOutcome::Proposed, $second->outcome);
        // The stored turn is exactly what the operator typed (clean chat bubble)…
        $this->assertSame('300 שקל', $second->instruction);
        // …while the agent received the original request merged in as context.
        $this->assertStringContainsString('תשלח דרישת תשלום למשה', (string) $capturedPrompt);
        $this->assertSame(30000, data_get(PendingAction::find($second->pending_action_id)->payload, 'amount_agorot'));
    }

    public function test_a_follow_up_message_carries_the_recent_conversation_as_context(): void
    {
        $this->actingAs(User::factory()->create());

        // Turn 1: a normal answered command.
        $this->fakeAgent([], summary: 'הצגתי את רשימת הלקוחות.');
        Livewire::test(AgentConsole::class)->set('data.instruction', 'מי הלקוחות שלי?')->call('run');

        // Turn 2: a plain follow-up (NOT a clarification) must still reach the
        // agent with the previous turn as context, so the chat has memory.
        $captured = null;
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('converse')->andReturnUsing(
            function (string $system, string $prompt) use (&$captured): string {
                $captured = $prompt;

                return 'הנה מי שבפיגור.';
            }
        );
        $this->app->instance(ClaudeClient::class, $claude);

        Livewire::test(AgentConsole::class)->set('data.instruction', 'ומי מהם בפיגור תשלום?')->call('run');

        $this->assertStringContainsString('מי הלקוחות שלי?', (string) $captured);
        $this->assertStringContainsString('ומי מהם בפיגור תשלום?', (string) $captured);
    }

    public function test_a_decision_from_the_chat_posts_a_system_turn_into_the_thread(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['name' => 'משה']);

        // The agent files a proposal in the chat…
        $this->fakeAgent([['propose_payment_request', ['customer_id' => $customer->id, 'amount_ils' => 300, 'description' => 'אחסון']]]);
        Livewire::test(AgentConsole::class)->set('data.instruction', 'דרישת תשלום למשה 300')->call('run');

        $action = PendingAction::latest('id')->first();

        // …and rejecting it from the chat records a system turn with the outcome.
        Livewire::test(AgentConsole::class)->call('rejectAction', $action->id);

        $system = AgentCommand::where('role', 'system')->latest('id')->first();
        $this->assertNotNull($system);
        $this->assertStringContainsString("#{$action->id}", $system->instruction);
        $this->assertStringContainsString('נדחתה', (string) $system->result);
    }

    public function test_the_chat_page_renders_the_conversation(): void
    {
        $this->actingAs($user = User::factory()->create());
        AgentCommand::create([
            'user_id' => $user->id, 'role' => 'user',
            'instruction' => 'שלום סוכן', 'outcome' => AgentCommandOutcome::Dispatched,
            'result' => 'שלום, איך אפשר לעזור?',
        ]);

        Livewire::test(AgentConsole::class)
            ->assertOk()
            ->assertSeeText('שלום סוכן')
            ->assertSeeText('שלום, איך אפשר לעזור?');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
