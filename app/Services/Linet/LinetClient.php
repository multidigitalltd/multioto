<?php

namespace App\Services\Linet;

use App\Enums\VatCategory;
use App\Models\Charge;
use App\Services\Health\ConnectionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin client for the Linet invoicing API.
 *
 * Documents are issued only after a successful charge. The VAT category
 * (taxable/exempt) is decided per customer by the caller and passed through.
 */
class LinetClient
{
    /**
     * Best-effort connectivity + credentials check. Linet's exact API surface
     * still needs verification against their docs, so this only confirms the
     * endpoint is reachable and the credentials aren't outright rejected — a
     * definitive test is issuing a real document.
     */
    public function testConnection(): ConnectionResult
    {
        $config = config('billing.linet');

        if (blank($config['login_id']) || blank($config['key'])) {
            return ConnectionResult::notConfigured('Login ID / Key לא הוגדרו');
        }

        try {
            $response = Http::baseUrl($config['base_url'])
                ->withHeaders($this->authHeaders())
                ->timeout(10)
                ->get('documents');

            if ($response->status() === 401 || $response->status() === 403) {
                return ConnectionResult::fail('לינט דחתה את פרטי ההזדהות (Login ID / Key / Company ID)');
            }

            if ($response->serverError()) {
                return ConnectionResult::fail('שגיאת שרת בלינט (קוד '.$response->status().')');
            }

            return ConnectionResult::ok('לינט זמינה והפרטים התקבלו (מומלץ לאמת בהנפקת מסמך אמיתי)');
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
        $customer = $charge->subscription->customer;

        $response = $this->request('documents', [
            'type' => 'invrec', // חשבונית מס/קבלה
            'company_id' => config('billing.linet.company_id'),
            'customer' => [
                'name' => $customer->name,
                'vat_number' => $customer->business_number,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
            'vat_type' => $vatCategory === VatCategory::Exempt ? 'exempt' : 'standard',
            'currency' => $charge->currency,
            'lines' => [[
                'description' => $description,
                'quantity' => 1,
                'unit_price' => round($charge->amount_agorot / 100, 2),
                'vat_amount' => round($charge->vat_agorot / 100, 2),
            ]],
            'total' => round($charge->total_agorot / 100, 2),
            'send_email' => (bool) config('billing.linet.email_document'),
        ]);

        return [
            'document_id' => (string) ($response['id'] ?? ''),
            'pdf_url' => $response['pdf_url'] ?? null,
            'allocation_number' => $response['allocation_number'] ?? null,
        ];
    }

    public function getDocument(string $documentId): array
    {
        $config = config('billing.linet');

        $response = Http::baseUrl($config['base_url'])
            ->withHeaders($this->authHeaders())
            ->timeout(30)
            ->get("documents/{$documentId}");

        $response->throw();

        return $response->json() ?? [];
    }

    protected function request(string $path, array $payload): array
    {
        $config = config('billing.linet');

        $response = Http::baseUrl($config['base_url'])
            ->withHeaders($this->authHeaders())
            ->timeout(30)
            ->post($path, $payload);

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Linet authenticates each request with the account's Login ID and Key
     * (from the Linet API settings screen); the Company ID scopes the request
     * to the right business and is also sent in the document payload.
     *
     * NOTE: the exact header/param names must be verified against Linet's
     * current API docs before going live — see docs/architecture.md.
     */
    protected function authHeaders(): array
    {
        $config = config('billing.linet');

        return [
            'X-Login-Id' => $config['login_id'],
            'X-Api-Key' => $config['key'],
            'X-Company-Id' => (string) $config['company_id'],
        ];
    }
}
