<?php

namespace App\Models;

use App\Enums\AgentCommandOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One free-text instruction the team gave the AI agent from the command console
 * ("reply to Moshe that we're on it", "clear the cache on site X"), plus how it
 * was understood and what it produced. Actions themselves still flow through the
 * approval gate — this is the console's history and audit trail, not an
 * execution path.
 */
class AgentCommand extends Model
{
    /** Where an instruction was typed: the panel console, or the WhatsApp group. */
    public const SOURCE_PANEL = 'panel';

    public const SOURCE_WHATSAPP = 'whatsapp';

    /**
     * Transient, never persisted (a real property, so it is not an attribute):
     * the run left work running in the background — a site investigation that
     * reports its own result later. Whoever is holding something open on this
     * command's behalf, such as a delegated task, must keep holding it.
     */
    public bool $backgroundWork = false;

    protected $fillable = [
        'user_id', 'source', 'role', 'instruction', 'outcome', 'result',
        'customer_id', 'ticket_id', 'site_id', 'pending_action_id',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => AgentCommandOutcome::class,
        ];
    }

    public function pendingAction(): BelongsTo
    {
        return $this->belongsTo(PendingAction::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
