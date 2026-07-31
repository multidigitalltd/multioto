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
