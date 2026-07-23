<?php

namespace App\Models;

use App\Enums\TokenStatus;
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
     * The last moment this card is still valid: the end of its expiry month.
     * A charge attempted after this will be declined for an expired card.
     * Returns null when the expiry is unknown (never captured).
     */
    public function expiresAt(): ?Carbon
    {
        if ($this->expiry_month === null || $this->expiry_year === null) {
            return null;
        }

        return Carbon::create((int) $this->expiry_year, (int) $this->expiry_month, 1)->endOfMonth();
    }
}
