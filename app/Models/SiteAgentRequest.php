<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request from a customer to their site agent, through its whole life.
 */
class SiteAgentRequest extends Model
{
    use HasFactory;

    /** Shown to the customer, waiting for their yes. */
    public const AWAITING = 'awaiting';

    /** They said yes and it is live. */
    public const APPLIED = 'applied';

    /** They said no, or asked for something else instead. */
    public const CANCELED = 'canceled';

    /** They never answered, and the offer aged out. */
    public const EXPIRED = 'expired';

    /** They said yes and it could not be carried out. */
    public const FAILED = 'failed';

    /** Applied, and then put back. */
    public const REVERTED = 'reverted';

    /** Add a paragraph to an existing page. */
    public const OP_APPEND = 'append_text';

    /** Replace an exact piece of existing text on a page. */
    public const OP_REPLACE = 'replace_text';

    /** Change a page's title. */
    public const OP_TITLE = 'update_title';

    protected $fillable = [
        'site_agent_subscriber_id', 'site_id', 'customer_id',
        'message', 'inbound_message_id', 'operation', 'plan', 'preview', 'restore',
        'state', 'failure_reason', 'expires_at', 'applied_at', 'reverted_at',
    ];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'restore' => 'array',
            'expires_at' => 'datetime',
            'applied_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(SiteAgentSubscriber::class, 'site_agent_subscriber_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Still waiting for a yes, and still young enough to mean it.
     *
     * The expiry is part of the query rather than a flag some job has to set,
     * so an offer from last Tuesday can never be confirmed by a "כן" typed
     * today in answer to something else entirely.
     */
    public function scopeAwaitingConfirmation(Builder $query): Builder
    {
        return $query
            ->where('state', self::AWAITING)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Applied recently enough that putting it back is still one word away. */
    public function scopeRevertable(Builder $query): Builder
    {
        $window = max(1, (int) config('siteagent.undo_minutes', 1440));

        return $query
            ->where('state', self::APPLIED)
            ->where('applied_at', '>=', now()->subMinutes($window));
    }

    public function isRevertable(): bool
    {
        $window = max(1, (int) config('siteagent.undo_minutes', 1440));

        return $this->state === self::APPLIED
            && $this->applied_at !== null
            && $this->applied_at->gte(now()->subMinutes($window));
    }
}
