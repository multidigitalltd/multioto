<?php

namespace App\Services\Linet;

use App\Enums\VatCategory;
use App\Models\Charge;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Linet invoicing API.
 *
 * Documents are issued only after a successful charge. The VAT category
 * (taxable/exempt) is decided per customer by the caller and passed through.
 */
class LinetClient
{
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
