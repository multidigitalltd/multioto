<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorCheck extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'site_id', 'checked_at', 'is_up', 'status_code', 'response_ms', 'error',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'is_up' => 'boolean',
            'status_code' => 'integer',
            'response_ms' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
