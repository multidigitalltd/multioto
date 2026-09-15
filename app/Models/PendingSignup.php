<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A filled-in signup waiting for its card.
 *
 * Card first, customer after. Nothing in `customers` is written until Cardcom
 * hands back a token, so the state this row represents — details given, card
 * not yet — can no longer be mistaken for a finished customer, because it is
 * not a customer at all.
 *
 * It holds personal details, so it is deliberately short-lived: an abandoned
 * signup is pruned along with its signature file (see PrunePendingSignupsJob).
 */
class PendingSignup extends Model
{
    use HasFactory;

    /**
     * What Cardcom carries back for us in ReturnValue. Prefixed so a pending
     * signup id can never be read as a customer id — those two numbering
     * sequences overlap, and confusing them would attach a stranger's card.
     */
    public const RETURN_VALUE_PREFIX = 'PS-';

    protected $fillable = [
        'token', 'name', 'contact_name', 'business_number', 'business_type', 'vat_exempt',
        'email', 'phone', 'domain', 'payment_method',
        'signature_path', 'signed_ip', 'terms_accepted_at', 'security_card_terms_at',
        'cardcom_lp_id', 'customer_id', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'vat_exempt' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'security_card_terms_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $pending): void {
            if (blank($pending->token)) {
                $pending->token = Str::random(48);
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Still waiting for a card — not yet converted into a customer. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereNull('completed_at')
            ->where('created_at', '>=', now()->subHours(self::lifetimeHours()));
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null
            && $this->created_at?->gt(now()->subHours(self::lifetimeHours()));
    }

    /**
     * How long a customer has to finish. Long enough to come back to the link
     * in the evening, short enough that we are not holding the details of
     * somebody who walked away weeks ago.
     */
    public static function lifetimeHours(): int
    {
        return max(1, (int) config('billing.signup.pending_lifetime_hours', 72));
    }
}
