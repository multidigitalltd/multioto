<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Jobs\InvestigateSiteJob;
use App\Jobs\MonitorSiteJob;
use App\Jobs\NotifyTaskCreatedJob;
use App\Mail\NotificationMail;
use App\Models\Site;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskAndIncidentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_task_notifies_its_assignees_in_panel_and_by_email(): void
    {
        Mail::fake();
        $assignee = User::factory()->create(['role' => UserRole::Agent, 'email' => 'agent@example.com']);
        $other = User::factory()->create(['role' => UserRole::Agent]);

        $task = Task::create(['title' => 'לבדוק גיבוי', 'status' => TaskStatus::Open]);
        $task->assignees()->sync([$assignee->id]);

        NotifyTaskCreatedJob::dispatchSync($task->id);

        // The assignee gets an in-panel bell notification…
        $this->assertSame(1, $assignee->fresh()->notifications()->count());
        // …and someone who wasn't assigned does not.
        $this->assertSame(0, $other->fresh()->notifications()->count());
        Mail::assertSent(NotificationMail::class);
    }

    public function test_an_unassigned_task_notifies_the_managers(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $agent = User::factory()->create(['role' => UserRole::Agent]);

        $task = Task::create(['title' => 'משימה יתומה', 'status' => TaskStatus::Open]);

        NotifyTaskCreatedJob::dispatchSync($task->id);

        // Managers are told; a non-manager agent is not.
        $this->assertSame(1, $admin->fresh()->notifications()->count());
        $this->assertSame(0, $agent->fresh()->notifications()->count());
    }

    /**
     * The person this announcement was for was deleted before the queue drained,
     * and deleting them took the task's last assignment with it. Staying quiet
     * would leave a task nobody owns that nobody was ever told about.
     */
    public function test_a_task_left_ownerless_by_a_deleted_assignee_reaches_the_managers(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $assignee = User::factory()->create(['role' => UserRole::Agent]);

        $task = Task::create(['title' => 'לבדוק גיבוי', 'status' => TaskStatus::Open]);
        $task->assignees()->sync([$assignee->id]);

        $assigneeId = $assignee->id;
        $assignee->delete();
        $task->assignees()->detach($assigneeId);

        NotifyTaskCreatedJob::dispatchSync($task->id, [$assigneeId]);

        $this->assertSame(1, $admin->fresh()->notifications()->count());
    }

    /**
     * But when the task still has an owner, the announcement whose audience is
     * gone simply says nothing: reporting it to the managers as unassigned
     * would be false.
     */
    public function test_a_lost_audience_says_nothing_while_the_task_still_has_an_owner(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $keeps = User::factory()->create(['role' => UserRole::Agent]);
        $left = User::factory()->create(['role' => UserRole::Agent]);

        $task = Task::create(['title' => 'לבדוק גיבוי', 'status' => TaskStatus::Open]);
        $task->assignees()->sync([$keeps->id]);

        $leftId = $left->id;
        $left->delete();

        NotifyTaskCreatedJob::dispatchSync($task->id, [$leftId]);

        $this->assertSame(0, $admin->fresh()->notifications()->count());
        $this->assertSame(0, $keeps->fresh()->notifications()->count());
    }

    /**
     * "A task landed with nobody on it" is a fact about the moment it was said.
     * If a clarification has since given the task to somebody, that assignment
     * announced itself — resolving the audience now would tell them twice.
     */
    public function test_an_unassigned_announcement_does_not_chase_whoever_got_the_task_later(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $alice = User::factory()->create(['role' => UserRole::Agent]);

        $task = Task::create(['title' => 'לבדוק גיבוי', 'status' => TaskStatus::Open]);

        // The clarification landed before the queue drained.
        $task->assignees()->sync([$alice->id]);

        NotifyTaskCreatedJob::dispatchSync($task->id, null, unassigned: true);

        $this->assertSame(0, $alice->fresh()->notifications()->count());
        $this->assertSame(0, $admin->fresh()->notifications()->count());
    }

    public function test_a_site_going_down_raises_the_in_panel_bell_for_managers(): void
    {
        config(['billing.monitoring.failures_to_incident' => 1]);
        Queue::fake([InvestigateSiteJob::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $site = Site::factory()->create(['domain' => 'down.example.com', 'monitor_url' => 'https://down.example.com']);
        Http::fake(['https://down.example.com' => Http::response('', 503)]);

        MonitorSiteJob::dispatchSync($site->id);

        $this->assertTrue($site->openIncident()->exists());
        $this->assertSame(1, $admin->fresh()->notifications()->count());
    }
}
