<?php

namespace App\Models;

use App\Enums\BroadcastChannel;
use App\Enums\BroadcastStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Broadcast extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject', 'body', 'channel', 'segment', 'status', 'scheduled_at', 'sent_count',
    ];

    protected function casts(): array
    {
        return [
            'channel' => BroadcastChannel::class,
            'segment' => 'array',
            'status' => BroadcastStatus::class,
            'scheduled_at' => 'datetime',
            'sent_count' => 'integer',
        ];
    }
}
