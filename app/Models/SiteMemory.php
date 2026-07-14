<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single durable note about a site — one key/value the agent or the team can
 * read before acting and write after learning something. Never stores secrets
 * (those live encrypted on the site row); this is plain operational context.
 */
class SiteMemory extends Model
{
    protected $fillable = ['site_id', 'key', 'value', 'updated_by'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
