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
