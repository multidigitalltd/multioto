<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\ChargeStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Jobs\SyncSiteAgentServiceStateJob;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use HasFactory;

    /**
     * Payment methods that are collected by hand (not via a saved card).
     *
     * PaymentMethod::manualValues() is the live source now — this is the same
     * list written out, kept only so a test can assert the two never drift.
     */
    public const MANUAL_PAYMENT_METHODS = ['standing_order', 'bank_transfer', 'checks'];

    protected $fillable = [
        'customer_id', 'plan_id', 'external_ref', 'name', 'billing_interval', 'vat_applies',
        'installments_total',
        'site_id', 'token_id', 'payment_method', 'card_fallback_days', 'status',
        'current_period_start', 'current_period_end', 'next_charge_at', 'card_expiry_alerted_at',
        'price_agorot_override', 'agent_extra_numbers', 'dunning_stage', 'canceled_at',
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
            'agent_extra_numbers' => 'integer',
            'installments_total' => 'integer',
            'card_fallback_days' => 'integer',
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

        // The security card, for customers who agreed to it.
        //
        // A subscription collected by hand gets the standing fallback allowance
        // — pay by transfer as agreed, and if the payment does not arrive, the
        // card covers it — but ONLY for a customer who accepted those terms,
        // which the signup form records on them.
        //
        // The consent is read from the customer rather than inferred from "this
        // subscription was created after we changed the policy", and that
        // distinction is the whole guard: a subscription the team opens today
        // for a customer who signed up two years ago would otherwise carry an
        // arrangement that customer was never shown, and their card would be
        // charged on terms nobody put in front of them.
        //
        // It is also stamped at creation rather than read from config when
        // charging, so changing the setting never reaches back into what was
        // already agreed.
        static::creating(function (self $subscription): void {
            $days = (int) config('billing.card_fallback_days', 0);

            if ($days <= 0 || $subscription->card_fallback_days !== null || ! $subscription->isManuallyCollected()) {
                return;
            }

            if ($subscription->customer?->security_card_terms_at !== null) {
                $subscription->card_fallback_days = $days;
            }
        });

        // A new card re-arms the "card expires before next charge" alert: the
        // old warning no longer applies once a fresh token is on file.
        static::updating(function (self $subscription): void {
            if ($subscription->isDirty('token_id')) {
                $subscription->card_expiry_alerted_at = null;
            }
        });

        // A payment plan can also become complete the moment somebody SAVES the
        // instalment count — converting a subscription that has already
        // collected that many periods, or lowering the count to what was
        // already paid. No payment happens on that path, so nothing would close
        // it: it would stay Active with a due date, unchargeable, picked up by
        // the scheduler every quarter of an hour forever and reported as
        // overdue by the money-integrity check. Closing here covers every way
        // the number can be set, not just the two screens that set it today.
        static::saved(function (self $subscription): void {
            if ($subscription->wasChanged('installments_total')) {
                $subscription->closeIfInstallmentPlanComplete();
            }
        });

        // A site-agent customer whose subscription moved deserves to hear about
        // it now rather than at the top of the hour.
        //
        // These hooks are for PROMPTNESS only, never for correctness: the job is
        // a reconciliation that works out the truth for itself, runs hourly
        // regardless, and does nothing when nothing changed. So a path that
        // never triggers it — a plan flag toggled, a status written by a future
        // screen — costs a delay and never a missed notice.
        $syncSiteAgent = function (self $subscription): void {
            // Nothing is queued while the product is switched off. The job would
            // return on its own line one, so this is not about correctness — it
            // is about every subscription written anywhere in the system not
            // dragging a job behind it for a product this installation does not
            // sell.
            if ($subscription->customer_id !== null && (bool) config('siteagent.enabled', false)) {
                SyncSiteAgentServiceStateJob::dispatch($subscription->customer_id);
            }
        };

        // Opened (a customer who lapsed and re-subscribed) and removed are both
        // changes of entitlement; an update is one only when the status moved.
        static::created($syncSiteAgent);
        static::deleted($syncSiteAgent);

        static::updated(function (self $subscription) use ($syncSiteAgent): void {
            if ($subscription->wasChanged('status')) {
                $syncSiteAgent($subscription);
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

    /**
     * The plugin licence this subscription renews, when it renews one.
     *
     * What it changes is what the customer is told when the card fails: a
     * subscription with a site can end in a suspended site, and one that renews
     * a licence never can — the plugin keeps running either way.
     */
    public function license(): HasOne
    {
        return $this->hasOne(License::class);
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
     * How THIS subscription is paid: its own setting, or the customer's when it
     * has none. Null when neither says — which means a card, the default
     * arrangement.
     */
    public function effectivePaymentMethod(): ?string
    {
        return $this->payment_method ?? $this->customer?->payment_method;
    }

    /** Collected by a person (transfer / standing order / cheques). */
    public function isManuallyCollected(): bool
    {
        return PaymentMethod::isManualValue($this->effectivePaymentMethod());
    }

    /**
     * Restrict to subscriptions collected BY HAND under the effective method.
     *
     * The subscription's own column wins when it is set, and only a subscription
     * that says nothing falls through to the customer's. Written as a query
     * rather than a PHP check because every collection screen and the scheduler
     * itself have to ask this of thousands of rows at once — and if the two ever
     * disagreed, a subscription would be on the automatic run and the manual
     * work list at the same time, or on neither.
     */
    public function scopeWhereCollectedByHand(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereIn('payment_method', PaymentMethod::manualValues())
            ->orWhere(fn (Builder $inherit) => $inherit
                ->whereNull('payment_method')
                ->whereHas('customer', fn (Builder $c) => $c
                    ->whereIn('payment_method', PaymentMethod::manualValues()))));
    }

    /** The complement: subscriptions whose effective method is a card. */
    public function scopeWhereCollectedByCard(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            // Explicitly set to a card method — this overrides a customer who is
            // otherwise on a transfer, which is the whole point of the column.
            ->where(fn (Builder $own) => $own
                ->whereNotNull('payment_method')
                ->whereNotIn('payment_method', PaymentMethod::manualValues()))
            // Or silent, and the customer is not on a manual method either. A
            // blank on both sides means a card: that is the default arrangement,
            // and reading it as manual would move the subscription off the
            // automatic run onto a list nobody was told to watch.
            ->orWhere(fn (Builder $inherit) => $inherit
                ->whereNull('payment_method')
                ->where(fn (Builder $c) => $c
                    ->whereDoesntHave('customer', fn (Builder $q2) => $q2
                        ->whereIn('payment_method', PaymentMethod::manualValues())))));
    }

    /**
     * Subscriptions whose next charge is due now — the scheduler's work list.
     *
     * A saved card is no longer enough on its own: a subscription the customer
     * pays by transfer keeps its card on file as a fallback (see
     * scopeDueForCardFallback), and charging it on the ordinary run would take
     * money the customer arranged to send another way.
     */
    public function scopeDueForCharge(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::AUTO_CHARGE_STATUSES)
            ->whereNotNull('token_id')
            ->whereCollectedByCard()
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', now());
    }

    /**
     * Manually-collected subscriptions whose payment never arrived, and whose
     * saved card was set to back them up.
     *
     * The safety property is the grace period, and it is opt-in per
     * subscription: the card is charged only after the collection has been due
     * for card_fallback_days and nobody recorded a payment. Recording one on the
     * "גבייה ידנית" screen rolls next_charge_at forward, which takes the
     * subscription out of this scope — so a transfer that arrived and was
     * written down can never be charged a second time.
     */
    public function scopeDueForCardFallback(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::AUTO_CHARGE_STATUSES)
            ->whereNotNull('token_id')
            ->whereNotNull('card_fallback_days')
            ->whereCollectedByHand()
            ->whereNotNull('next_charge_at')
            // Days are a column, so the cutoff cannot be a single timestamp:
            // each row is late by its own allowance. Date arithmetic is the one
            // thing the three engines spell differently, so it is written per
            // driver — read from THIS query's connection, not the default one,
            // or a query run against another connection would be built with the
            // wrong dialect and fail (or, worse, be built for a driver that
            // happens to parse it differently).
            ->whereRaw(
                match ($query->getConnection()->getDriverName()) {
                    'sqlite' => "datetime(next_charge_at, '+' || card_fallback_days || ' days') <= ?",
                    'pgsql' => "next_charge_at + (card_fallback_days * INTERVAL '1 day') <= ?",
                    default => 'DATE_ADD(next_charge_at, INTERVAL card_fallback_days DAY) <= ?',
                },
                [now()],
            );
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
            && in_array($this->status, self::AUTO_CHARGE_STATUSES, true)
            // A card on file that the subscription is not paid with does not
            // make it self-collecting — somebody still has to go and get the
            // transfer, whatever the fallback does later.
            && ! $this->isManuallyCollected();
    }

    /** Is the saved card standing behind a collection somebody does by hand? */
    public function usesCardFallback(): bool
    {
        return $this->card_fallback_days !== null
            && $this->token_id !== null
            && $this->isManuallyCollected();
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
            // No longer "has no card". A subscription paid by transfer may well
            // have a card on file as a fallback, and it is still collected by
            // hand until that fallback fires — leaving it off this list because
            // a card exists is how the collection quietly stops happening.
            ->whereNot('status', SubscriptionStatus::Canceled)
            ->whereCollectedByHand();
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
            ->whereCollectedByCard();
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
     *
     * Plus the additional manager numbers this subscription pays for. They are
     * added to the price rather than billed on a second subscription so they
     * ride everything the first one already has: one charge, one invoice line,
     * one dunning ladder. A customer with two numbers must not be able to fall
     * behind on half a service.
     *
     * An agreed special price does not include them: a price named in a
     * conversation is the price of the service, and numbers added months later
     * were not part of that conversation.
     */
    public function basePriceAgorot(): int
    {
        return ($this->price_agorot_override ?? $this->plan?->price_agorot ?? 0)
            + $this->extraNumbersAgorot();
    }

    /**
     * What the extra manager numbers add to one cycle, before VAT.
     *
     * Reads the plan's per-number price at charge time rather than a copy at
     * purchase, matching how the base price already behaves: a plan's price is
     * what its subscribers pay. Zero when the plan stopped selling extra numbers,
     * which is the safe direction — the alternative is charging for a line item
     * the plan can no longer name a price for.
     */
    public function extraNumbersAgorot(): int
    {
        $each = $this->plan?->extra_number_price_agorot;

        if ($each === null || $this->agent_extra_numbers < 1) {
            return 0;
        }

        return (int) $each * (int) $this->agent_extra_numbers;
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
        // A finished payment plan is never chargeable again. This sits in front
        // of EVERY charge path — the scheduler, "charge now", a dunning retry,
        // a card-update recovery — because a fifteenth payment on a fourteen
        // payment plan is money taken from someone who does not owe it, and
        // that is discovered by the customer rather than by us.
        if ($this->installmentPlanComplete()) {
            return false;
        }

        return in_array($this->status, [
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Suspended,
        ], true) && $this->token_id !== null;
    }

    /*
    | ----------------------------------------------------------------
    | פריסת תשלומים
    | ----------------------------------------------------------------
    |
    | חוב שנפרס למספר תשלומים קבוע. הכל כמו במנוי רגיל — אותה גבייה, אותו
    | דאנינג, אותה חשבונית — חוץ מזה שיש לו סוף שהמערכת מכירה.
    */

    /** מנוי שהוא פריסת תשלומים (ולא מנוי מתמשך). */
    public function isInstallmentPlan(): bool
    {
        return $this->installments_total !== null && $this->installments_total > 0;
    }

    /**
     * כמה תשלומים כבר נגבו.
     *
     * נספר מהחיובים עצמם, ולא מעמודה שמישהו צריך לזכור לקדם: רק חיוב שהצליח
     * נחשב, ולכן חיוב שנכשל ונגבה שוב בדאנינג נספר פעם אחת ולא פעמיים. הספירה
     * היא לפי תקופות ולא לפי שורות — 14 תשלומים ייגבו, לא 13.
     */
    public function installmentsPaid(): int
    {
        if (! $this->isInstallmentPlan()) {
            return 0;
        }

        return (int) $this->charges()
            ->where('status', ChargeStatus::Succeeded)
            ->distinct()
            ->count('period_start');
    }

    /** כמה תשלומים נותרו (לעולם לא שלילי). */
    public function installmentsRemaining(): int
    {
        return $this->isInstallmentPlan()
            ? max(0, (int) $this->installments_total - $this->installmentsPaid())
            : 0;
    }

    /** היתרה שנותרה לגבייה, באגורות. */
    public function installmentsRemainingAgorot(): int
    {
        return $this->installmentsRemaining() * $this->totalChargeAgorot();
    }

    /** סך הפריסה כולה, באגורות. */
    public function installmentsTotalAgorot(): int
    {
        return $this->isInstallmentPlan()
            ? (int) $this->installments_total * $this->totalChargeAgorot()
            : 0;
    }

    /** האם כל התשלומים כבר נגבו. */
    public function installmentPlanComplete(): bool
    {
        return $this->isInstallmentPlan() && $this->installmentsRemaining() === 0;
    }

    /**
     * סגירת פריסה שהסתיימה — נקראת אחרי כל תשלום שנקלט, מכל מסלול.
     *
     * גם הסטטוס וגם תאריך החיוב הבא מתאפסים: הסטטוס כדי שהמסכים יראו שהסתיים,
     * ותאריך החיוב כדי שאפילו קריאה ידנית ל"חייב עכשיו" לא תמצא מה לגבות.
     * מחזירה true אם נסגרה עכשיו.
     */
    public function closeIfInstallmentPlanComplete(): bool
    {
        if (! $this->installmentPlanComplete() || $this->status === SubscriptionStatus::Canceled) {
            return false;
        }

        $this->update([
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => now(),
            'next_charge_at' => null,
            'dunning_stage' => 0,
        ]);

        return true;
    }

    /**
     * התיאור שמלווה חיוב אחד — בכרטיס, בחשבונית ובמסך החיובים.
     *
     * במנוי רגיל זו התקופה שנגבתה ("01/09/2026 עד 01/10/2026"), כי זה בדיוק מה
     * שהלקוח קנה. בפריסת חוב זו אמירה שגויה: התשלום אינו עבור חודש כלשהו אלא
     * חלק מסכום שסוכם, ותאריכים על החיוב קוראים כאילו נגבה כאן שירות חודשי.
     * שם הספירה היא מה שאומר משהו — "תשלום 3 מתוך 14".
     *
     * מספר התשלום נגזר מהתקופה של החיוב עצמו — ראו installmentNumberFor.
     */
    public function chargeDescription(Carbon $periodStart, Carbon $periodEnd): string
    {
        if ($this->isInstallmentPlan()) {
            return sprintf(
                '%s — תשלום %d מתוך %d',
                $this->chargeLabel(),
                $this->installmentNumberFor($periodStart),
                $this->installments_total,
            );
        }

        return sprintf('%s — %s עד %s', $this->chargeLabel(), $periodStart->format('d/m/Y'), $periodEnd->format('d/m/Y'));
    }

    /**
     * מספרו הסידורי של התשלום שגובה את התקופה הזו.
     *
     * נספרות התקופות ששולמו **לפני** התקופה הזו, ועוד אחת. זה נראה כמו פרט
     * טכני והוא ההבדל בין תיאור נכון לתיאור שגוי בכל מקום שבו הוא מופיע:
     *
     *  · החשבונית מונפקת אחרי שהחיוב סומן כמוצלח, ולכן ספירה של "כמה שולמו עד
     *    עכשיו ועוד אחד" כוללת את החיוב הנוכחי — והחשבונית הראשונה בפריסה
     *    הייתה יוצאת ללקוח עם "תשלום 2 מתוך 14";
     *  · שורה היסטורית במסך החיובים מחושבת מחדש בכל טעינה, וספירה שמסתמכת על
     *    ההווה הייתה נותנת לכל השורות הישנות את אותו מספר ככל שהפריסה מתקדמת;
     *  · ניסיון חוזר על תקופה שנכשלה נושא את אותו מספר, כי התקופות שלפניה לא
     *    השתנו.
     */
    public function installmentNumberFor(Carbon $periodStart): int
    {
        $paidBefore = (int) $this->charges()
            ->where('status', ChargeStatus::Succeeded)
            ->whereDate('period_start', '<', $periodStart->toDateString())
            ->distinct()
            ->count('period_start');

        return min($paidBefore + 1, (int) $this->installments_total);
    }

    /** "תשלום 5 מתוך 14 · נותרו 4,500 ₪" — או null למנוי רגיל. */
    public function installmentSummary(): ?string
    {
        if (! $this->isInstallmentPlan()) {
            return null;
        }

        $paid = $this->installmentsPaid();

        if ($paid >= (int) $this->installments_total) {
            return "שולמו כל {$this->installments_total} התשלומים";
        }

        return sprintf(
            'שולמו %d מתוך %d · נותרו %s',
            $paid,
            $this->installments_total,
            Money::ils($this->installmentsRemainingAgorot()),
        );
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
