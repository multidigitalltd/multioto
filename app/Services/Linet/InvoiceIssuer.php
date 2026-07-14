<?php

namespace App\Services\Linet;

use App\Enums\ChargeStatus;
use App\Enums\DocumentType;
use App\Enums\VatCategory;
use App\Models\Charge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issues a Linet tax invoice/receipt for a successful charge and returns a
 * plain result, so both the async job and a manual "issue invoice" button can
 * surface the exact Linet error instead of failing silently.
 *
 * Idempotent under concurrency: a charge that already has an invoice is a
 * success no-op, and the whole issue is serialised behind a per-charge lock so
 * a race between the async job and the manual button (or a double-click) can
 * NEVER create two Linet documents for one transaction. The `invoices.charge_id`
 * unique index is the last line of defence; the lock stops the duplicate Linet
 * call before it ever happens.
 */
class InvoiceIssuer
{
    public function __construct(private LinetClient $linet) {}

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function issue(Charge $charge): array
    {
        $charge->loadMissing(['subscription.customer', 'subscription.plan', 'subscription.site', 'customer', 'invoice']);

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

        // Follow the CHARGE, not the customer flag: a per-charge exemption (a
        // manual charge marked "פטור ממע״מ") sets vat_agorot to 0, and the
        // invoice must match. For a normal charge this is identical to the
        // customer's flag (exempt customer → 0 VAT → exempt invoice).
        $vatCategory = $charge->vat_agorot <= 0 ? VatCategory::Exempt : VatCategory::Taxable;

        // Preflight: never call Linet with missing configuration. Linet's own
        // errors for these cases are cryptic ("סוג מסמך לא תקין" וכד'); this
        // names exactly which settings are absent and where to fill them.
        $missing = $this->missingSettings($vatCategory);

        if ($missing->isNotEmpty()) {
            return [
                'ok' => false,
                'error' => 'הגדרות לינט חסרות: '.$missing->join(', ').'. מלאו אותן בהגדרות ← מפתחות אינטגרציות ← לינט, שמרו ונסו שוב.',
            ];
        }

        // Subscription charges describe the plan + period; one-off (manual)
        // charges carry their own free-text description.
        $description = $charge->subscription
            ? sprintf('%s — %s עד %s',
                $charge->subscription->chargeLabel(),
                $charge->period_start->format('d/m/Y'),
                $charge->period_end->format('d/m/Y'),
            )
            : ($charge->description ?: 'חיוב');

        // Serialise issuance per charge: only one caller may talk to Linet at a
        // time. A concurrent issue (async job racing the manual button, or a
        // double-click) must never produce a second Linet document.
        $lock = Cache::lock("invoice-issue:{$charge->id}", 120);

        if (! $lock->get()) {
            // Another issue is already in flight — treat as a success no-op
            // rather than calling Linet a second time.
            return ['ok' => true, 'error' => null];
        }

        try {
            // Re-check under the lock with a fresh read: the in-flight issue may
            // have committed the invoice between our first check and the lock.
            if ($charge->invoice()->exists()) {
                return ['ok' => true, 'error' => null];
            }

            $document = $this->linet->issueDocument($charge, $vatCategory, $description);

            // The create response carries no PDF link — fetch it now (best
            // effort: a failure here must never fail an issued invoice; the
            // download button can backfill the link later).
            if (blank($document['pdf_url']) && filled($document['document_id'])) {
                try {
                    $document['pdf_url'] = $this->linet->documentPdfUrl($document['document_id']);
                } catch (\Throwable) {
                    $document['pdf_url'] = null;
                }
            }

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
        } finally {
            $lock->release();
        }
    }

    /**
     * Hebrew names of the Linet settings required for this document that are
     * not configured. The exempt VAT code is only required for exempt customers.
     *
     * @return Collection<int, string>
     */
    protected function missingSettings(VatCategory $vatCategory): Collection
    {
        $config = config('billing.linet');

        $required = [
            'Login ID' => $config['login_id'] ?? null,
            'Key' => $config['key'] ?? null,
            'Company ID' => $config['company_id'] ?? null,
            'קוד סוג מסמך' => $config['doctype'] ?? null,
            'קוד אמצעי תשלום' => $config['payment_type'] ?? null,
        ];

        $required[$vatCategory === VatCategory::Exempt ? 'קוד מע״מ — פטור' : 'קוד מע״מ — חייב'] =
            $vatCategory === VatCategory::Exempt
                ? ($config['vat_cat_exempt'] ?? null)
                : ($config['vat_cat_taxable'] ?? null);

        return collect($required)->reject(fn ($value) => filled($value))->keys();
    }
}
