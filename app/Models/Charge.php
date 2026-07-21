<?php

namespace App\Models;

use App\Enums\ChargeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Charge extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id', 'customer_id', 'amount_agorot', 'vat_agorot', 'total_agorot', 'currency',
        'status', 'attempt_number', 'cardcom_transaction_id', 'cardcom_response_code',
        'failure_reason', 'description', 'invoice_notes', 'lines', 'cardcom_low_profile_id', 'cardcom_pay_url', 'cardcom_bit_url',
        'demand_sent_at', 'demand_channel', 'demand_reminder_count', 'demand_reminders_log', 'demand_reminders_paused',
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
