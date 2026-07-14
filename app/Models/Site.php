<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'domain', 'hosting_ref', 'monitor_url', 'monitor_enabled', 'status',
        'expected_keyword', 'ssl_days_left', 'ssl_alerted_at', 'slow_alerted_at',
        'domain_expiry_at', 'domain_alerted_at',
        'mcp_endpoint', 'mcp_secret', 'mcp_enabled', 'environment',
        'mcp_capabilities', 'mcp_last_seen_at', 'agent_plugin_version',
    ];

    /** Never mass-assign the agent token — it is set only via generateAgentToken(). */
    protected $hidden = ['mcp_secret', 'agent_token'];

    protected function casts(): array
    {
        return [
            'monitor_enabled' => 'boolean',
            'status' => SiteStatus::class,
            'ssl_alerted_at' => 'datetime',
            'slow_alerted_at' => 'datetime',
            'domain_expiry_at' => 'date',
            'domain_alerted_at' => 'datetime',
            'mcp_secret' => 'encrypted',
            'mcp_enabled' => 'boolean',
            'mcp_capabilities' => 'array',
            'mcp_last_seen_at' => 'datetime',
        ];
    }

    /**
     * Issue a fresh per-site token, storing only its hash. The plaintext is
     * returned once (to be installed in the site's plugin) and is never
     * recoverable afterwards — rotating it revokes the previous one.
     */
    public function generateAgentToken(): string
    {
        $token = Str::random(48);
        $this->forceFill(['agent_token' => hash('sha256', $token)])->save();

        return $token;
    }

    /** Resolve a site by the plaintext token its plugin presents (constant-time). */
    public static function forAgentToken(string $token): ?self
    {
        return static::where('agent_token', hash('sha256', $token))->first();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(SiteMemory::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SiteChange::class)->latest('id');
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
