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

            // Credentials are good — also flag missing document codes here, so
            // the operator learns about an incomplete setup from the connection
            // test instead of from a failed invoice later.
            $missingCodes = collect([
                'קוד סוג מסמך' => $config['doctype'] ?? null,
                'קוד מע״מ — חייב' => $config['vat_cat_taxable'] ?? null,
                'קוד מע״מ — פטור' => $config['vat_cat_exempt'] ?? null,
                'קוד אמצעי תשלום' => $config['payment_type'] ?? null,
            ])->reject(fn ($value) => filled($value))->keys();

            if ($missingCodes->isNotEmpty()) {
                return ConnectionResult::ok('ההזדהות ללינט תקינה, אך חסרים קודים להנפקת חשבונית: '.$missingCodes->join(', ').'. מלאו ושמרו אותם.');
            }

            return ConnectionResult::ok('החיבור ללינט תקין — ההזדהות והקודים מוגדרים');
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

        // Linet requires a no-VAT income account on an exempt (0%) line; a
        // taxable line uses the item's default income account, so we send
        // nothing there. Verified against the live API.
        $lineIncomeAccount = $vatCategory === VatCategory::Exempt
            ? ($config['income_account_exempt'] ?? null)
            : null;

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
            'docDet' => [array_filter([
                'item_id' => (string) ($config['general_item_id'] ?? '1'),
                'name' => $description,
                'description' => '',
                'qty' => 1,
                'line' => 1,
                'currency_id' => 'ILS',
                'vat_cat_id' => $vatCatId,
                'unit_id' => 0,
                'iItem' => $totalIls,
                'iItemWithVat' => 1,
                'account_id' => filled($lineIncomeAccount) ? (int) $lineIncomeAccount : null,
            ], fn ($v) => $v !== null)],
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

            if ($this->envelopeSucceeded($search->json())) {
                $existing = data_get($search->json(), 'body.0.id');

                if (filled($existing)) {
                    return (int) $existing;
                }
            }

            // NB: the `account` model rejects a `company` parameter (Linet 500s
            // "Parameter company is not allowed for model account"). The account
            // name carries the business name; type 0 = customer.
            $created = $this->post('/create/account', [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'country_id' => 'IL',
                'currency_id' => 'ILS',
                'type' => 0,
            ], timeout: 20);

            if ($this->envelopeSucceeded($created->json())) {
                $newId = data_get($created->json(), 'body.id');

                if (filled($newId)) {
                    return (int) $newId;
                }
            }

            Log::warning('Linet account resolution returned no id', [
                'customer_id' => $customer->id,
                'create_error' => $this->describeError($created->json()),
            ]);

            return null;
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
     *
     * CRITICAL: Linet answers HTTP 200 even for failures. Success is signalled by
     * BOTH envelope status == 200 AND errorCode == 0 (absent = 0). A validation
     * failure comes back as {"status":200,"errorCode":1001,"body":{field:[msgs]}}
     * and an auth failure as {"status":400,"body":"Unauthorized"}. Checking only
     * the HTTP code or `status` would treat a rejected request as success and
     * report a document as created when nothing was — so we gate on errorCode too
     * and throw with Linet's own field-level messages.
     *
     * @return array<mixed>
     */
    protected function unwrap(Response $response): array
    {
        $json = $response->json();

        // HTTP failure with no Linet envelope at all (gateway/transport error).
        if ($response->failed() && data_get($json, 'status') === null) {
            $response->throw();
        }

        if (! $this->envelopeSucceeded($json)) {
            throw new \RuntimeException('Linet: '.$this->describeError($json));
        }

        $body = data_get($json, 'body', $json);

        return is_array($body) ? $body : (array) $body;
    }

    /**
     * A Linet call succeeded only when the envelope status is 200 AND errorCode
     * is 0/absent. Both fields default to their success value when missing.
     */
    protected function envelopeSucceeded(mixed $json): bool
    {
        $status = (int) (data_get($json, 'status') ?? 200);
        $errorCode = (int) (data_get($json, 'errorCode') ?? 0);

        return $status === 200 && $errorCode === 0;
    }

    /**
     * Turn a Linet error envelope into a readable message. Validation failures
     * put a {field: [messages]} map in `body`; auth/transport failures put a
     * plain string there (e.g. "Unauthorized").
     */
    protected function describeError(mixed $json): string
    {
        $body = data_get($json, 'body');

        if (is_array($body)) {
            $parts = [];
            foreach ($body as $field => $messages) {
                $text = is_array($messages) ? implode(' ', $messages) : (string) $messages;
                $parts[] = is_string($field) ? "{$field}: {$text}" : $text;
            }
            $message = implode(' | ', $parts);
        } else {
            $message = (string) ($body ?? data_get($json, 'message') ?? data_get($json, 'text') ?? 'שגיאה לא ידועה');
        }

        return Str::limit(trim($message) ?: 'שגיאה לא ידועה', 250);
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
