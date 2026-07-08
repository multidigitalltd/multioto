<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'price_agorot', 'vat_applies', 'billing_interval', 'description', 'active',
    ];

    protected function casts(): array
    {
        return [
            'price_agorot' => 'integer',
            'vat_applies' => 'boolean',
            'billing_interval' => BillingInterval::class,
            'active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
