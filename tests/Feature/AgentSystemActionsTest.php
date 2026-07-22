<?php

namespace Tests\Feature;

use App\Enums\ActionStatus;
use App\Enums\AgentCommandOutcome;
use App\Enums\TaskStatus;
use App\Enums\TicketStatus;
use App\Filament\Pages\AgentConsole;
use App\Jobs\SendPaymentLinkJob;
use App\Models\AgentCommand;
use App\Models\Customer;
use App\Models\PendingAction;
use App\Models\Site;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\McpClient;
use App\Services\Ai\ClaudeClient;
use App\Services\Automation\ApprovalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_approving_a_wordpress_update_for_all_connected_sites_updates_each(): void
    {
        config(['agent.system_actions_enabled' => true, 'agent.actions_enabled' => true]);

        $connectedA = Site::factory()->create(['mcp_enabled' => true, 'mcp_endpoint' => 'https://a.test/mcp']);
        $connectedB = Site::factory()->create(['mcp_enabled' => true, 'mcp_endpoint' => 'https://b.test/mcp']);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')
            ->twice()
            ->with(Mockery::type(Site::class), 'wp_core_update', [], Mockery::type('int'))
            ->andReturn(['content' => [['type' => 'text', 'text' => 'ליבת וורדפרס עודכנה מגרסה 6.4 לגרסה 6.5.']]]);
        $mcp->shouldReceive('textContent')->andReturn('ליבת וורדפרס עודכנה מגרסה 6.4 לגרסה 6.5.');
        $this->instance(McpClient::class, $mcp);

        // The payload carries the snapshot of approved site ids (as the proposal builds).
        $action = $this->systemAction(['operation' => 'update_wordpress', 'site_ids' => [$connectedA->id, $connectedB->id]]);
        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertSame(1, $connectedA->changes()->where('tool', 'wp_core_update')->count());
        $this->assertSame(1, $connectedB->changes()->where('tool', 'wp_core_update')->count());
    }

    public function test_a_wordpress_update_only_touches_the_snapshotted_sites_not_ones_added_later(): void
    {
        config(['agent.system_actions_enabled' => true, 'agent.actions_enabled' => true]);

        $approved = Site::factory()->create(['mcp_enabled' => true, 'mcp_endpoint' => 'https://a.test/mcp']);
        // Proposal captured only $approved. A site connected AFTER approval must
        // never be swept into the already-approved update.
        $addedLater = Site::factory()->create(['mcp_enabled' => true, 'mcp_endpoint' => 'https://b.test/mcp']);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldReceive('callTool')
            ->once()
            ->with(Mockery::on(fn (Site $s): bool => $s->id === $approved->id), 'wp_core_update', [], Mockery::type('int'))
            ->andReturn(['content' => [['type' => 'text', 'text' => 'עודכן']]]);
        $mcp->shouldReceive('textContent')->andReturn('עודכן');
        $this->instance(McpClient::class, $mcp);

        $action = $this->systemAction(['operation' => 'update_wordpress', 'site_ids' => [$approved->id]]);
        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertSame(1, $approved->changes()->count());
        $this->assertSame(0, $addedLater->changes()->count());
    }

    public function test_a_wordpress_update_is_blocked_by_the_site_agent_kill_switch(): void
    {
        config(['agent.system_actions_enabled' => true, 'agent.actions_enabled' => false]);
        $site = Site::factory()->create(['mcp_enabled' => true, 'mcp_endpoint' => 'https://a.test/mcp']);

        $mcp = Mockery::mock(McpClient::class);
        $mcp->shouldNotReceive('callTool');
        $this->instance(McpClient::class, $mcp);

        $action = $this->systemAction(['operation' => 'update_wordpress', 'site_ids' => [$site->id]]);
        app(ApprovalGate::class)->approve($action);

        // Not executed — the kill-switch stopped it before any MCP call.
        $this->assertNotSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertSame(0, $site->changes()->count());
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

    public function test_approving_a_cloudflare_purge_calls_cloudflare_with_the_saved_token(): void
    {
        config(['agent.system_actions_enabled' => true, 'billing.cloudflare.api_token' => 'saved-token']);
        Http::fake([
            '*/purge_cache*' => Http::response(['success' => true, 'result' => ['id' => 'x']]),
            '*/zones*' => Http::response(['success' => true, 'result' => [['id' => 'zone_1']]]),
        ]);
        $site = Site::factory()->create(['domain' => 'example.co.il']);

        $action = $this->systemAction(['operation' => 'purge_cloudflare_cache', 'site_id' => $site->id]);
        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/purge_cache')
            && str_contains($request->header('Authorization')[0] ?? '', 'saved-token'));
    }

    public function test_approving_a_country_rule_applies_it_across_zones_with_the_saved_token(): void
    {
        config(['agent.system_actions_enabled' => true, 'billing.cloudflare.api_token' => 'saved-token']);
        Http::fake([
            '*/access_rules/rules/*' => Http::response(['success' => true]),
            '*/access_rules/rules*' => fn ($request) => $request->method() === 'GET'
                ? Http::response(['success' => true, 'result' => []])
                : Http::response(['success' => true, 'result' => ['id' => 'new']]),
            '*/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'z1', 'name' => 'a.com']],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $action = $this->systemAction(['operation' => 'cloudflare_country_rule', 'country' => 'RU', 'mode' => 'block']);
        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), 'access_rules/rules')
            && data_get($request->data(), 'configuration.value') === 'RU'
            && data_get($request->data(), 'mode') === 'block');
    }

    public function test_a_cloudflare_purge_fails_cleanly_without_a_saved_token(): void
    {
        config(['agent.system_actions_enabled' => true, 'billing.cloudflare.api_token' => '']);
        $site = Site::factory()->create();

        $action = $this->systemAction(['operation' => 'purge_cloudflare_cache', 'site_id' => $site->id]);
        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Failed, $action->fresh()->status);
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

    public function test_close_ticket_system_action_closes_the_ticket_when_enabled(): void
    {
        config(['agent.system_actions_enabled' => true]);
        Queue::fake();
        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'subject' => 'בעיה',
            'status' => TicketStatus::Open,
        ]);

        $action = $this->systemAction(['operation' => 'close_ticket', 'ticket_id' => $ticket->id]);

        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    public function test_set_ticket_status_system_action_updates_the_ticket(): void
    {
        config(['agent.system_actions_enabled' => true]);
        Queue::fake();
        $ticket = Ticket::create(['channel' => 'whatsapp', 'subject' => 'בעיה', 'status' => TicketStatus::Open]);

        $action = $this->systemAction(['operation' => 'set_ticket_status', 'ticket_id' => $ticket->id, 'status' => 'pending']);
        app(ApprovalGate::class)->approve($action);

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertSame(TicketStatus::Pending, $ticket->fresh()->status);
    }

    public function test_update_customer_system_action_only_writes_whitelisted_fields(): void
    {
        config(['agent.system_actions_enabled' => true]);
        Queue::fake();
        $customer = Customer::factory()->create(['name' => 'ישן', 'phone' => '050']);

        // A stray non-whitelisted key must be ignored even if it reaches the payload.
        $action = $this->systemAction([
            'operation' => 'update_customer', 'customer_id' => $customer->id,
            'changes' => ['name' => 'חדש', 'phone' => '051', 'default_token_id' => 999],
        ]);
        app(ApprovalGate::class)->approve($action);

        $fresh = $customer->fresh();
        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertSame('חדש', $fresh->name);
        $this->assertSame('051', $fresh->phone);
        $this->assertNull($fresh->default_token_id);
    }

    public function test_complete_task_system_action_marks_the_task_done(): void
    {
        config(['agent.system_actions_enabled' => true]);
        Queue::fake();
        $task = Task::create(['title' => 'לבדוק', 'status' => TaskStatus::Open]);

        $action = $this->systemAction(['operation' => 'complete_task', 'task_id' => $task->id]);
        app(ApprovalGate::class)->approve($action);

        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_a_double_approval_executes_the_action_only_once(): void
    {
        config(['agent.system_actions_enabled' => true]);
        Queue::fake();
        $customer = Customer::factory()->create();

        $action = $this->systemAction([
            'operation' => 'send_payment_request', 'customer_id' => $customer->id,
            'amount_agorot' => 30000, 'description' => 'אחסון', 'channel' => 'whatsapp',
        ]);

        $gate = app(ApprovalGate::class);
        $first = $gate->approve($action->fresh());
        $second = $gate->approve($action->fresh()); // e.g. WhatsApp + panel at once

        $this->assertSame(ActionStatus::Executed, $action->fresh()->status);
        $this->assertStringContainsString('כבר טופלה', $second);
        // The send job is dispatched exactly once — no duplicate payment demand.
        Queue::assertPushed(SendPaymentLinkJob::class, 1);
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
