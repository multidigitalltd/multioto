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

    /** Never mass-assign or expose the agent token / secrets. */
    protected $hidden = ['mcp_secret', 'agent_token', 'agent_token_plain'];

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
            'agent_token_plain' => 'encrypted',
            'mcp_enabled' => 'boolean',
            'mcp_capabilities' => 'array',
            'mcp_last_seen_at' => 'datetime',
        ];
    }

    /**
     * Issue a fresh per-site token. Its hash authenticates the plugin's
     * check-ins; an encrypted copy is kept so the panel can re-display the code
     * for copying into the site's plugin. Rotating it revokes the previous one.
     */
    public function generateAgentToken(): string
    {
        $token = Str::random(48);
        $this->forceFill([
            'agent_token' => hash('sha256', $token),
            'agent_token_plain' => $token,
        ])->save();

        return $token;
    }

    /**
     * Make sure the site has a full, usable set of connection codes and return
     * them ready to copy into the companion plugin: panel URL, MCP endpoint,
     * MCP secret and update token. Missing pieces are generated (a random secret,
     * the conventional endpoint, a fresh token) so a manager never has to invent
     * anything — the panel is the single source of truth. The "connection active"
     * toggle is left untouched — enabling stays an explicit choice.
     */
    public function ensureAgentCredentials(): array
    {
        if (blank($this->mcp_endpoint)) {
            $this->mcp_endpoint = $this->conventionalMcpEndpoint();
        }

        if (blank($this->mcp_secret)) {
            $this->mcp_secret = Str::random(40);
        }

        if ($this->isDirty()) {
            $this->save();
        }

        // The token lives outside the fillable/dirty set (forceFill); ensure it
        // exists separately.
        if (blank($this->agent_token_plain)) {
            $this->generateAgentToken();
        }

        return [
            'panel_url' => rtrim((string) config('app.url'), '/'),
            'mcp_endpoint' => (string) $this->mcp_endpoint,
            'mcp_secret' => (string) $this->mcp_secret,
            'update_token' => (string) $this->agent_token_plain,
        ];
    }

    /** The MCP endpoint the companion plugin exposes for a given domain. */
    public function conventionalMcpEndpoint(): string
    {
        return 'https://'.$this->domain.'/wp-json/md-agent/v1/mcp';
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
