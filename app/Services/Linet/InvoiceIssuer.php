<?php

namespace App\Services\Linet;

use App\Enums\ChargeStatus;
use App\Enums\DocumentType;
use App\Enums\VatCategory;
use App\Models\Charge;
use Illuminate\Support\Str;

/**
 * Issues a Linet tax invoice/receipt for a successful charge and returns a
 * plain result, so both the async job and a manual "issue invoice" button can
 * surface the exact Linet error instead of failing silently. Idempotent: a
 * charge that already has an invoice is a success no-op.
 */
class InvoiceIssuer
{
    public function __construct(private LinetClient $linet) {}

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function issue(Charge $charge): array
    {
        $charge->loadMissing(['subscription.customer', 'subscription.plan', 'customer', 'invoice']);

        if ($charge->status !== ChargeStatus::Succeeded) {
            return ['ok' => false, 'error' => 'החיוב אינו במצב "הצליח" — אי אפשר להנפיק חשבונית.'];
        }

        if ($charge->invoice) {
            return ['ok' => true, 'error' => null]; // Already issued.
        }

        $customer = $charge->resolveCustomer();

        if (! $customer) {
            return ['ok' => false, 'error' => 'לא נמצא לקוח משויך לחיוב.'];
        }

        $vatCategory = $customer->vat_exempt ? VatCategory::Exempt : VatCategory::Taxable;

        // Subscription charges describe the plan + period; one-off (manual)
        // charges carry their own free-text description.
        $description = $charge->subscription
            ? sprintf('%s — %s עד %s',
                $charge->subscription->plan->name,
                $charge->period_start->format('d/m/Y'),
                $charge->period_end->format('d/m/Y'),
            )
            : ($charge->description ?: 'חיוב');

        try {
            $document = $this->linet->issueDocument($charge, $vatCategory, $description);

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

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => Str::limit(trim($e->getMessage()) ?: class_basename($e), 200)];
        }
    }
}
