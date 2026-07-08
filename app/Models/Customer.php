<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'business_number', 'business_type', 'vat_exempt', 'email', 'phone',
        'whatsapp_jid', 'cardcom_account_id', 'default_token_id', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'status' => CustomerStatus::class,
            'vat_exempt' => 'boolean',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function paymentTokens(): HasMany
    {
        return $this->hasMany(PaymentToken::class);
    }

    public function defaultToken(): BelongsTo
    {
        return $this->belongsTo(PaymentToken::class, 'default_token_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
