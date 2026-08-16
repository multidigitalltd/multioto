<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
