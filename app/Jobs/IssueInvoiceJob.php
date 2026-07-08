<?php

namespace App\Jobs;

use App\Enums\ChargeStatus;
use App\Enums\DocumentType;
use App\Enums\VatCategory;
use App\Models\Charge;
use App\Services\Linet\LinetClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Issue a Linet tax invoice/receipt for a successful charge — and only for a
 * successful charge. Safe to retry: skips if an invoice already exists.
 */
class IssueInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $chargeId) {}

    public function handle(LinetClient $linet): void
    {
        $charge = Charge::with(['subscription.customer', 'subscription.plan', 'invoice'])
            ->find($this->chargeId);

        if (! $charge || $charge->status !== ChargeStatus::Succeeded || $charge->invoice) {
            return;
        }

        $customer = $charge->subscription->customer;
        $vatCategory = $customer->vat_exempt ? VatCategory::Exempt : VatCategory::Taxable;

        $document = $linet->issueDocument(
            $charge,
            $vatCategory,
            sprintf('%s — %s עד %s',
                $charge->subscription->plan->name,
                $charge->period_start->format('d/m/Y'),
                $charge->period_end->format('d/m/Y'),
            ),
        );

        $charge->invoice()->create([
            'customer_id' => $customer->id,
            'linet_document_id' => $document['document_id'],
            'document_type' => DocumentType::TaxInvoiceReceipt,
            'allocation_number' => $document['allocation_number'],
            'vat_category' => $vatCategory,
            'amount_agorot' => $charge->amount_agorot,
            'vat_agorot' => $charge->vat_agorot,
            'total_agorot' => $charge->total_agorot,
            'pdf_url' => $document['pdf_url'],
            'issued_at' => now(),
        ]);
    }
}
