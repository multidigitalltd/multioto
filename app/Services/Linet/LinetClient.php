<?php

namespace App\Services\Linet;

use App\Enums\VatCategory;
use App\Models\Charge;
use App\Services\Health\ConnectionResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin client for the Linet ERP API (https://app.linet.org.il/api).
 *
 * Per Linet's docs, every request is a plain POST whose JSON body carries the
 * auth triple — login_id (API ID), login_hash (API Key), login_company
 * (Company ID). Documents are created at /create/doc with docDet (line items)
 * and docCheq (payments). Documents are issued only after a successful charge;
 * VAT category (taxable/exempt) is decided per customer by the caller.
 *
 * NOTE: doctype, vat_cat_* and payment_type are account-specific codes taken
 * from config — verify them against the Linet account before going live. The
 * create response field names are parsed defensively and should be confirmed
 * against a real Linet response.
 */
class LinetClient
{
    /**
     * Validate the auth triple by running a tiny account search. Linet rejects
     * a bad login pair, so a clean response means the credentials work.
     */
    public function testConnection(): ConnectionResult
    {
        $config = config('billing.linet');

        if (blank($config['login_id']) || blank($config['key']) || blank($config['company_id'])) {
            return ConnectionResult::notConfigured('Login ID / Key / Company ID לא הוגדרו');
        }

        try {
            // Short timeout: the admin-save connection test must not hang the UI.
            // Document creation keeps the default (longer) timeout.
            $response = $this->post('/newsearch/account', ['limit' => 1, 'query' => ['type' => 0]], timeout: 12);

            if ($response->failed()) {
                return ConnectionResult::fail('לינט החזירה שגיאה (קוד '.$response->status().')');
            }

            $json = $response->json();

            // Linet surfaces auth/errors in an `error`/`errors`/`message` field.
            if (is_array($json) && (filled($json['error'] ?? null) || filled($json['errors'] ?? null))) {
                return ConnectionResult::fail('לינט דחתה את פרטי ההזדהות: '.Str::limit((string) ($json['error'] ?? json_encode($json['errors'])), 100));
            }

            return ConnectionResult::ok('החיבור ללינט תקין — ההזדהות התקבלה');
        } catch (\Throwable $e) {
            return ConnectionResult::fail('לא ניתן להתחבר ללינט: '.Str::limit(trim($e->getMessage()) ?: class_basename($e), 120));
        }
    }

    /**
     * Issue a tax invoice/receipt for a successful charge.
     *
     * @return array{document_id: string, pdf_url: ?string, allocation_number: ?string}
     */
    public function issueDocument(Charge $charge, VatCategory $vatCategory, string $description): array
    {
        $config = config('billing.linet');
        // One-off (manual) charges have no subscription — resolve the customer
        // directly in that case.
        $customer = $charge->subscription?->customer ?? $charge->customer;

        $vatCatId = $vatCategory === VatCategory::Exempt
            ? $config['vat_cat_exempt']
            : $config['vat_cat_taxable'];

        // Unit price INCLUDING VAT (iItemWithVat = 1). Linet derives the VAT
        // breakdown from the category, keeping our integer-agorot total exact.
        $totalIls = round($charge->total_agorot / 100, 2);

        $response = $this->post($config['create_doc_path'] ?? '/create/doc', [
            'doctype' => (string) $config['doctype'],
            'status' => 2, // final (non-draft) document
            'currency_id' => 'ILS',
            'country_id' => 'IL',
            'language' => 'he_il',
            'autoRound' => false,
            'sendmail' => $config['email_document'] ? 1 : 0,
            'company' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'refnum_ext' => "charge-{$charge->id}",
            'docDet' => [[
                'name' => $description,
                'description' => '',
                'qty' => 1,
                'currency_id' => 'ILS',
                'vat_cat_id' => $vatCatId,
                'unit_id' => 0,
                'iItem' => $totalIls,
                'iItemWithVat' => 1,
            ]],
            'docCheq' => [[
                'type' => (int) $config['payment_type'],
                'currency_id' => 'ILS',
                'sum' => $totalIls,
                'doc_sum' => $totalIls,
                'line' => 1,
            ]],
        ]);

        $response->throw();
        $json = $response->json() ?? [];

        return [
            'document_id' => (string) $this->firstValue($json, ['id', 'doc_id', 'docId', 'docnum', 'document_id']),
            'pdf_url' => $this->firstValue($json, ['pdf', 'pdf_url', 'url', 'pdfUrl']) ?: null,
            'allocation_number' => $this->firstValue($json, ['allocation_number', 'allocationNum', 'refnum']) ?: null,
        ];
    }

    /**
     * Look up a document by id via the search endpoint.
     */
    public function getDocument(string $documentId): array
    {
        $response = $this->post('/newsearch/doc', ['query' => ['id' => $documentId]]);
        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * POST to a Linet endpoint with the auth triple merged into the body.
     */
    protected function post(string $path, array $payload, int $timeout = 30): Response
    {
        $config = config('billing.linet');

        return Http::baseUrl($config['base_url'])
            ->timeout($timeout)
            ->connectTimeout(8)
            ->post($path, array_merge($payload, [
                'login_id' => $config['login_id'],
                'login_hash' => $config['key'],
                'login_company' => (string) $config['company_id'],
            ]));
    }

    /**
     * First present, non-empty value among candidate keys (Linet's exact
     * response field names still need confirming against a live response).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    protected function firstValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (filled($data[$key] ?? null)) {
                return $data[$key];
            }
        }

        return null;
    }
}
