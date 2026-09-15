<?php

namespace App\Models;

use App\Enums\TokenStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Cardcom token reference. Never stores a card number (PCI stays with Cardcom).
 */
class PaymentToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'cardcom_token', 'card_last4', 'card_brand',
        'expiry_month', 'expiry_year', 'status',
    ];

    protected $hidden = ['cardcom_token'];

    protected function casts(): array
    {
        return [
            'status' => TokenStatus::class,
            'expiry_month' => 'integer',
            'expiry_year' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Tokens that could actually take money right now.
     *
     * `status = active` is NOT that question. Nothing in the system walks the
     * table to restamp cards (see hasExpired below), so a card that expired two
     * years ago still says "פעיל" — and a check written against status alone
     * reports a chargeable card where every charge would be declined. That is
     * the failure this predicate exists to prevent, so it asks about the expiry
     * we captured, exactly as hasExpired() does for a single row.
     *
     * An unknown expiry is not treated as expired: it means we never captured
     * one, and calling a working card dead on that basis would strand it.
     */
    public function scopeChargeable(Builder $query): Builder
    {
        // Months since year zero, so one integer comparison covers both fields
        // and no date function has to behave identically on SQLite and
        // Postgres. Both operands are computed here, never taken from input.
        $cutoff = now()->year * 12 + now()->month;

        return $query
            ->where('status', TokenStatus::Active)
            ->where(fn (Builder $q) => $q
                ->whereNull('expiry_month')
                ->orWhereNull('expiry_year')
                // The stored year may be two digits (see expiryYear): read
                // literally, 27 is the year 27 AD and every such card reads as
                // long dead.
                ->orWhereRaw('(CASE WHEN expiry_year < 100 THEN 2000 + expiry_year ELSE expiry_year END) * 12 + expiry_month >= ?', [$cutoff])
            );
    }

    /**
     * The last moment this card is still valid: the end of its expiry month.
     * A charge attempted after this will be declined for an expired card.
     * Returns null when the expiry is unknown (never captured).
     */
    public function expiresAt(): ?Carbon
    {
        if ($this->expiry_month === null || $this->expiry_year === null) {
            return null;
        }

        return Carbon::create($this->expiryYear(), (int) $this->expiry_month, 1)->endOfMonth();
    }

    /**
     * The expiry year as a full year.
     *
     * Cardcom hands the year back in whichever form the capture produced — a
     * card printed 12/27 can arrive as 27 or as 2027. Read literally, the
     * two-digit form puts the expiry in the year 27 AD, which makes every such
     * card read as long expired: the alert fires, the panel shows "פג תוקף",
     * and a perfectly good card looks dead. Charging is unaffected (it sends
     * MMYY either way), which is what lets the discrepancy sit unnoticed.
     */
    public function expiryYear(): int
    {
        $year = (int) $this->expiry_year;

        return $year < 100 ? 2000 + $year : $year;
    }

    /**
     * Is this card past its printed expiry?
     *
     * Read from the expiry we captured, not from `status` — nothing in the
     * system walks the table each night to restamp cards, so a dead card keeps
     * saying "פעיל" until somebody replaces it. That gap is exactly how a
     * customer ends up looking at a card marked active while every charge
     * against it is declined.
     *
     * An unknown expiry is NOT treated as expired: it means we never captured
     * one, and refusing to charge on that basis would strand a working card.
     */
    public function hasExpired(): bool
    {
        return $this->expiresAt()?->isPast() ?? false;
    }

    /** "ויזה · 4580 · 12/27" — the card as a person recognises it. */
    public function label(): string
    {
        $parts = array_filter([
            $this->card_brand,
            $this->card_last4 !== null ? '****'.$this->card_last4 : null,
            $this->expiryLabel(),
        ]);

        return $parts === [] ? "כרטיס #{$this->id}" : implode(' · ', $parts);
    }

    /** The expiry as printed on the card, or null when it was never captured. */
    public function expiryLabel(): ?string
    {
        if ($this->expiry_month === null || $this->expiry_year === null) {
            return null;
        }

        // Two digits, the way the card itself prints it.
        return sprintf('%02d/%02d', $this->expiry_month, $this->expiryYear() % 100);
    }
}
