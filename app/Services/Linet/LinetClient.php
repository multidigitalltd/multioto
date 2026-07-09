<?php

namespace App\Services\Linet;

use App\Enums\VatCategory;
use App\Models\Charge;
use App\Models\Customer;
use App\Services\Health\ConnectionResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin client for the Linet ERP API (https://app.linet.org.il/api).
 *
 * Every request is a plain POST whose JSON body carries the auth triple —
 * login_id (API ID), login_hash (API Key), login_company (Company ID).
 * Linet wraps every response in an envelope: {"status": 200, "body": ...}
 * where `status` is Linet's own result code (200 = ok) and `body` holds the
 * payload (an array for searches, an object for creates).
 *
 * Documents are created at /create/doc against a resolved account_id (Linet
 * requires the document to belong to an account, so we search/create the
 * account by e-mail first) with docDet (line items) and docCheq (payments).
 * Documents are issued only after a successful charge; VAT category
 * (taxable/exempt) is decided per customer by the caller.
 *
 * The endpoint names, envelope shape and account-first flow mirror Linet's own
 * official WooCommerce plugin (linet-erp-woocommerce-integration). doctype,
 * vat_cat_* and payment_type are account-specific codes taken from config.
 */
class LinetClient
{
    /**
     * Validate the auth triple by running a tiny account search. Linet returns
     * an envelope with status 200 when the login is accepted, so a 200 (or a
     * present body) means the credentials work.
     */
    public function testConnection(): ConnectionResult
    {
        $config = config('billing.linet');

        if (blank($config['login_id']) || blank($config['key']) || blank($config['company_id'])) {
            return ConnectionResult::notConfigured('Login ID / Key / Company ID לא הוגדרו');
        }

        try {
            // Short timeout: the admin-save connection test must not hang the UI.
            // Document creation keeps the default (longer) timeout. The endpoint
            // and body shape match Linet's own plugin (search/account, type 0).
            $response = $this->post('/search/account', ['email' => 'connection-test@multidigital.co.il', 'type' => 0], timeout: 12);

            $json = $response->json();
            $status = data_get($json, 'status');

            // A rejected login comes back either as an HTTP 4xx or as a non-200
            // status inside the envelope — surface both as a credentials failure.
            if ($response->failed() && $status === null) {
                return ConnectionResult::fail('לינט החזירה שגיאת HTTP '.$response->status());
            }

            if ($status !== null && (int) $status !== 200) {
                $message = (string) (data_get($json, 'message') ?? data_get($json, 'body') ?? $status);

                return ConnectionResult::fail('לינט דחתה את פרטי ההזדהות: '.Str::limit($message, 100));
            }

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

        // Linet ties every document to an account — resolve (or create) it by
        // e-mail first, exactly as Linet's own plugin does.
        $accountId = $customer ? $this->resolveAccountId($customer) : null;

        $payload = [
            'doctype' => (string) $config['doctype'],
            'status' => 2, // final (non-draft) document
            'currency_id' => 'ILS',
            'country_id' => 'IL',
            'language' => 'he_il',
            'autoRound' => false,
            'sendmail' => $config['email_document'] ? 1 : 0,
            'company' => $customer?->name,
            'email' => $customer?->email,
            'phone' => $customer?->phone,
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
        ];

        if ($accountId !== null) {
            $payload['account_id'] = $accountId;
        }

        $response = $this->post($config['create_doc_path'] ?? '/create/doc', $payload);

        $body = $this->unwrap($response); // throws on HTTP or envelope-status failure

        return [
            'document_id' => (string) $this->firstValue($body, ['id', 'doc_id', 'docId', 'docnum', 'document_id']),
            'pdf_url' => $this->firstValue($body, ['pdf', 'pdf_url', 'url', 'pdfUrl']) ?: null,
            'allocation_number' => $this->firstValue($body, ['allocation_number', 'allocationNum', 'refnum']) ?: null,
        ];
    }

    /**
     * Resolve the Linet account id for a customer, creating the account if it
     * doesn't exist yet (search/account → create/account), mirroring Linet's
     * own plugin. Best-effort: on any failure we log and return null so the
     * document attempt still proceeds (and surfaces Linet's own error).
     */
    protected function resolveAccountId(Customer $customer): ?int
    {
        try {
            $search = $this->post('/search/account', ['email' => $customer->email, 'type' => 0], timeout: 20);
            $existing = data_get($search->json(), 'body.0.id');

            if (filled($existing)) {
                return (int) $existing;
            }

            $created = $this->post('/create/account', [
                'name' => $customer->name,
                'company' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'country_id' => 'IL',
                'currency_id' => 'ILS',
                'type' => 0,
            ], timeout: 20);

            $newId = data_get($created->json(), 'body.id');

            return filled($newId) ? (int) $newId : null;
        } catch (\Throwable $e) {
            Log::warning('Linet account resolution failed; issuing document without account_id', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Look up a document by id via the search endpoint.
     */
    public function getDocument(string $documentId): array
    {
        $response = $this->post('/search/doc', ['id' => $documentId]);

        return $this->unwrap($response);
    }

    /**
     * Validate a Linet response and return its `body` payload as an array.
     * Linet answers 200 with {"status": 200, "body": ...} on success; anything
     * else (HTTP 4xx/5xx or a non-200 envelope status) is a real error the
     * caller must see, so we throw with Linet's own message.
     *
     * @return array<mixed>
     */
    protected function unwrap(Response $response): array
    {
        $json = $response->json();
        $status = data_get($json, 'status');

        if ($response->failed() && $status === null) {
            $response->throw();
        }

        if ($status !== null && (int) $status !== 200) {
            $message = (string) (data_get($json, 'message') ?? data_get($json, 'error') ?? json_encode(data_get($json, 'body')) ?? $status);

            throw new \RuntimeException('Linet API status '.$status.': '.Str::limit($message, 200));
        }

        $body = data_get($json, 'body', $json);

        return is_array($body) ? $body : (array) $body;
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
