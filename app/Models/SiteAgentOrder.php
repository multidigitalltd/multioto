<?php

namespace App\Models;

use App\Services\Billing\SiteAgentArrearsBilling;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * One stranger's purchase of בוט ניהול האתר, from the form to the working service.
 *
 * It exists because the buyer leaves. They go to Cardcom's page and what comes
 * back is a webhook, minutes later, into a process with no browser and no
 * session — so everything the purchase needs is written down before they go,
 * and everything afterwards is done from this row.
 *
 * Nothing is granted here. The subscription, the site and the number's binding
 * are created only after a verified card capture (or a historical prepaid
 * payment), never by submitting the public form.
 */
class SiteAgentOrder extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    /** Card verified; service active, with payment collected after the month. */
    public const ACTIVE = 'active';

    public const FAILED = 'failed';

    /** The customer installs the plugin themselves, from the codes we show. */
    public const INSTALL_SELF = 'self';

    /** We install it, once they have handed over access. */
    public const INSTALL_BY_US = 'by_us';

    public const INSTALL_MODES = [self::INSTALL_SELF, self::INSTALL_BY_US];

    protected $fillable = [
        'reference', 'customer_id', 'plan_id',
        'buyer_name', 'buyer_email', 'manager_phone', 'manager_name', 'extra_phones',
        'domain', 'site_id', 'total_agorot', 'install_mode', 'status',
        'charge_id', 'subscription_id', 'fulfilled_at', 'trial_days', 'cardcom_low_profile_id', 'billing_mode',
    ];

    /** A free trial: a card was captured and nothing was charged. */
    public function isTrial(): bool
    {
        return (int) $this->trial_days > 0;
    }

    public function isArrears(): bool
    {
        return $this->billing_mode === SiteAgentArrearsBilling::MODE;
    }

    public function requiresCardCapture(): bool
    {
        return $this->isArrears() || $this->isTrial();
    }

    protected function casts(): array
    {
        return [
            'total_agorot' => 'integer',
            'fulfilled_at' => 'datetime',
            'extra_phones' => 'array',
        ];
    }

    /**
     * The additional manager numbers bought with this order, normalised.
     *
     * Always a list of non-empty strings, whatever the column holds: the row may
     * predate the column, and a cast array is only as well-shaped as what was
     * written into it. Everything downstream of here creates bindings and raises
     * a price, so neither may act on a null or a stray blank.
     *
     * @return list<string>
     */
    public function extraPhones(): array
    {
        $phones = is_array($this->extra_phones) ? $this->extra_phones : [];

        return array_values(array_unique(array_filter(
            array_map(fn ($phone): string => is_string($phone) ? trim($phone) : '', $phones),
            fn (string $phone): bool => $phone !== '',
        )));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function installation(): HasOne
    {
        return $this->hasOne(SiteInstallation::class);
    }

    public function isFulfilled(): bool
    {
        return in_array($this->status, [self::PAID, self::ACTIVE], true);
    }

    public function wantsUsToInstall(): bool
    {
        return $this->install_mode === self::INSTALL_BY_US;
    }

    /**
     * The address of a purchase, unguessable on purpose.
     *
     * The pages that show an order — the one Cardcom returns to, the one that
     * carries the connection codes — are public, and they have to be: the buyer
     * has no account yet. So the address IS the credential, and a sequence would
     * hand every purchase to whoever can count.
     */
    public static function newReference(): string
    {
        do {
            $reference = 'SA-'.Str::upper(Str::random(12));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /** @param  Builder<SiteAgentOrder>  $query */
    public function scopeFulfilled(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PAID, self::ACTIVE]);
    }

    /** @param  Builder<SiteAgentOrder>  $query */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::PAID);
    }
}
