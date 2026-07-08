<?php

namespace App\Models;

use App\Enums\ChargeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Charge extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id', 'amount_agorot', 'vat_agorot', 'total_agorot', 'currency',
        'status', 'attempt_number', 'cardcom_transaction_id', 'cardcom_response_code',
        'failure_reason', 'period_start', 'period_end', 'charged_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_agorot' => 'integer',
            'vat_agorot' => 'integer',
            'total_agorot' => 'integer',
            'status' => ChargeStatus::class,
            'attempt_number' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'charged_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
