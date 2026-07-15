<?php

namespace App\Models;

use App\Enums\SiteChangeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a site — the audit-and-undo record. `before_state`
 * holds enough of the prior state to reverse the change, so a mistaken action
 * can be rolled back and the whole history of a site is inspectable.
 */
class SiteChange extends Model
{
    protected $fillable = [
        'site_id', 'pending_action_id', 'summary', 'tool', 'arguments',
        'before_state', 'after_state', 'status', 'initiated_by', 'error', 'reverted_at',
        'revert_tool', 'revert_arguments',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'revert_arguments' => 'array',
            'status' => SiteChangeStatus::class,
            'reverted_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function pendingAction(): BelongsTo
    {
        return $this->belongsTo(PendingAction::class);
    }

    /**
     * Whether this change is still applied and carries a live rollback recipe
     * (the inverse tool to call). before_state is kept for the audit record; a
     * live undo needs the inverse call.
     */
    public function isRevertable(): bool
    {
        return $this->status === SiteChangeStatus::Applied && filled($this->revert_tool);
    }
}
