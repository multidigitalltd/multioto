<?php

namespace App\Models;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Charge extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id', 'customer_id', 'amount_agorot', 'vat_agorot', 'total_agorot', 'currency',
        'payment_method',
        'status', 'attempt_number', 'cardcom_transaction_id', 'cardcom_response_code',
        'failure_reason', 'description', 'invoice_notes', 'lines', 'cardcom_low_profile_id', 'cardcom_pay_url', 'cardcom_bit_url',
        'demand_sent_at', 'demand_channel', 'due_at', 'demand_reminder_count', 'demand_reminders_log', 'demand_reminders_paused',
        'proforma_document_id', 'proforma_pdf_url', 'period_start', 'period_end', 'charged_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_agorot' => 'integer',
            'vat_agorot' => 'integer',
            'total_agorot' => 'integer',
            'status' => ChargeStatus::class,
            'attempt_number' => 'integer',
            'lines' => 'array',
            'demand_sent_at' => 'datetime',
            'due_at' => 'date',
            'demand_reminder_count' => 'integer',
            'demand_reminders_log' => 'array',
            'demand_reminders_paused' => 'boolean',
            'period_start' => 'date',
            'period_end' => 'date',
            'charged_at' => 'datetime',
        ];
    }

    /**
     * The invoice lines to bill, normalised to integer agorot. Uses the stored
     * multi-line breakdown when present; otherwise a single line synthesised
     * from the charge description and total — so callers never special-case.
     *
     * @return array<int, array{name: string, qty: int, unit_price_agorot: int}>
     */
    public function invoiceLines(): array
    {
        $lines = collect($this->lines ?? [])
            ->map(fn (array $line): array => [
                'name' => (string) ($line['name'] ?? ''),
                'qty' => max(1, (int) ($line['qty'] ?? 1)),
                'unit_price_agorot' => (int) ($line['unit_price_agorot'] ?? 0),
            ])
            ->filter(fn (array $line): bool => $line['name'] !== '' && $line['unit_price_agorot'] > 0)
            ->values()
            ->all();

        if ($lines !== []) {
            return $lines;
        }

        return [[
            'name' => $this->description ?: 'חיוב',
            'qty' => 1,
            'unit_price_agorot' => $this->total_agorot,
        ]];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Direct customer link for one-off (manual) charges that have no
     * subscription. Subscription charges reach the customer via the subscription.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * What a customer currently owes: pending charges we actually asked them to
     * pay. A pending charge with no demand behind it is mid-processing, not a
     * debt — telling somebody they owe money for one would be a demand nobody
     * decided to send.
     *
     * A charge belongs to a customer directly OR through one of their
     * subscriptions, and both have to be matched: subscription charges carry no
     * customer_id of their own, so the direct test alone reports a debtor with
     * a clean slate.
     *
     * The single definition, shared by the portal, the ticket acknowledgement
     * and anything else that names a number to a customer — two of those
     * quoting different totals is worse than either being wrong alone.
     */
    public function scopeOpenDebtFor(Builder $query, Customer|int $customer): Builder
    {
        $customerId = $customer instanceof Customer ? $customer->id : $customer;

        return $query
            ->where('status', ChargeStatus::Pending)
            ->whereNotNull('demand_sent_at')
            ->where(fn (Builder $q) => $q
                ->where('customer_id', $customerId)
                ->orWhereHas('subscription', fn (Builder $s) => $s->where('customer_id', $customerId)));
    }

    /**
     * Money that is expected to come again, as a constraint on a charge query.
     *
     * "Has a subscription_id" is the obvious test and it is wrong in both
     * directions, which is why this is a definition and not an inline `where`:
     *
     * - An INSTALLMENT PLAN is stored as a subscription (see ManualCharge's
     *   "פריסה לתשלומים"), but it is one sale split into payments and it stops.
     *   Counting its instalments as recurring income reports a business that
     *   will keep earning from a sale that has already ended.
     * - A RENEWING PLUGIN PLAN bought in the storefront is the opposite: the
     *   first term is charged on a hosted page before any subscription exists,
     *   so that charge carries no subscription_id at all. Its subscription is
     *   created afterwards and linked to the licence, never back to the charge —
     *   so the first term of a genuinely recurring sale reads as one-off.
     *
     * The plugin side is asked through the order rather than by back-filling
     * subscription_id onto the charge, which would look tidier and would also
     * silently stop ChargeReconciler from ever reconciling those charges — it
     * skips anything carrying a subscription.
     *
     * @param  Builder<Charge>  $query
     * @return Builder<Charge>
     */
    public function scopeRecurringRevenue(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereHas('subscription', fn (Builder $s) => $s->whereNull('installments_total'))
            ->orWhereHas('pluginOrder.plan', fn (Builder $p) => $p->whereNotNull('billing_interval')));
    }

    /**
     * The storefront order this charge paid for, when it is one.
     *
     * Hung off the charge rather than only the other way round because the
     * question "was this sale a renewing one" is asked from the charge.
     */
    public function pluginOrder(): HasOne
    {
        return $this->hasOne(PluginOrder::class);
    }

    /** The customer behind this charge, whether one-off or via a subscription. */
    public function resolveCustomer(): ?Customer
    {
        return $this->subscription?->customer ?? $this->customer;
    }

    /**
     * The card this charge would actually be taken from, if any.
     *
     * The customer's default card when it can still take money, otherwise their
     * most recent one that can.
     *
     * "Can take money" is `PaymentToken::chargeable()`, never `status` alone.
     * Nothing in this system walks the table to restamp cards, so a card that
     * expired two years ago still reads "פעיל" — and selecting on status would
     * hand the charger a card every bank will decline, which then marks the
     * demand failed and drops it out of the collection flow over a card nobody
     * ever tried to fix.
     *
     * A replaced card is excluded too: card capture marks the superseded token
     * TokenStatus::Replaced.
     *
     * One definition, because two screens disagreeing about this is a button
     * that offers to charge a card that is not there — or worse, hides itself
     * from a customer who does have one. It reads the customer through
     * resolveCustomer() for the same reason the invoice issuer does: a charge
     * may hang off a subscription rather than carry the customer directly.
     */
    public function chargeableToken(): ?PaymentToken
    {
        $customer = $this->resolveCustomer();

        if ($customer === null) {
            return null;
        }

        $default = $customer->defaultToken;

        if ($default && $default->status === TokenStatus::Active && ! $default->hasExpired()) {
            return $default;
        }

        return $customer->paymentTokens()
            ->chargeable()
            ->latest('id')
            ->first();
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
