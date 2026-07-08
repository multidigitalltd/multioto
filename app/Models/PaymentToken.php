<?php

namespace App\Models;

use App\Enums\TokenStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
