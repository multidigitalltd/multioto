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
use App\Services\Agent\CommandInterpreter;
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

    /** Mock the classifier to return $classification for every structured() call. */
    private function fakeClassification(array $classification): void
    {
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('structured')->andReturn($classification);
        $this->app->instance(ClaudeClient::class, $claude);
    }

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

    public function test_a_payment_request_command_proposes_a_system_action(): void
    {
        $customer = Customer::factory()->create(['name' => 'משה כהן']);
        $this->fakeClassification([
            'intent' => 'system_action', 'operation' => 'send_payment_request',
            'customer_name' => 'משה', 'amount_ils' => 300, 'detail' => 'אחסון שנתי',
        ]);

        $command = app(CommandInterpreter::class)->run('תשלח דרישת תשלום למשה על 300 שקל אחסון שנתי');

        $this->assertSame(AgentCommandOutcome::Proposed, $command->outcome);
        $action = PendingAction::find($command->pending_action_id);
        $this->assertSame('system_action', $action->type);
        $this->assertSame('send_payment_request', data_get($action->payload, 'operation'));
        $this->assertSame($customer->id, data_get($action->payload, 'customer_id'));
        $this->assertSame(30000, data_get($action->payload, 'amount_agorot')); // 300 ILS → agorot
    }

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
        $this->assertSame(0, Task::count()); // nothing ran
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

    public function test_a_missing_amount_is_asked_for_and_the_next_reply_continues(): void
    {
        $this->actingAs(User::factory()->create());
        Customer::factory()->create(['name' => 'משה כהן']);

        // The classifier reads the amount out of whatever text it's given — so the
        // first turn (no number) yields no amount, the clarification (with 300) does.
        $claude = Mockery::mock(ClaudeClient::class);
        $claude->shouldReceive('isEnabled')->andReturn(true);
        $claude->shouldReceive('structured')->andReturnUsing(function (string $system, string $prompt): array {
            $amount = preg_match('/(\d{2,})/', $prompt, $m) ? (int) $m[1] : null;

            return ['intent' => 'system_action', 'operation' => 'send_payment_request',
                'customer_name' => 'משה', 'amount_ils' => $amount, 'detail' => 'אחסון'];
        });
        $this->app->instance(ClaudeClient::class, $claude);

        // Turn 1: no amount → needs clarification (not cancelled).
        Livewire::test(AgentConsole::class)->set('data.instruction', 'תשלח דרישת תשלום למשה')->call('run');
        $first = AgentCommand::latest('id')->first();
        $this->assertSame(AgentCommandOutcome::Unclear, $first->outcome);
        $this->assertStringContainsString('כמה לגבות', (string) $first->result);

        // Turn 2: the operator answers with the amount → the original is continued.
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
