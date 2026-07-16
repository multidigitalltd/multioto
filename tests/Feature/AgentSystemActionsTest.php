<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\AgentCommandOutcome;
use App\Filament\Pages\AgentConsole;
use App\Jobs\SendPaymentLinkJob;
use App\Models\AgentCommand;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AgentSystemActionsTest extends TestCase
{
    use RefreshDatabase;

    private function systemAction(array $payload): PendingAction
    {
        return PendingAction::create([
            'type' => 'system_action',
            'status' => ActionStatus::Pending,
            'summary' => 'פעולת מערכת',
            'payload' => $payload,
            'proposed_by' => 'console',
        ]);
    }

    // ---- SystemActionRunner (execution) -----------------------------------

    public function test_approving_a_payment_request_dispatches_the_send_job_when_enabled(): void
    {
        config(['agent.system_actions_enabled' => true]);
        Queue::fake();
        $customer = Customer::factory()->create();

        $action = $this->systemAction([
            'operation' => 'send_payment_request', 'customer_id' => $customer->id,
            'amount_agorot' => 30000, 'description' => 'אחסון', 'channel' => 'whatsapp',
        ]);

        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        Queue::assertPushed(SendPaymentLinkJob::class, fn (SendPaymentLinkJob $j): bool => $j->customerId === $customer->id && $j->totalAgorot === 30000);
    }

    public function test_the_kill_switch_blocks_execution_of_an_approved_system_action(): void
    {
        config(['agent.system_actions_enabled' => false]);

        $action = $this->systemAction(['operation' => 'open_task', 'title' => 'לבדוק גיבוי']);

        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Failed, $action->fresh()->status);
        $this->assertSame(0, Task::count());
    }

    public function test_open_task_system_action_creates_the_task_when_enabled(): void
    {
        config(['agent.system_actions_enabled' => true]);
        Queue::fake();

        $action = $this->systemAction(['operation' => 'open_task', 'title' => 'להתקשר ללקוח']);

        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertSame(1, Task::where('title', 'להתקשר ללקוח')->count());
    }

    // ---- Inline approval from the console ---------------------------------

    public function test_a_proposal_can_be_approved_inline_from_the_console(): void
    {
        config(['agent.system_actions_enabled' => true]);
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $action = $this->systemAction(['operation' => 'open_task', 'title' => 'משימה מהמסוף']);

        Livewire::test(AgentConsole::class)
            ->call('approveAction', $action->id)
            ->assertNotified();

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertSame(1, Task::where('title', 'משימה מהמסוף')->count());
    }

    public function test_a_proposal_can_be_rejected_inline_from_the_console(): void
    {
        $this->actingAs(User::factory()->create());
        $action = $this->systemAction(['operation' => 'open_task', 'title' => 'לא רלוונטי']);

        Livewire::test(AgentConsole::class)->call('rejectAction', $action->id);

        $this->assertSame(ActionStatus::Rejected, $action->fresh()->status);
        $this->assertSame(0, Task::count());
    }

    // ---- ConsoleAgent (clarify-and-continue) ------------------------------

    public function test_a_missing_amount_is_asked_for_and_the_next_reply_continues(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['name' => 'משה כהן']);

        // The agent proposes when it sees an amount, otherwise it asks for one —
        // driven off whatever text the loop is given (original, then + clarification).
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('converse')->andReturnUsing(
            function (string $system, string $prompt, array $tools, callable $handler) use ($customer): string {
                if (preg_match('/(\d{2,})/', $prompt, $m)) {
                    $handler('propose_payment_request', ['customer_id' => $customer->id, 'amount_ils' => (int) $m[1], 'description' => 'אחסון']);

                    return 'הצעתי דרישת תשלום.';
                }
                $handler('need_clarification', ['question' => 'כמה לגבות ממשה?']);

                return 'צריך סכום.';
            }
        );
        $this->app->instance(ClaudeClient::class, $claude);

        // Turn 1: no amount → needs clarification (not cancelled).
        Livewire::test(AgentConsole::class)->set('data.instruction', 'תשלח דרישת תשלום למשה')->call('run');
        $first = AgentCommand::latest('id')->first();
        $this->assertSame(AgentCommandOutcome::Unclear, $first->outcome);
        $this->assertStringContainsString('כמה לגבות', (string) $first->result);

        // Turn 2: the operator answers → the original is continued into a proposal.
        Livewire::test(AgentConsole::class)->set('data.instruction', '300 שקל')->call('run');
        $second = AgentCommand::latest('id')->first();
        $this->assertSame(AgentCommandOutcome::Proposed, $second->outcome);
        $this->assertSame(30000, data_get(PendingAction::find($second->pending_action_id)->payload, 'amount_agorot'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
