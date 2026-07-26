<?php

namespace Tests\Feature;

use App\Filament\Resources\TaskResource\Pages\CreateTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The task-creation screen end to end. Regression guard for the 1.70.0
 * incident where users.allowed_modules was created as Postgres `json` (which
 * has no equality operator) and the assignee picker's SELECT DISTINCT made the
 * page 500. SQLite can't reproduce the operator error itself, but this keeps
 * the full render+create flow covered.
 */
class TaskCreationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_task_page_renders_and_creates_a_task(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateTask::class)
            ->fillForm(['title' => 'משימת בדיקה'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tasks', ['title' => 'משימת בדיקה']);
    }
}
