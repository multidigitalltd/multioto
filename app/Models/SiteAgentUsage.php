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
    protected $table = 'site_agent_usage';

    protected $fillable = [
        'customer_id', 'subscription_id', 'site_id', 'site_agent_subscriber_id',
        'provider_message_id', 'billable', 'charge_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'billable' => 'boolean',
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
