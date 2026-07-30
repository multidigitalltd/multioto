<?php

namespace Tests\Feature;

use App\Enums\AgentCommandOutcome;
use App\Enums\TaskStatus;
use App\Enums\TicketChannel;
use App\Enums\TicketStatus;
use App\Jobs\NotifyTaskCreatedJob;
use App\Jobs\RunAgentInstructionJob;
use App\Models\AgentCommand;
use App\Models\Customer;
use App\Models\Task;
use App\Models\Ticket;
use App\Services\Agent\CommandInterpreter;
use App\Services\Waha\WahaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Running the team's own work from the management group: capturing a task the
 * moment it is said out loud, and handing work to the AI agent.
 *
 * The line these tests defend: a task is OURS and a ticket is the CUSTOMER'S.
 * They use different command words and different numbering, because a to-do
 * silently becoming a customer conversation (or the reverse) is worse than
 * either command simply not existing.
 */
class WhatsappTaskCommandsTest extends TestCase
{
    use RefreshDatabase;

    /** The WhatsApp management group we act on. */
    private const MGMT = '120363000000000000@g.us';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.waha.webhook_secret' => 'waha-secret',
            'billing.waha.base_url' => 'https://waha.test',
            'billing.waha.api_key' => 'k',
            'billing.waha.session' => 'default',
            'billing.waha.owner_number' => self::MGMT,
        ]);

        Http::fake(['*/api/sendText' => Http::response(['id' => 'reply-1'])]);

        // Only these two are faked: the ingestion job that carries the command
        // must still run, or nothing would reach the handler under test.
        Queue::fake([RunAgentInstructionJob::class, NotifyTaskCreatedJob::class]);
    }

    private function inbound(string $body, string $id = 'wa-1'): void
    {
        $this->post('/webhooks/waha?secret=waha-secret', [
            'event' => 'message',
            'payload' => ['id' => $id, 'from' => self::MGMT, 'body' => $body],
        ])->assertOk();
    }

    /** The text we sent back to the group. */
    private function lastReply(): string
    {
        return (string) (Http::recorded()->last()[0]->data()['text'] ?? '');
    }

    /*
    | ----------------------------------------------------------------
    | Opening tasks
    | ----------------------------------------------------------------
    */

    public function test_the_group_can_open_a_task(): void
    {

        $this->inbound('משימה לחדש את הדומיין של דני');

        $task = Task::sole();
        $this->assertSame('לחדש את הדומיין של דני', $task->title);
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertNull($task->due_at);

        // A task is not a customer conversation.
        $this->assertSame(0, Ticket::count());
        $this->assertStringContainsString("נפתחה משימה #{$task->id}", $this->lastReply());
    }

    public function test_a_leading_date_word_becomes_the_deadline(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $this->inbound('משימה מחר להתקשר לדני');

        $task = Task::sole();
        $this->assertSame('להתקשר לדני', $task->title);
        $this->assertSame('2026-08-11', $task->due_at->toDateString());
    }

    public function test_a_written_date_becomes_the_deadline(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $this->inbound('משימה 15/9 לחדש את התעודה');

        $task = Task::sole();
        $this->assertSame('לחדש את התעודה', $task->title);
        $this->assertSame('2026-09-15', $task->due_at->toDateString());
    }

    /**
     * "משימה 3 מכתבים ללקוחות" is a description that happens to start with a
     * number — not a date. Eating it would silently lose the first word.
     */
    public function test_a_description_starting_with_a_number_is_not_read_as_a_date(): void
    {

        $this->inbound('משימה 3 מכתבים ללקוחות');

        $task = Task::sole();
        $this->assertSame('3 מכתבים ללקוחות', $task->title);
        $this->assertNull($task->due_at);
    }

    public function test_opening_a_task_notifies_the_managers_who_are_not_in_the_group(): void
    {

        $this->inbound('משימה לבדוק את הגיבויים');

        Queue::assertPushed(NotifyTaskCreatedJob::class);
    }

    public function test_the_group_can_list_and_complete_tasks(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');

        $dated = Task::create(['title' => 'לשלם לספק', 'status' => TaskStatus::Open, 'due_at' => now()->addDay()]);
        $undated = Task::create(['title' => 'לסדר את המשרד', 'status' => TaskStatus::Open]);
        Task::create(['title' => 'כבר טופל', 'status' => TaskStatus::Done]);

        $this->inbound('משימות', 'wa-list');
        $reply = $this->lastReply();

        $this->assertStringContainsString('משימות פתוחות (2)', $reply);
        $this->assertStringContainsString('לשלם לספק', $reply);
        $this->assertStringContainsString('לסדר את המשרד', $reply);
        $this->assertStringNotContainsString('כבר טופל', $reply);
        // Soonest deadline first; undated tasks last.
        $this->assertLessThan(
            strpos($reply, $undated->title),
            strpos($reply, $dated->title),
        );

        $this->inbound("בוצע {$dated->id}", 'wa-done');

        $this->assertSame(TaskStatus::Done, $dated->fresh()->status);
        $this->assertStringContainsString('סומנה כבוצעה', $this->lastReply());
    }

    public function test_completing_a_missing_task_says_so_instead_of_failing(): void
    {

        $this->inbound('בוצע 999');

        $this->assertStringContainsString('לא נמצאה משימה #999', $this->lastReply());
    }

    /**
     * Tickets close with "סגור" and tasks with "בוצע". The two numbering spaces
     * overlap, so mixing the words up must never close the wrong thing.
     */
    public function test_the_task_and_ticket_commands_do_not_cross(): void
    {

        $ticket = Ticket::create([
            'channel' => TicketChannel::Manual,
            'subject' => 'פנייה כלשהי',
            'status' => TicketStatus::Open,
        ]);
        $task = Task::create(['title' => 'משימה כלשהי', 'status' => TaskStatus::Open]);

        // Deliberately the same number in both spaces — that is the trap.
        $this->assertSame($ticket->id, $task->id);

        $this->inbound("סגור {$ticket->id}", 'wa-x1');

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertSame(TaskStatus::Open, $task->fresh()->status);

        $this->inbound("בוצע {$task->id}", 'wa-x2');

        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
    }

    /*
    | ----------------------------------------------------------------
    | Handing work to the agent
    | ----------------------------------------------------------------
    */

    public function test_the_group_can_give_the_agent_a_free_instruction(): void
    {

        $this->inbound('סוכן בדוק למי יש חוב פתוח מעל חודש');

        Queue::assertPushed(
            RunAgentInstructionJob::class,
            fn (RunAgentInstructionJob $job): bool => $job->instruction === 'בדוק למי יש חוב פתוח מעל חודש'
                && $job->chatId === self::MGMT
                && $job->taskId === null,
        );

        $this->assertStringContainsString('מסרתי לסוכן', $this->lastReply());
    }

    public function test_an_existing_task_can_be_handed_to_the_agent(): void
    {

        $customer = Customer::factory()->create(['name' => 'דני']);
        $task = Task::create([
            'title' => 'לנקות קאש באתר',
            'description' => 'הלקוח מתלונן על עמוד ישן',
            'status' => TaskStatus::Open,
            'customer_id' => $customer->id,
        ]);

        $this->inbound("סוכן משימה {$task->id}");

        // Marked as being worked on so nobody picks it up in parallel — but NOT
        // completed: the agent proposes, a person decides.
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        Queue::assertPushed(
            RunAgentInstructionJob::class,
            fn (RunAgentInstructionJob $job): bool => $job->taskId === $task->id
                && str_contains($job->instruction, 'לנקות קאש באתר')
                && str_contains($job->instruction, 'הלקוח מתלונן על עמוד ישן')
                && str_contains($job->instruction, 'דני'),
        );
    }

    public function test_a_completed_task_is_not_handed_to_the_agent(): void
    {

        $task = Task::create(['title' => 'כבר טופל', 'status' => TaskStatus::Done]);

        $this->inbound("סוכן משימה {$task->id}");

        Queue::assertNotPushed(RunAgentInstructionJob::class);
        $this->assertStringContainsString('כבר בוצעה', $this->lastReply());
    }

    /**
     * The agent runs on the queue, never inside the webhook: it reasons over
     * several AI turns, and the webhook must answer immediately.
     */
    public function test_the_agent_answers_the_group_when_it_finishes(): void
    {
        $command = new AgentCommand([
            'outcome' => AgentCommandOutcome::Proposed,
            'result' => 'הוגשה פעולה לאישור.',
        ]);

        $interpreter = $this->mock(CommandInterpreter::class);
        $interpreter->shouldReceive('run')
            ->once()
            ->with('בדוק חובות', null, AgentCommand::SOURCE_WHATSAPP)
            ->andReturn($command);

        (new RunAgentInstructionJob(self::MGMT, 'בדוק חובות'))
            ->handle($interpreter, app(WahaClient::class));

        $reply = $this->lastReply();
        $this->assertStringContainsString('הוגשה פעולה לאישור', $reply);
        // A proposal is useless without the words that approve it.
        $this->assertStringContainsString('אשר', $reply);
    }

    public function test_a_question_from_the_agent_tells_the_group_how_to_answer(): void
    {
        $command = new AgentCommand([
            'outcome' => AgentCommandOutcome::Unclear,
            'result' => 'איזה סכום לגבות?',
        ]);

        $interpreter = $this->mock(CommandInterpreter::class);
        $interpreter->shouldReceive('run')->once()->andReturn($command);

        (new RunAgentInstructionJob(self::MGMT, 'שלח דרישת תשלום לדני'))
            ->handle($interpreter, app(WahaClient::class));

        $reply = $this->lastReply();
        $this->assertStringContainsString('איזה סכום לגבות?', $reply);
        // Plain group chatter is never swallowed as an answer, so the reply must
        // say which words continue the conversation.
        $this->assertStringContainsString('סוכן', $reply);
    }

    public function test_a_delegated_task_is_released_when_the_agent_run_fails(): void
    {
        $task = Task::create(['title' => 'משהו', 'status' => TaskStatus::InProgress]);

        (new RunAgentInstructionJob(self::MGMT, 'משהו', $task->id))
            ->failed(new \RuntimeException('boom'));

        // Left "in progress" forever, it would look like someone is on it.
        $this->assertSame(TaskStatus::Open, $task->fresh()->status);
        $this->assertStringContainsString('לא הצליח להשלים', $this->lastReply());
    }

    /*
    | ----------------------------------------------------------------
    | Conversation threading
    | ----------------------------------------------------------------
    */

    /**
     * The group has no user of its own, so without the source it could not be
     * threaded — every instruction would start cold and the agent's questions
     * could never be answered.
     */
    public function test_the_group_conversation_is_threaded_apart_from_the_panel(): void
    {
        AgentCommand::create([
            'user_id' => null,
            'source' => AgentCommand::SOURCE_WHATSAPP,
            'role' => 'user',
            'instruction' => 'שלח דרישת תשלום לדני',
            'outcome' => AgentCommandOutcome::Unclear,
            'result' => 'איזה סכום?',
        ]);

        $this->assertSame(1, AgentCommand::where('source', AgentCommand::SOURCE_WHATSAPP)->count());
        $this->assertSame(0, AgentCommand::where('source', AgentCommand::SOURCE_PANEL)->count());
    }

    /*
    | ----------------------------------------------------------------
    | The menu
    | ----------------------------------------------------------------
    */

    public function test_the_help_menu_lists_the_task_and_agent_commands(): void
    {
        $this->inbound('עזרה');

        $reply = $this->lastReply();
        $this->assertStringContainsString('משימה <תיאור>', $reply);
        $this->assertStringContainsString('בוצע <מספר>', $reply);
        $this->assertStringContainsString('סוכן <הוראה>', $reply);
        $this->assertStringContainsString('סוכן משימה <מספר>', $reply);
    }
}
