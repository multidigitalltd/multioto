<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * A phone number allowed to drive one site from WhatsApp.
 *
 * Everything the product does starts by finding this row, and nothing happens
 * without one. The number is matched exactly — an unrecognised number is told
 * so rather than guessed at, because the alternative is one customer editing
 * another customer's site because their numbers looked similar.
 */
class SiteAgentSubscriber extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone', 'customer_id', 'site_id', 'name',
        'verification_code', 'verification_sent_at', 'verification_attempts', 'verified_at',
        'revoked_at', 'revoked_reason', 'last_seen_at',
    ];

    /** The service is running for this number, and it has been told so. */
    public const STATE_ACTIVE = 'active';

    /** The subscription does not entitle it right now, and it has been told so. */
    public const STATE_PAUSED = 'paused';

    /** The code is a credential for the duration of its life; never serialise it. */
    protected $hidden = ['verification_code'];

    protected function casts(): array
    {
        return [
            'verification_sent_at' => 'datetime',
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'notified_service_state_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Proved they hold the number, and nobody has taken the access away. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at')->whereNull('revoked_at');
    }

    public function isUsable(): bool
    {
        return $this->verified_at !== null && $this->revoked_at === null;
    }

    /** How many wrong codes end the attempt entirely. */
    public const MAX_VERIFICATION_ATTEMPTS = 5;

    /**
     * Is the code they just sent the one we sent them, still alive, and are
     * they still allowed to be guessing?
     *
     * This is the only thing standing between "somebody messaged our number"
     * and "somebody can rewrite a business's website", so it is bounded three
     * ways: the hash comparison is constant-time, the code expires, and a run
     * of wrong answers burns the code rather than inviting the next guess.
     *
     * A wrong answer counts even when the code has already expired — otherwise
     * the counter is trivially reset by waiting.
     */
    public function codeMatches(string $candidate): bool
    {
        if (blank($this->verification_code) || $this->verification_sent_at === null) {
            return false;
        }

        if ($this->verification_attempts >= self::MAX_VERIFICATION_ATTEMPTS) {
            return false;
        }

        $ttl = max(1, (int) config('siteagent.binding.verification_ttl_minutes', 30));
        $alive = $this->verification_sent_at->gte(now()->subMinutes($ttl));

        if ($alive && Hash::check(trim($candidate), $this->verification_code)) {
            return true;
        }

        $this->increment('verification_attempts');

        return false;
    }
}
