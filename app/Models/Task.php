<?php

namespace App\Models;

use App\Enums\ActionStatus;
use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * An internal team task. Optionally linked to the customer and/or ticket it
 * concerns, assigned to one or more team members, with a due date and an
 * optional checklist of sub-tasks; assignees are reminded (in-panel + email)
 * while it is open and due.
 */
class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'subtasks', 'customer_id', 'ticket_id',
        'status', 'priority', 'due_at', 'completed_at', 'reminded_at',
        'source_ref', 'background_holds',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TicketPriority::class,
            'subtasks' => 'array',
            'background_holds' => 'array',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /**
     * Record that a background job is holding this claimed task.
     *
     * The token identifies the holder, so giving a hold back is naming which
     * one ended rather than counting one off — a job that dies is failed on a
     * fresh instance of itself and cannot know whether its own release already
     * ran, and a second count-off would take a hold belonging to another job
     * still working.
     */
    public static function hold(?int $taskId, string $token): void
    {
        static::amendHolds($taskId, fn (array $holds): array => array_values(
            array_unique([...$holds, $token]),
        ));
    }

    /** Give back one named hold. Doing it twice is the same as doing it once. */
    public static function dropHold(?int $taskId, string $token): void
    {
        static::amendHolds($taskId, fn (array $holds): array => array_values(
            array_filter($holds, fn (string $held): bool => $held !== $token),
        ));
    }

    /**
     * Read-modify-write the holder list under a row lock, so two jobs finishing
     * at the same moment cannot each write a list that ignores the other.
     *
     * @param  callable(list<string>): list<string>  $amend
     */
    private static function amendHolds(?int $taskId, callable $amend): void
    {
        if ($taskId === null) {
            return;
        }

        DB::transaction(function () use ($taskId, $amend): void {
            $task = static::whereKey($taskId)->lockForUpdate()->first();

            if ($task === null) {
                return;
            }

            $holds = array_values(array_filter(
                (array) ($task->background_holds ?? []),
                fn ($held): bool => is_string($held),
            ));

            $task->update(['background_holds' => $amend($holds)]);
        });
    }

    /**
     * Hand a claimed task back to the humans, once nothing is working on it.
     *
     * A task delegated to the AI agent is marked "in progress" so nobody picks
     * it up in parallel. Two things can outlive the run that claimed it: a site
     * investigation still gathering findings (counted in background_holds), and
     * a fix it proposed that is waiting for a decision. While either is true
     * the task stays claimed; when neither is, it belongs to a person again —
     * and a task left "in progress" with nobody on it is out of the open list
     * and out of the reminders, which is how work quietly disappears.
     *
     * Only from "in progress": a status a person set meanwhile is newer than
     * ours and must stand. Safe to call more than once.
     */
    public static function releaseIfIdle(?int $taskId): void
    {
        if ($taskId === null) {
            return;
        }

        $working = ((array) (static::whereKey($taskId)->value('background_holds') ?? [])) !== [];

        // "Approved" is a decision taken and still executing — the external
        // action is running right now. Treating it as settled would hand the
        // task back mid-execution, where it could be delegated again.
        $deciding = PendingAction::query()
            ->where('task_id', $taskId)
            ->whereIn('status', [ActionStatus::Pending, ActionStatus::Approved])
            ->exists();

        if ($working || $deciding) {
            return;
        }

        static::whereKey($taskId)
            ->where('status', TaskStatus::InProgress)
            // Cleared because this update bypasses TaskObserver, and a released
            // task must be remindable again.
            ->update(['status' => TaskStatus::Open, 'reminded_at' => null]);
    }

    /** Not-yet-done tasks. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', TaskStatus::Done);
    }

    /** Open tasks whose due date has arrived or passed. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<=', now());
    }

    /**
     * All open tasks for a print/email report — eager-loaded and ordered by due
     * date (undated last). Shared by the "print" and "email" list actions so the
     * two reports always list exactly the same tasks in the same order.
     *
     * @return Collection<int, static>
     */
    public static function openForReport(): Collection
    {
        return static::query()->open()
            ->with(['assignees', 'customer'])
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('priority')
            ->get();
    }

    /**
     * Move the task to a lifecycle status. The TaskObserver keeps completed_at
     * and the reminder clock in sync on the same write, so this only needs to
     * set the status.
     */
    public function markStatus(TaskStatus $status): void
    {
        $this->update(['status' => $status]);
    }

    /** The team members this task is assigned to (may be several). */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Checklist progress as [done, total]. Sub-tasks are stored as
     * [{title, done}] on the subtasks JSON column.
     *
     * @return array{0: int, 1: int}
     */
    public function subtaskProgress(): array
    {
        $items = collect($this->subtasks ?? []);

        return [$items->where('done', true)->count(), $items->count()];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
