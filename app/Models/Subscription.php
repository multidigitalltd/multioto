<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'plan_id', 'site_id', 'token_id', 'status',
        'current_period_start', 'current_period_end', 'next_charge_at',
        'price_agorot_override', 'dunning_stage', 'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'next_charge_at' => 'datetime',
            'price_agorot_override' => 'integer',
            'dunning_stage' => 'integer',
            'canceled_at' => 'datetime',
        ];
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

    public function token(): BelongsTo
    {
        return $this->belongsTo(PaymentToken::class, 'token_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function dunningEvents(): HasMany
    {
        return $this->hasMany(DunningEvent::class);
    }

    /**
     * Subscriptions whose next charge is due now — the scheduler's work list.
     */
    public function scopeDueForCharge(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->whereNotNull('token_id')
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', now());
    }

    /**
     * Subscriptions in arrears — past-due or suspended. The single definition of
     * "debtor" shared by the Collections screen, the Debtors widget and reminders.
     */
    public function scopeInArrears(Builder $query): Builder
    {
        return $query->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended]);
    }

    /**
     * Effective base price in agorot: locked legacy override when set, plan price otherwise.
     */
    public function basePriceAgorot(): int
    {
        return $this->price_agorot_override ?? $this->plan->price_agorot;
    }

    /**
     * VAT for this subscription in agorot. Zero when the customer is VAT-exempt
     * or the plan price does not carry VAT on top.
     */
    public function vatAgorot(): int
    {
        if ($this->customer->vat_exempt || ! $this->plan->vat_applies) {
            return 0;
        }

        return (int) round($this->basePriceAgorot() * config('billing.vat_rate'));
    }

    public function totalChargeAgorot(): int
    {
        return $this->basePriceAgorot() + $this->vatAgorot();
    }

    /**
     * Whether a charge may be attempted now. Includes Suspended so a manual
     * "charge now" or a card-update recovery can collect a lapsed debtor and
     * restore the site (activatePaidPeriod). The scheduler does NOT auto-retry
     * suspended subscriptions — dueForCharge scopes to Active/PastDue only.
     */
    public function isChargeable(): bool
    {
        return in_array($this->status, [
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Suspended,
        ], true) && $this->token_id !== null;
    }
}
