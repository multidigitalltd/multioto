<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
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
                'assigned_to' => $user->id,
                'priority' => TicketPriority::High->value,
            ]);

        $task = Task::sole();
        $this->assertSame('לבדוק את הדומיין', $task->title);
        $this->assertSame($ticket->id, $task->ticket_id);
        $this->assertSame($customer->id, $task->customer_id);
        $this->assertSame($user->id, $task->assigned_to);
    }

    public function test_the_reminder_job_emails_the_assignee_their_due_tasks_once(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'agent@example.co']);
        $due = Task::factory()->create([
            'assigned_to' => $user->id,
            'due_at' => now()->subDay(),
            'status' => TaskStatus::Open,
        ]);
        // Not due yet — must be left alone.
        Task::factory()->create(['assigned_to' => $user->id, 'due_at' => now()->addWeek()]);

        (new SendTaskRemindersJob)->handle();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m): bool => str_contains($m->bodyText, $due->title));
        $this->assertNotNull($due->fresh()->reminded_at);

        // A second run does not re-notify (already reminded).
        Mail::fake();
        (new SendTaskRemindersJob)->handle();
        Mail::assertNothingSent();
    }

    public function test_the_tasks_screen_renders(): void
    {
        $this->actingAs(User::factory()->create());
        Task::factory()->count(3)->create();

        Livewire::test(ListTasks::class)->assertOk()->assertCountTableRecords(3);
    }
}
