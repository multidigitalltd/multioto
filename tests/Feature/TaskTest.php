<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\TaskResource\Pages\EditTask;
use App\Filament\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Jobs\SendTaskRemindersJob;
use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_marking_a_task_done_stamps_completed_at_and_reopening_clears_it(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::Open]);
        $this->assertNull($task->completed_at);

        $task->update(['status' => TaskStatus::Done]);
        $this->assertNotNull($task->fresh()->completed_at);

        $task->update(['status' => TaskStatus::Open]);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_status_buttons_inside_a_task_move_it_through_its_lifecycle(): void
    {
        $this->actingAs(User::factory()->create());
        $task = Task::factory()->create(['status' => TaskStatus::Open]);

        // "סמן כבביצוע"
        Livewire::test(EditTask::class, ['record' => $task->id])
            ->callAction('markInProgress');
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        // "סמן כהושלם" — stamps completion time via the observer.
        Livewire::test(EditTask::class, ['record' => $task->id])
            ->callAction('markDone');
        $this->assertSame(TaskStatus::Done, $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);

        // "פתח מחדש" — clears the completion time.
        Livewire::test(EditTask::class, ['record' => $task->id])
            ->callAction('reopen');
        $this->assertSame(TaskStatus::Open, $task->fresh()->status);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_rescheduling_a_task_reopens_its_reminder_cycle(): void
    {
        $task = Task::factory()->create([
            'due_at' => now()->subDay(),
            'reminded_at' => now()->subDay(),
        ]);

        $task->update(['due_at' => now()->addWeek()]);

        $this->assertNull($task->fresh()->reminded_at);
    }

    public function test_a_ticket_can_be_turned_into_a_linked_task(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $customer = Customer::factory()->create();
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'channel' => TicketChannel::Email,
            'subject' => 'להחזיר ללקוח לגבי הדומיין',
            'status' => TicketStatus::Open,
        ]);

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->callAction('convertToTask', [
                'title' => 'לבדוק את הדומיין',
                'assignees' => [$user->id],
                'priority' => TicketPriority::High->value,
            ]);

        $task = Task::sole();
        $this->assertSame('לבדוק את הדומיין', $task->title);
        $this->assertSame($ticket->id, $task->ticket_id);
        $this->assertSame($customer->id, $task->customer_id);
        $this->assertTrue($task->assignees->contains($user->id));
    }

    public function test_the_reminder_job_emails_every_assignee_their_due_tasks_once(): void
    {
        Mail::fake();

        // A shared task assigned to two team members reminds both of them.
        $a = User::factory()->create(['email' => 'a@example.co']);
        $b = User::factory()->create(['email' => 'b@example.co']);
        $due = Task::factory()->create(['due_at' => now()->subDay(), 'status' => TaskStatus::Open]);
        $due->assignees()->attach([$a->id, $b->id]);

        // Not due yet — must be left alone.
        Task::factory()->create(['due_at' => now()->addWeek()])->assignees()->attach($a->id);

        (new SendTaskRemindersJob)->handle();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m): bool => $m->hasTo('a@example.co') && str_contains($m->bodyText, $due->title));
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m): bool => $m->hasTo('b@example.co') && str_contains($m->bodyText, $due->title));
        $this->assertNotNull($due->fresh()->reminded_at);

        // A second run does not re-notify (already reminded).
        Mail::fake();
        (new SendTaskRemindersJob)->handle();
        Mail::assertNothingSent();
    }

    public function test_subtask_progress_counts_completed_items(): void
    {
        $task = Task::factory()->create(['subtasks' => [
            ['title' => 'לגבות', 'done' => true],
            ['title' => 'לעדכן DNS', 'done' => false],
            ['title' => 'לבדוק SSL', 'done' => true],
        ]]);

        $this->assertSame([2, 3], $task->subtaskProgress());
    }

    public function test_the_tasks_screen_renders(): void
    {
        $this->actingAs(User::factory()->create());
        Task::factory()->count(3)->create();

        Livewire::test(ListTasks::class)->assertOk()->assertCountTableRecords(3);
    }

    public function test_the_print_page_lists_open_tasks_only(): void
    {
        $this->actingAs(User::factory()->create());
        Task::factory()->create(['title' => 'לתקן דומיין', 'status' => TaskStatus::Open]);
        Task::factory()->create(['title' => 'בעבודה עכשיו', 'status' => TaskStatus::InProgress]);
        Task::factory()->create(['title' => 'כבר הושלמה', 'status' => TaskStatus::Done]);

        $this->get(route('tasks.print'))
            ->assertOk()
            ->assertSee('לתקן דומיין')
            ->assertSee('בעבודה עכשיו')
            ->assertDontSee('כבר הושלמה');
    }

    public function test_the_print_page_is_team_only(): void
    {
        // A guest is redirected to log in — the open-task list is team-only.
        $this->get(route('tasks.print'))->assertRedirect();
    }

    public function test_emailing_open_tasks_sends_the_list(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        Task::factory()->create(['title' => 'לחדש SSL', 'status' => TaskStatus::Open]);
        Task::factory()->create(['title' => 'כבר הושלמה', 'status' => TaskStatus::Done]);

        Livewire::test(ListTasks::class)
            ->callAction('emailOpen', ['email' => 'ops@multidigital.co.il']);

        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo('ops@multidigital.co.il')
            && str_contains($mail->bodyText, 'לחדש SSL')
            && ! str_contains($mail->bodyText, 'כבר הושלמה'));
    }
}
