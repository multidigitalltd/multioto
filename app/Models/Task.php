<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An internal team task. Optionally linked to the customer and/or ticket it
 * concerns, assigned to a team member, with a due date; the assignee is
 * reminded (in-panel + email) while it is open and due.
 */
class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'assigned_to', 'customer_id', 'ticket_id',
        'status', 'priority', 'due_at', 'completed_at', 'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TicketPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
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
            ->with(['assignee', 'customer'])
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('priority')
            ->get();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
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
