<?php

namespace App\Models;

use App\Support\LicenseKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A licence to run one of our plugins: on how many shops, until when.
 *
 * The key is not here. Only its HMAC is, and that is also what a lookup matches
 * on — so nobody, including us, can read a key back out of this table. It is
 * shown once when issued and sent to the customer; after that a lost key is
 * replaced, not recovered, and the screen says so rather than pretending.
 *
 * Expiry stops UPDATES, never the plugin. A shop that paid for a year and did
 * not renew keeps a working shop; it just stops receiving new versions. Cutting
 * off software somebody is trading on is not a collections tactic.
 */
class License extends Model
{
    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    protected $fillable = [
        'plugin_product_id', 'plugin_plan_id', 'customer_id', 'subscription_id', 'key_hash', 'key_prefix',
        'email', 'sites_limit', 'expires_at', 'includes_updates', 'status', 'notes', 'issued_at', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'issued_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'sites_limit' => 'integer',
            'includes_updates' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PluginProduct::class, 'plugin_product_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PluginPlan::class, 'plugin_plan_id');
    }

    /**
     * Whether this licence is ever offered a newer version.
     *
     * A licence bought outright without updates is valid forever and never
     * updated — the two are separate facts, and collapsing them into an expiry
     * date would tell a customer who owns the plugin that it has expired.
     */
    public function includesUpdates(): bool
    {
        return (bool) $this->includes_updates;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(LicenseSite::class);
    }

    /**
     * Issue a licence and hand back the plaintext key — the only moment it
     * exists outside the customer's hands.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: self, 1: string}
     */
    public static function issue(array $attributes): array
    {
        $key = LicenseKey::generate();

        $license = static::create($attributes + [
            'key_hash' => LicenseKey::hash($key),
            'key_prefix' => LicenseKey::prefix($key),
            'status' => self::ACTIVE,
            'issued_at' => now(),
        ]);

        return [$license, $key];
    }

    /**
     * Replace the key on an existing licence, keeping everything else.
     *
     * This is what "the customer lost the key" looks like: the old one stops
     * working the moment the new one is issued, and that is said out loud
     * wherever this is offered. Recovery is impossible by design.
     */
    public function regenerateKey(): string
    {
        $key = LicenseKey::generate();

        $this->update([
            'key_hash' => LicenseKey::hash($key),
            'key_prefix' => LicenseKey::prefix($key),
        ]);

        return $key;
    }

    /** The licence this key belongs to, or null. Matched on the hash, never on the key. */
    public static function findByKey(string $key): ?self
    {
        if (! LicenseKey::looksValid($key)) {
            return null;
        }

        return static::query()->where('key_hash', LicenseKey::hash($key))->first();
    }

    public function isRevoked(): bool
    {
        return $this->status === self::REVOKED;
    }

    /** A licence with no expiry date never expires — that is what null means here. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->endOfDay()->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->hasExpired();
    }

    /** 0 means unlimited, mirroring the API contract. */
    public function isUnlimited(): bool
    {
        return $this->sites_limit === 0;
    }

    public function seatsUsed(): int
    {
        return $this->sites()->count();
    }

    public function hasFreeSeat(): bool
    {
        return $this->isUnlimited() || $this->seatsUsed() < $this->sites_limit;
    }

    /**
     * Push the expiry out to $through, never backwards.
     *
     * Renewals arrive as successful charges, and a charge can be recorded twice
     * or out of order. Taking the later of the two dates means a replay cannot
     * shorten a licence somebody already paid to extend.
     */
    public function extendThrough(Carbon $through): bool
    {
        if ($this->expires_at !== null && $this->expires_at->greaterThanOrEqualTo($through)) {
            return false;
        }

        $this->update(['expires_at' => $through->toDateString()]);

        return true;
    }

    /** How it reads in the panel. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isRevoked() => 'מבוטל',
            $this->hasExpired() => 'פג תוקף',
            ! $this->includesUpdates() => 'פעיל — ללא עדכונים',
            default => 'פעיל',
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            $this->isRevoked() => 'danger',
            $this->hasExpired() => 'warning',
            default => 'success',
        };
    }
}
