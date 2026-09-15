<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A signup link a manager issued, and the card exemption it may carry.
 *
 * The default everywhere is that a card is required — `card_exempt` is false
 * unless somebody deliberately set it, and an invite without one is simply a
 * prefilled link. The exemption is single-use and attributed, because waiving
 * the card is a decision about one customer and not a mode the business runs in.
 */
class SignupInvite extends Model
{
    use HasFactory;

    protected $fillable = [
        'token', 'name', 'email', 'phone',
        'card_exempt', 'exempt_reason', 'created_by', 'expires_at', 'used_at', 'customer_id',
    ];

    protected function casts(): array
    {
        return [
            'card_exempt' => 'boolean',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invite): void {
            if (blank($invite->token)) {
                $invite->token = Str::random(48);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Usable right now: never used, and not past its own expiry. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isOpen(): bool
    {
        return $this->used_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Does this invite actually waive the card?
     *
     * Both conditions, always asked together: a spent or expired invite carries
     * no exemption, however it was created. Anything that reads `card_exempt`
     * on its own would let one waiver become a link that opens customers with
     * no card for as long as somebody keeps forwarding it.
     */
    public function waivesCard(): bool
    {
        return $this->card_exempt && $this->isOpen();
    }

    /** The public link a prospect receives. */
    public function url(): string
    {
        return route('signup', ['invite' => $this->token]);
    }
}
