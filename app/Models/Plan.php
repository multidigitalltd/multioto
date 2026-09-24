<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'price_agorot', 'vat_applies', 'billing_interval', 'description', 'active', 'includes_site_agent',
        'extra_number_price_agorot', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'price_agorot' => 'integer',
            'extra_number_price_agorot' => 'integer',
            'vat_applies' => 'boolean',
            'billing_interval' => BillingInterval::class,
            'active' => 'boolean',
            'is_public' => 'boolean',
            'includes_site_agent' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Plans a stranger may buy סוכן האתר on, in the order they should be read.
     *
     * Three conditions, and dropping any one of them sells something we cannot
     * deliver: inactive is a plan that was withdrawn, a plan without the agent
     * flag bills a customer for a service the entitlement check will refuse
     * them, and a plan that is not public is a price agreed with one customer.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopePubliclySellable(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->where('is_public', true)
            ->where('includes_site_agent', true)
            ->orderBy('price_agorot');
    }

    /** The price a customer pays for one cycle, VAT included where it applies. */
    public function grossAgorot(bool $vatExempt = false): int
    {
        return $this->withVat((int) $this->price_agorot, $vatExempt);
    }

    /**
     * The price of one additional manager number per cycle, or null when this
     * plan does not sell them.
     *
     * Null and zero are different answers and the caller has to keep them
     * apart — see the migration.
     */
    public function extraNumberGrossAgorot(bool $vatExempt = false): ?int
    {
        return $this->extra_number_price_agorot === null
            ? null
            : $this->withVat((int) $this->extra_number_price_agorot, $vatExempt);
    }

    /** Does this plan sell additional manager numbers at all? */
    public function sellsExtraNumbers(): bool
    {
        return $this->extra_number_price_agorot !== null;
    }

    /** "₪149 לחודש" — the price as a buyer reads it, cycle included. */
    public function priceLabel(bool $vatExempt = false): string
    {
        return Money::ils($this->grossAgorot($vatExempt)).' '.$this->intervalLabel();
    }

    public function intervalLabel(): string
    {
        return $this->billing_interval === BillingInterval::Yearly ? 'לשנה' : 'לחודש';
    }

    private function withVat(int $agorot, bool $vatExempt): int
    {
        if ($vatExempt || ! $this->vat_applies) {
            return $agorot;
        }

        return $agorot + (int) round($agorot * config('billing.vat_rate'));
    }
}
