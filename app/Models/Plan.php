<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Services\Billing\SiteAgentArrearsBilling;
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
        'extra_number_price_agorot', 'message_price_agorot', 'included_messages', 'writing_price_agorot', 'included_writings', 'trial_days', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'price_agorot' => 'integer',
            'extra_number_price_agorot' => 'integer',
            'message_price_agorot' => 'integer',
            'trial_days' => 'integer',
            'included_messages' => 'integer',
            'writing_price_agorot' => 'integer',
            'included_writings' => 'integer',
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
     * Plans a stranger may buy בוט ניהול האתר on, in the order they should be read.
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

    /** One bot message, VAT as the customer pays it — or null when messages are not billed. */
    public function messageGrossAgorot(bool $vatExempt = false): ?int
    {
        return $this->billsMessages()
            ? $this->withVat((int) $this->message_price_agorot, $vatExempt)
            : null;
    }

    /** Does this plan charge for the messages the bot sends? */
    public function billsMessages(): bool
    {
        return (int) $this->message_price_agorot > 0;
    }

    /** Does this plan charge for long texts the bot writes onto the site? */
    public function billsWritings(): bool
    {
        return (int) $this->writing_price_agorot > 0;
    }

    public function writingGrossAgorot(bool $vatExempt = false): ?int
    {
        return $this->billsWritings()
            ? $this->withVat((int) $this->writing_price_agorot, $vatExempt)
            : null;
    }

    /** Does this plan start with a free trial? */
    public function hasTrial(): bool
    {
        return (int) $this->trial_days > 0;
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

    /*
    |--------------------------------------------------------------------------
    | Prices as a business reads them: before VAT, with the VAT said out loud
    |--------------------------------------------------------------------------
    |
    | The buyer here is a business, and a business compares net prices because
    | the VAT comes back to it. Quoting it gross reads as 18% more expensive
    | than every competitor quoting net.
    |
    | So these say the net figure AND that VAT is added — never the net figure
    | alone, which would be the same sentence a customer later disputes against
    | their invoice. Where VAT does not apply to the plan at all, the suffix is
    | omitted rather than printed as a promise of a tax nobody will charge.
    |
    | The gross helpers above stay exactly as they are: everything that bills,
    | invoices or tells an existing customer what they will pay goes through
    | them, VAT-exempt flag included. These are for the shop window only.
    |
    */

    /** "₪149 לחודש + מע״מ" — the net price, with the VAT named rather than hidden. */
    public function netPriceLabel(): string
    {
        return Money::ils((int) $this->price_agorot).' '.$this->intervalLabel().$this->vatSuffix();
    }

    /** Exact net quote for a personal bot month, including the selected seats. */
    public function siteAgentMonthlyNetAgorot(int $extraNumbers = 0, int $cycleIndex = 0): int
    {
        return $this->siteAgentMonthlyShare((int) $this->price_agorot, $cycleIndex)
            + $this->siteAgentMonthlyExtraNetAgorot($extraNumbers, $cycleIndex);
    }

    public function siteAgentMonthlyExtraNetAgorot(int $extraNumbers = 1, int $cycleIndex = 0): int
    {
        return $this->siteAgentMonthlyShare(max(0, $extraNumbers) * (int) $this->extra_number_price_agorot, $cycleIndex);
    }

    public function siteAgentMonthlyNetLabel(): string
    {
        return Money::ils($this->siteAgentMonthlyNetAgorot()).' לחודש'.$this->vatSuffix();
    }

    public function siteAgentMonthlyExtraNetLabel(): ?string
    {
        if (! $this->sellsExtraNumbers()) {
            return null;
        }

        return (int) $this->extra_number_price_agorot === 0
            ? 'ללא תוספת תשלום'
            : Money::ils($this->siteAgentMonthlyExtraNetAgorot()).' לחודש'.$this->vatSuffix();
    }

    private function siteAgentMonthlyShare(int $amount, int $cycleIndex): int
    {
        return $this->billing_interval === BillingInterval::Yearly
            ? SiteAgentArrearsBilling::annualShare($amount, max(0, $cycleIndex))
            : $amount;
    }

    /** The net price of one extra manager number per cycle, or null when none are sold. */
    public function extraNumberNetLabel(): ?string
    {
        if (! $this->sellsExtraNumbers()) {
            return null;
        }

        if ((int) $this->extra_number_price_agorot === 0) {
            return 'ללא תוספת תשלום';
        }

        return Money::ils((int) $this->extra_number_price_agorot).' '.$this->intervalLabel().$this->vatSuffix();
    }

    /** The net price of one bot message, or null when messages are not billed. */
    public function messageNetLabel(): ?string
    {
        return $this->billsMessages()
            ? Money::ils((int) $this->message_price_agorot).$this->vatSuffix()
            : null;
    }

    public function writingNetLabel(): ?string
    {
        return $this->billsWritings()
            ? Money::ils((int) $this->writing_price_agorot).$this->vatSuffix()
            : null;
    }

    private function vatSuffix(): string
    {
        return $this->vat_applies ? ' + מע״מ' : '';
    }

    public function intervalLabel(): string
    {
        return $this->billing_interval === BillingInterval::Yearly ? 'לשנה' : 'לחודש';
    }

    public function withVat(int $agorot, bool $vatExempt): int
    {
        if ($vatExempt || ! $this->vat_applies) {
            return $agorot;
        }

        return $agorot + (int) round($agorot * config('billing.vat_rate'));
    }
}
