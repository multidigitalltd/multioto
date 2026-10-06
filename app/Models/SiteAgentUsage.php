<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message the site agent delivered — a line in the ledger behind the
 * "messages" row of an invoice. See SiteAgentUsageMeter.
 */
class SiteAgentUsage extends Model
{
    /** A reply the bot delivered. */
    public const MESSAGE = 'message';

    /** An approved change that put more than the threshold of words on the site. */
    public const WRITING = 'writing';

    protected $table = 'site_agent_usage';

    protected $fillable = [
        'customer_id', 'subscription_id', 'site_id', 'site_agent_subscriber_id',
        'provider_message_id', 'billable', 'charge_id', 'sent_at', 'kind', 'words',
    ];

    protected function casts(): array
    {
        return [
            'billable' => 'boolean',
            'words' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
