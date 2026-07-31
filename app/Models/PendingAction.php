<?php

namespace App\Models;

use App\Enums\ActionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An action the AI / automation wants to perform, awaiting the owner's
 * approval (WhatsApp "אשר <id>" or a panel button). Nothing customer-facing
 * executes without a row here being explicitly approved — the audit trail of
 * every automated decision in the business.
 */
class PendingAction extends Model
{
    protected $fillable = [
        'type', 'status', 'customer_id', 'ticket_id', 'task_id', 'summary', 'payload',
        'proposed_by', 'standing_approval_id', 'decided_at', 'executed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ActionStatus::class,
            'payload' => 'array',
            'decided_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * Cancel the pending AI reply proposals on a ticket — a person answered it
     * first, and approving the draft afterwards would send the customer a
     * second reply.
     *
     * Goes through here rather than a bare update so a task waiting on one of
     * those proposals is handed back: the decision HAS been made, just not by
     * pressing approve or reject, and a task nobody can release is a task that
     * disappears from the open list.
     */
    public static function supersedeTicketReplies(int $ticketId, string $reason): void
    {
        $superseded = static::query()
            ->where('ticket_id', $ticketId)
            ->where('type', 'ticket_reply')
            ->where('status', ActionStatus::Pending)
            ->get(['id', 'task_id']);

        if ($superseded->isEmpty()) {
            return;
        }

        static::whereKey($superseded->pluck('id')->all())
            // Still conditional: an approval that claimed the row a moment ago
            // is executing, and must not be overwritten.
            ->where('status', ActionStatus::Pending)
            ->update(['status' => ActionStatus::Rejected, 'decided_at' => now(), 'error' => $reason]);

        $superseded->pluck('task_id')
            ->filter()
            ->unique()
            ->each(fn (int $taskId): mixed => Task::releaseIfIdle($taskId));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** The team task that is waiting on this proposal, if one was delegated. */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
