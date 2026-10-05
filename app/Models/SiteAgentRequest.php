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

    /**
     * Claimed by a worker and being carried out right now.
     *
     * Its own state, and not a flag, because it is what makes a second "כן"
     * harmless: the move out of AWAITING is a single conditional UPDATE, so
     * exactly one worker wins it and the other finds nothing left to do.
     */
    public const APPLYING = 'applying';

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

    /** Change one product's price (regular, sale, or ending a sale). */
    public const OP_PRICE = 'update_price';

    /** Change one product's stock. */
    public const OP_STOCK = 'update_stock';

    /** Put an image the customer sent onto a page or product. */
    public const OP_IMAGE = 'set_image';

    /** Change several fields of one product at once (name, prices, stock, status). */
    public const OP_PRODUCT = 'update_product';

    /** Create a product — always as a draft. */
    public const OP_PRODUCT_CREATE = 'create_product';

    /** Move an order along its ordinary path (processing, completed, …). */
    public const OP_ORDER_STATUS = 'order_status';

    /** Add a note to an order, private or to the buyer. */
    public const OP_ORDER_NOTE = 'order_note';

    /** Pause, resume or cancel a renewing subscription. */
    public const OP_SUBSCRIPTION_STATUS = 'subscription_status';

    /** Create a post or page. */
    public const OP_POST_CREATE = 'create_post';

    /** Change a post's title, status or excerpt. */
    public const OP_POST_UPDATE = 'update_post';

    /** Add a user to the site (never an administrator). */
    public const OP_USER_CREATE = 'create_user';

    /** Move a user to another role (never to or from administrator). */
    public const OP_USER_ROLE = 'user_role';

    /** Create a coupon. */
    public const OP_COUPON = 'create_coupon';

    /** The operations the assistant proposes, carried out by SiteActionApplier. */
    public const MANAGEMENT_OPERATIONS = [
        self::OP_PRODUCT_CREATE, self::OP_ORDER_STATUS, self::OP_ORDER_NOTE,
        self::OP_SUBSCRIPTION_STATUS, self::OP_POST_CREATE, self::OP_POST_UPDATE,
        self::OP_USER_CREATE, self::OP_USER_ROLE, self::OP_COUPON,
    ];

    /** Everything the agent is allowed to do, in one list. */
    public const OPERATIONS = [
        self::OP_APPEND, self::OP_REPLACE, self::OP_TITLE,
        self::OP_PRICE, self::OP_STOCK, self::OP_IMAGE, self::OP_PRODUCT,
        ...self::MANAGEMENT_OPERATIONS,
    ];

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
