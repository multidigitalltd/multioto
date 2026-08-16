<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One way a plugin is sold: a price, a number of sites, and what happens after
 * the sale.
 *
 * The last part is the one that matters. Three shapes exist, and they are
 * genuinely different products rather than different numbers:
 *
 *  · **מנוי** — renews monthly or yearly. Updates for as long as it is paid.
 *  · **חד-פעמי עם עדכונים לתקופה** — paid once; updates for N months, and after
 *    that the plugin keeps working but stops being offered new versions.
 *  · **חד-פעמי בלי עדכונים** — paid once, licensed forever, never updated. This
 *    one has NO expiry date on purpose: the customer owns it, and a licence
 *    that reported "פג תוקף" for something they bought outright would be
 *    telling them their plugin is broken when it is not.
 */
class PluginPlan extends Model
{
    protected $fillable = [
        'plugin_product_id', 'name', 'price_agorot', 'sites_limit',
        'billing_interval', 'updates_months', 'description', 'is_active', 'position',
    ];

    protected function casts(): array
    {
        return [
            'price_agorot' => 'integer',
            'sites_limit' => 'integer',
            'updates_months' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PluginProduct::class, 'plugin_product_id');
    }

    public function billingInterval(): ?BillingInterval
    {
        return $this->billing_interval !== null ? BillingInterval::tryFrom((string) $this->billing_interval) : null;
    }

    public function renews(): bool
    {
        return $this->billingInterval() !== null;
    }

    /** Whether a licence sold on this plan ever receives a newer version. */
    public function includesUpdates(): bool
    {
        return $this->renews() || (int) $this->updates_months > 0;
    }

    /**
     * When a licence sold now stops receiving updates — or null for never.
     *
     * Null means two opposite things depending on the plan, and both are
     * correct: a renewing plan has not been paid past its first term yet (the
     * caller sets that), and a no-updates plan has no expiry at all because the
     * customer owns it outright.
     */
    public function updatesUntil(?Carbon $from = null): ?Carbon
    {
        $from ??= now();

        return match (true) {
            $this->billingInterval() === BillingInterval::Yearly => $from->copy()->addYear(),
            $this->billingInterval() === BillingInterval::Monthly => $from->copy()->addMonth(),
            (int) $this->updates_months > 0 => $from->copy()->addMonths((int) $this->updates_months),
            default => null,
        };
    }

    /** VAT-inclusive price, which is the only number a buyer cares about. */
    public function grossAgorot(bool $exempt = false): int
    {
        return $exempt
            ? $this->price_agorot
            : (int) round($this->price_agorot * (1 + (float) config('billing.vat_rate')));
    }

    /** How the plan reads on a button: "₪236.00 לשנה". */
    public function priceLabel(bool $exempt = false): string
    {
        return Money::ils($this->grossAgorot($exempt)).' '.match ($this->billingInterval()) {
            BillingInterval::Yearly => 'לשנה',
            BillingInterval::Monthly => 'לחודש',
            default => 'חד-פעמי',
        };
    }

    /** The sentence that says what happens after the sale. */
    public function updatesLabel(): string
    {
        return match (true) {
            $this->renews() => 'עדכונים כל עוד המנוי פעיל',
            (int) $this->updates_months > 0 => 'כולל עדכונים ל-'.$this->updates_months.' חודשים',
            default => 'ללא עדכונים — התוסף שלכם לתמיד, בגרסה הנוכחית',
        };
    }

    public function sitesLabel(): string
    {
        return $this->sites_limit === 0
            ? 'אתרים ללא הגבלה'
            : $this->sites_limit.' '.($this->sites_limit === 1 ? 'אתר' : 'אתרים');
    }
}
