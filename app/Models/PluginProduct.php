<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A WordPress plugin we sell.
 *
 * The slug is how an installed copy identifies itself to us, so it is the one
 * field that must never change after the first sale — every shop out there is
 * asking about updates by that name.
 */
class PluginProduct extends Model
{
    protected $fillable = [
        'slug', 'name', 'homepage', 'description',
        'requires', 'requires_php', 'tested', 'is_active',
        'github_repo', 'github_token', 'pack_from_source', 'github_synced_at', 'github_error',
        'price_agorot', 'billing_interval', 'default_sites_limit',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pack_from_source' => 'boolean',
            'github_synced_at' => 'datetime',
            // A token that can read a private repository is a credential, and
            // credentials are not readable from a stolen database dump.
            'github_token' => 'encrypted',
            'price_agorot' => 'integer',
            'default_sites_limit' => 'integer',
        ];
    }

    /** Does a sale of this plugin renew itself, and how often. */
    public function billingInterval(): ?BillingInterval
    {
        return $this->billing_interval !== null
            ? BillingInterval::tryFrom((string) $this->billing_interval)
            : null;
    }

    /** How long one paid term lasts, from $from. Null for a one-off sale. */
    public function termEnd(Carbon $from): ?Carbon
    {
        return match ($this->billingInterval()) {
            BillingInterval::Yearly => $from->copy()->addYear(),
            BillingInterval::Monthly => $from->copy()->addMonth(),
            default => null,
        };
    }

    public function releases(): HasMany
    {
        return $this->hasMany(PluginRelease::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /** The release being handed out, or null when nothing has been published yet. */
    public function currentRelease(): ?PluginRelease
    {
        return $this->releases()->where('is_current', true)->latest('id')->first();
    }

    /** Compatibility as WordPress wants to hear it, falling back to the configured defaults. */
    public function requires(): string
    {
        return $this->requires ?: (string) config('licensing.defaults.requires');
    }

    public function requiresPhp(): string
    {
        return $this->requires_php ?: (string) config('licensing.defaults.requires_php');
    }

    public function tested(): string
    {
        return $this->tested ?: (string) config('licensing.defaults.tested');
    }
}
