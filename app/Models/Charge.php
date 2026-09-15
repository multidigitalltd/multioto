<?php

namespace App\Models;

use App\Enums\ChargeStatus;
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

    /** The customer behind this charge, whether one-off or via a subscription. */
    public function resolveCustomer(): ?Customer
    {
        return $this->subscription?->customer ?? $this->customer;
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
