<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A standing ("always approve") grant for one kind of automation action.
 * While enabled, a new proposal whose standing key matches executes
 * immediately — the owner is told it ran instead of being asked. Created from
 * a concrete pending action ("אשר תמיד 123"); disabled or deleted from the
 * panel at any time. Destructive tools and customer-facing replies are never
 * eligible (ApprovalGate::standingKeyFor returns null for them).
 */
class StandingApproval extends Model
{
    protected $fillable = [
        'action_key', 'label', 'enabled', 'uses_count', 'last_used_at', 'created_from_action_id',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /** The enabled standing approval for a key, or null. */
    public static function enabledFor(?string $actionKey): ?self
    {
        if ($actionKey === null) {
            return null;
        }

        return static::query()->where('action_key', $actionKey)->where('enabled', true)->first();
    }

    /** Record one automatic use. */
    public function markUsed(): void
    {
        $this->increment('uses_count');
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
