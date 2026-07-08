<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Encrypted key/value settings. Reads are cached; writes bust the cache and the
 * booted config overrides (see SettingsServiceProvider).
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected const CACHE_KEY = 'settings.all';

    protected function casts(): array
    {
        // Encrypted at rest — secrets never sit in the DB as plaintext.
        return ['value' => 'encrypted'];
    }

    /**
     * All settings as a decrypted key => value map (cached).
     * Uses get() (not pluck) so the encrypted cast is applied on read.
     *
     * @return array<string, string|null>
     */
    public static function map(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->get()->pluck('value', 'key')->all(),
        );
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }
}
