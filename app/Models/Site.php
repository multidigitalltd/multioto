<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\SiteStatus;
use App\Enums\SiteType;
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
        'mcp_endpoint', 'mcp_secret', 'mcp_enabled', 'environment', 'site_type',
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
            'site_type' => SiteType::class,
        ];
    }

    /**
     * Classify the site as a store/brochure from its installed plugins
     * (WooCommerce ⇒ store). By default a value already set — by hand or a
     * previous detection — is kept, so the team's manual choice wins; pass
     * $force to re-classify explicitly (the "זהה סוג אתר" action).
     */
    public function applyDetectedType(string $pluginListText, bool $force = false): void
    {
        $detected = SiteType::fromPluginList($pluginListText);

        if ($force) {
            $this->update(['site_type' => $detected]);

            return;
        }

        // Atomic "fill only if still unset": a WHERE site_type IS NULL guard, so
        // a manual choice made during the (slow) MCP round-trip isn't clobbered
        // by this stale instance. Sync the in-memory model only if we won the race.
        $filled = static::whereKey($this->getKey())
            ->whereNull('site_type')
            ->update(['site_type' => $detected]);

        if ($filled > 0) {
            $this->setAttribute('site_type', $detected)->syncOriginalAttribute('site_type');
        }
    }

    /**
     * Keep the MCP endpoint automatic. Newer plugin versions expose their own
     * REST endpoint, so a manager never types an address: on save (for an enabled
     * site with a domain) we derive the conventional endpoint from the domain
     * when it's blank/malformed, and re-derive it when the domain changes and the
     * stored endpoint was itself auto-derived. A deliberate custom endpoint — one
     * that never matched the conventional form — is left untouched.
     */
    protected static function booted(): void
    {
        static::saving(function (self $site): void {
            if (! $site->mcp_enabled || blank($site->domain)) {
                return;
            }

            $endpoint = (string) $site->mcp_endpoint;

            if (blank($endpoint) || substr_count($endpoint, '://') > 1) {
                $site->mcp_endpoint = $site->conventionalMcpEndpoint();

                return;
            }

            // Domain changed: follow it only if the endpoint was auto-derived
            // from the previous domain (otherwise it's a custom endpoint to keep).
            if ($site->isDirty('domain')) {
                $previousDomain = (string) $site->getOriginal('domain');

                if (filled($previousDomain) && $endpoint === $site->conventionalMcpEndpoint($previousDomain)) {
                    $site->mcp_endpoint = $site->conventionalMcpEndpoint();
                }
            }
        });
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
        // Generate when missing, and self-heal a malformed endpoint (e.g. a
        // doubled scheme from a domain saved with "https://").
        if (blank($this->mcp_endpoint) || substr_count((string) $this->mcp_endpoint, '://') > 1) {
            $this->mcp_endpoint = $this->conventionalMcpEndpoint();
        }

        if (blank($this->mcp_secret)) {
            $this->mcp_secret = Str::random(40);
        }

        if ($this->isDirty()) {
            $this->save();
        }

        // Only mint a token when the site has none at all. If a hash already
        // exists but no retrievable copy (a site connected before this column),
        // we must NOT rotate on read — that would 401 the plugin already
        // installed with the old token. Such a token is simply unrecoverable
        // for display; rotating it is an explicit action ("טוקן חדש").
        if (blank($this->agent_token) && blank($this->agent_token_plain)) {
            $this->generateAgentToken();
        }

        return [
            'panel_url' => rtrim((string) config('app.url'), '/'),
            'mcp_endpoint' => (string) $this->mcp_endpoint,
            'mcp_secret' => (string) $this->mcp_secret,
            // Empty when a pre-existing token can't be shown — the view then
            // tells the manager to rotate rather than showing a blank box.
            'update_token' => (string) $this->agent_token_plain,
        ];
    }

    /**
     * The MCP endpoint the companion plugin exposes for this site. The `domain`
     * column may hold a bare host or a full URL (it accepts a URL in the form),
     * so reduce it to just the host — otherwise we'd build https://https://…
     */
    public function conventionalMcpEndpoint(?string $domain = null): string
    {
        // Drop the scheme and any trailing slash, but KEEP the path: a WordPress
        // install in a subdirectory (example.com/blog) exposes its REST root at
        // /blog/wp-json/…, so the path must survive.
        $base = preg_replace('#^https?://#i', '', (string) ($domain ?? $this->domain));
        $base = trim(rtrim((string) $base, '/'));

        return 'https://'.$base.'/wp-json/md-agent/v1/mcp';
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
