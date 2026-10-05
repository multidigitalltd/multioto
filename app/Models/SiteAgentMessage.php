<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of the conversation with the site agent: what the owner wrote, or
 * what we answered. The assistant reads the last few back as context.
 */
class SiteAgentMessage extends Model
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    protected $fillable = ['site_agent_subscriber_id', 'site_id', 'role', 'body'];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(SiteAgentSubscriber::class, 'site_agent_subscriber_id');
    }
}
