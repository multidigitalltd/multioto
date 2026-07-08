<?php

namespace App\Models;

use App\Enums\DunningChannel;
use App\Enums\DunningStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DunningEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id', 'charge_id', 'stage', 'channel', 'template_key', 'status', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'channel' => DunningChannel::class,
            'status' => DunningStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}
