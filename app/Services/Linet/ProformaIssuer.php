<?php

namespace App\Services\Linet;

use App\Enums\VatCategory;
use App\Models\Charge;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issues a Linet proforma ("חשבונית עסקה") for a payment demand — a non-fiscal
 * demand for payment produced up front, before the customer pays. The fiscal
 * tax invoice/receipt is still issued later, only after a successful charge
 * (see InvoiceIssuer / IssueInvoiceJob).
 *
 * Idempotent: a charge that already carries a proforma is a success no-op, and
 * the whole issue is serialised per charge so a retry can't create two.
 */
class ProformaIssuer
{
    public function __construct(private LinetClient $linet) {}

    /**
     * @return array{ok: bool, error: ?string, skipped?: bool}
     */
    public function issue(Charge $charge): array
    {
        // Proforma is optional: without a configured document type we simply
        // don't issue one — the demand still goes out.
        if (blank(config('billing.linet.doctype_proforma'))) {
            return ['ok' => true, 'error' => null, 'skipped' => true];
        }

        $charge->loadMissing(['subscription.customer', 'customer']);

        if (filled($charge->proforma_document_id)) {
            return ['ok' => true, 'error' => null]; // Already issued.
        }

        $customer = $charge->resolveCustomer();

        if (! $customer) {
            return ['ok' => false, 'error' => 'לא נמצא לקוח משויך לחיוב.'];
        }

        // Follow the charge's own VAT split (a per-charge exemption sets it to 0).
        $vatCategory = $charge->vat_agorot <= 0 ? VatCategory::Exempt : VatCategory::Taxable;

        $description = $charge->description ?: 'דרישת תשלום';

        $lock = Cache::lock("proforma-issue:{$charge->id}", 120);

        if (! $lock->get()) {
            return ['ok' => true, 'error' => null]; // Another issue is in flight.
        }

        try {
            if (filled($charge->fresh()->proforma_document_id)) {
                return ['ok' => true, 'error' => null];
            }

            $document = $this->linet->issueProforma($charge, $vatCategory, $description);

            if (blank($document['pdf_url']) && filled($document['document_id'])) {
                try {
                    $document['pdf_url'] = $this->linet->documentPdfUrl($document['document_id']);
                } catch (\Throwable) {
                    $document['pdf_url'] = null;
                }
            }

            $charge->update([
                'proforma_document_id' => $document['document_id'],
                'proforma_pdf_url' => $document['pdf_url'],
            ]);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => Str::limit(trim($e->getMessage()) ?: class_basename($e), 200)];
        } finally {
            $lock->release();
        }
    }
}
