<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use HasFactory;

    /** Customer payment methods that are collected by hand (not via a saved card). */
    public const MANUAL_PAYMENT_METHODS = ['standing_order', 'bank_transfer', 'checks'];

    protected $fillable = [
        'customer_id', 'plan_id', 'external_ref', 'name', 'billing_interval', 'vat_applies',
        'site_id', 'token_id', 'status',
        'current_period_start', 'current_period_end', 'next_charge_at', 'card_expiry_alerted_at',
        'price_agorot_override', 'dunning_stage', 'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_interval' => BillingInterval::class,
            'vat_applies' => 'boolean',
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'next_charge_at' => 'datetime',
            'card_expiry_alerted_at' => 'datetime',
            'price_agorot_override' => 'integer',
            'dunning_stage' => 'integer',
            'canceled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A card-first customer (signed up via /join, card captured) has a saved
        // default token but no subscription yet. When the team later adds a
        // custom subscription, inherit that saved card so it is chargeable —
        // otherwise the scheduler skips it (dueForCharge requires token_id).
        static::creating(function (self $subscription): void {
            if ($subscription->token_id === null && $subscription->customer_id !== null) {
                $subscription->token_id = Customer::whereKey($subscription->customer_id)
                    ->value('default_token_id');
            }
        });

        // A new card re-arms the "card expires before next charge" alert: the
        // old warning no longer applies once a fresh token is on file.
        static::updating(function (self $subscription): void {
            if ($subscription->isDirty('token_id')) {
                $subscription->card_expiry_alerted_at = null;
            }
        });
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
     * The only statuses the scheduler charges on its own. Trialing and Suspended
     * are deliberately out: a trial has nothing to bill yet, and a suspended
     * debtor is collected by hand (isChargeable) rather than auto-retried.
     */
    public const AUTO_CHARGE_STATUSES = [SubscriptionStatus::Active, SubscriptionStatus::PastDue];

    /**
     * Subscriptions whose next charge is due now — the scheduler's work list.
     */
    public function scopeDueForCharge(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::AUTO_CHARGE_STATUSES)
            ->whereNotNull('token_id')
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', now());
    }

    /**
     * Will this renewal collect itself when the date arrives? dueForCharge()
     * minus the date — so a screen may promise "ייגבה לבד" only for rows the
     * scheduler would genuinely pick up. A saved card is not enough on its own:
     * a trialing subscription holding a token is still never auto-charged.
     */
    public function collectsAutomatically(): bool
    {
        return $this->token_id !== null
            && in_array($this->status, self::AUTO_CHARGE_STATUSES, true);
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
     * Manually-collected subscriptions: no saved card and a non-card payment
     * method (bank transfer / standing order / cheques). The scheduler never
     * charges these (dueForCharge requires a token), so the team collects them
     * by hand and records the payment on the "דרישות תשלום" screen.
     */
    public function scopeManuallyCollected(Builder $query): Builder
    {
        return $query
            ->whereNull('token_id')
            ->whereNot('status', SubscriptionStatus::Canceled)
            ->whereHas('customer', fn (Builder $c) => $c->whereIn('payment_method', self::MANUAL_PAYMENT_METHODS));
    }

    /**
     * Subscriptions nobody is going to collect: a customer set to pay BY CARD
     * who has no card on file.
     *
     * These fall through every net. The scheduler skips them (dueForCharge
     * requires a token), so no charge is attempted, so the subscription never
     * turns past-due and never reaches the debtors screen — and the manual
     * collection list ignores them because their payment method is not manual.
     * The result is a customer who quietly owes money while every screen in the
     * system says everything is fine.
     *
     * Scoped to the statuses the scheduler would actually have charged: a
     * subscription still in its trial is not in debt, it is in its trial.
     */
    public function scopeAwaitingCard(Builder $query): Builder
    {
        return $query
            ->whereNull('token_id')
            // The test is "would this have been charged if a card existed?" —
            // AUTO_CHARGE_STATUSES is exactly that question, already answered.
            // A TRIAL owes nothing yet and is deliberately created without a
            // card (see OnboardCustomer), so calling it a debt would turn every
            // newly onboarded customer into a debtor on day one and send them a
            // demand for money they do not owe.
            ->whereIn('status', self::AUTO_CHARGE_STATUSES)
            ->whereNotNull('next_charge_at')
            // A blank payment method means nobody chose bank transfer, and the
            // default arrangement is a card — so it belongs here, not in limbo.
            ->whereHas('customer', fn (Builder $c) => $c
                ->whereNotIn('payment_method', self::MANUAL_PAYMENT_METHODS)
                ->orWhereNull('payment_method'));
    }

    /** Awaiting a card AND already past the date it should have been charged. */
    public function scopeAwaitingCardOverdue(Builder $query): Builder
    {
        return $query->awaitingCard()->where('next_charge_at', '<=', now());
    }

    /**
     * Manually-collected subscriptions whose payment is due now — the team's
     * "collect these" work list, so a bank-transfer/standing-order collection
     * can't slip through unnoticed.
     */
    public function scopeDueForManualCollection(Builder $query): Builder
    {
        return $query
            ->manuallyCollected()
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', now());
    }

    /**
     * Display name: the plan's name, or the free-form subscription name for a
     * plan-less (fully custom) subscription. Never null so charge/invoice
     * descriptions always have a label.
     */
    public function planName(): string
    {
        return $this->plan?->name ?? $this->name ?? 'מנוי';
    }

    /**
     * Human label for a charge/invoice line: the plan name, and — when the
     * subscription is tied to a site — which site it's for. Keeps the operator
     * (and the customer's invoice) from having to guess which site a recurring
     * charge covers. The billing period dates are added by the caller.
     */
    public function chargeLabel(): string
    {
        $label = $this->planName();

        if (filled($this->site?->domain)) {
            $label .= ' — עבור אתר '.$this->site->domain;
        }

        return $label;
    }

    /**
     * Billing interval: the plan's when a plan is set, otherwise the free-form
     * interval, defaulting to monthly for a custom subscription that left it blank.
     */
    public function billingInterval(): BillingInterval
    {
        return $this->plan?->billing_interval ?? $this->billing_interval ?? BillingInterval::Monthly;
    }

    /**
     * Whether VAT is added on top of the base price: the plan's flag when a plan
     * is set, otherwise the free-form flag (defaults to charging VAT when blank).
     */
    public function vatApplies(): bool
    {
        return $this->plan?->vat_applies ?? $this->vat_applies ?? true;
    }

    /**
     * Effective base price in agorot: the per-subscription price (override) when
     * set — always the case for a free-form subscription — the plan price otherwise.
     */
    public function basePriceAgorot(): int
    {
        return $this->price_agorot_override ?? $this->plan?->price_agorot ?? 0;
    }

    /**
     * VAT for this subscription in agorot. Zero when the customer is VAT-exempt
     * or the price does not carry VAT on top.
     */
    public function vatAgorot(): int
    {
        if ($this->customer->vat_exempt || ! $this->vatApplies()) {
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

    /**
     * Make a subscription collectable right now WITHOUT gifting a late payer
     * free days — bill the delayed period and keep the original monthly date.
     *
     * If next_charge_at is already in the past it is the real (overdue) anchor,
     * so we leave it untouched. Otherwise (a cleared anchor at the final dunning
     * stage, or a future one) we look for a genuine UNPAID PAST boundary — the
     * paid-through date, else the oldest unpaid period, else the tracked period
     * end — and pull next_charge_at back to it. Only when there is no overdue
     * period at all (an up-to-date Active subscription being charged early) do we
     * fall back to now(), which collects the upcoming period immediately.
     * ChargeSubscriptionJob then bills the correct period and, on success, rolls
     * next_charge_at to that period's end.
     */
    /**
     * Cancel the subscription: stop billing but keep it on record (its charges
     * and history stay intact). Use delete only to remove one created in error.
     */
    public function cancel(): void
    {
        $this->update([
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now(),
            'next_charge_at' => null,
        ]);
    }

    public function markDueNow(): void
    {
        if ($this->next_charge_at !== null && $this->next_charge_at->isPast()) {
            return;
        }

        $anchor = $this->charges()->where('status', ChargeStatus::Succeeded)->max('period_end')
            ?? $this->charges()->where('status', ChargeStatus::Failed)->min('period_start')
            ?? $this->current_period_end?->toDateString();

        $anchor = $anchor ? Carbon::parse($anchor)->startOfDay() : null;

        // Use the anchor only if it's a real overdue boundary; a future anchor
        // means nothing is owed yet, so an explicit "charge now" collects the
        // next period immediately instead of silently doing nothing.
        $this->update([
            'next_charge_at' => $anchor && $anchor->isPast() ? $anchor : now(),
        ]);
    }
}
