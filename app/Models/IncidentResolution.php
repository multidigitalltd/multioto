<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One remembered incident treatment: the problem, the fix that ran, and
 * whether the follow-up verification confirmed it solved the problem.
 * The agent reads these back — same site and across sites — so a recurring
 * problem starts from "this worked last time", not from zero.
 */
class IncidentResolution extends Model
{
    protected $fillable = [
        'site_id', 'domain', 'problem', 'fix_tool', 'fix_summary', 'verified', 'action_id',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
