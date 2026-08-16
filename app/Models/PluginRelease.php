<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One published build of a plugin.
 *
 * Which one is distributed is an explicit flag, not "the highest version":
 * uploading a build and deciding to ship it to every customer are two different
 * decisions, and comparing version strings in the database is a way to ship the
 * wrong one. Marking a release current clears the flag from its siblings, so
 * there is never a product with two answers to "what do I download".
 */
class PluginRelease extends Model
{
    protected $fillable = [
        'plugin_product_id', 'version', 'zip_path', 'changelog', 'is_current', 'released_at',
    ];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'released_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(function (self $release): void {
            if (! $release->is_current) {
                return;
            }

            static::query()
                ->where('plugin_product_id', $release->plugin_product_id)
                ->whereKeyNot($release->getKey())
                ->where('is_current', true)
                ->update(['is_current' => false]);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PluginProduct::class, 'plugin_product_id');
    }

    /** The version without a leading "v", which is what WordPress compares. */
    public function number(): string
    {
        return ltrim(trim((string) $this->version), 'vV');
    }
}
