<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'domain', 'hosting_ref', 'monitor_url', 'monitor_enabled', 'status',
        'expected_keyword', 'ssl_days_left', 'ssl_alerted_at', 'slow_alerted_at',
        'domain_expiry_at', 'domain_alerted_at',
    ];

    protected function casts(): array
    {
        return [
            'monitor_enabled' => 'boolean',
            'status' => SiteStatus::class,
            'ssl_alerted_at' => 'datetime',
            'slow_alerted_at' => 'datetime',
            'domain_expiry_at' => 'date',
            'domain_alerted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function monitorChecks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function openIncident(): HasOne
    {
        return $this->hasOne(Incident::class)->where('status', IncidentStatus::Open);
    }
}
