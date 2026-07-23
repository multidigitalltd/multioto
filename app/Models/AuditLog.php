<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * One team-action audit entry — who did what and when. Append-only (only
 * created_at). Writing never throws: an audit failure must never break the
 * action being audited.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Attribute names whose VALUES are never stored in the changes payload. */
    public const REDACTED = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        'two_factor_code', 'mcp_secret', 'agent_token', 'agent_token_plain', 'cardcom_token', 'card_link_token',
    ];

    protected $fillable = [
        'user_id', 'user_name', 'event', 'auditable_type', 'auditable_id',
        'description', 'changes', 'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Append an audit entry for the CURRENTLY authenticated team member. Returns
     * silently when nobody is signed in (system/queue work is not a team action)
     * or the table isn't there yet. Never throws.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function record(string $event, string $description, ?Model $subject = null, ?array $changes = null, ?Authenticatable $actor = null): void
    {
        try {
            // Logout fires after the guard clears the user, so the caller can pass
            // the actor explicitly; everything else uses the signed-in user.
            $user = $actor ?? Auth::user();

            if ($user === null || ! Schema::hasTable('audit_logs')) {
                return;
            }

            static::create([
                'user_id' => $user->getKey(),
                'user_name' => (string) ($user->name ?? $user->email ?? ''),
                'event' => $event,
                'auditable_type' => $subject !== null ? $subject::class : null,
                'auditable_id' => $subject?->getKey(),
                'description' => Str::limit($description, 480),
                'changes' => $changes !== null ? self::redact($changes) : null,
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Auditing must never break the operation it records.
        }
    }

    /**
     * Replace the value of any sensitive attribute with a marker, so a secret is
     * never copied into the audit trail.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function redact(array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (in_array($key, self::REDACTED, true)) {
                $changes[$key] = '[hidden]';
            }
        }

        return $changes;
    }
}
