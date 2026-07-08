<?php

namespace App\Services\Cardcom;

use App\Models\PaymentToken;
use App\Services\Health\ConnectionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin client for the Cardcom API (v11, token module).
 *
 * Card capture always happens on Cardcom's hosted Low Profile page — card
 * numbers never touch this system. We store and charge token references only.
 * Business logic (retries, dunning, recording) lives in jobs, not here.
 */
class CardcomClient
{
    /**
     * Verify the terminal + API credentials by opening a hosted-page session
     * (no charge, no card — the session is simply discarded). A non-zero
     * ResponseCode means Cardcom rejected the credentials or request.
     */
    public function testConnection(): ConnectionResult
    {
        $config = config('billing.cardcom');

        if (blank($config['terminal_number']) || blank($config['api_name'])) {
            return ConnectionResult::notConfigured('מספר מסוף / API Name לא הוגדרו');
        }

        try {
            $response = $this->request('LowProfile/Create', [
                'Operation' => 'CreateTokenOnly',
                'Amount' => 0,
                'ISOCoinId' => 1, // ILS
                'Language' => 'he',
                'ReturnValue' => 'connection-test',
                'SuccessRedirectUrl' => url('/'),
                'FailedRedirectUrl' => url('/'),
                // No Document object and no ApiPassword — token-only must not enter
                // document-creation mode (which demands InvoiceHead → error 5046).
            ], withApiPassword: false);

            $code = (string) ($response['ResponseCode'] ?? '');

            if ($code === '0' && filled($response['Url'] ?? null)) {
                return ConnectionResult::ok('החיבור תקין — המסוף אימת את הבקשה');
            }

            $desc = $response['Description'] ?? 'תשובה לא צפויה מקארדקום';

            return ConnectionResult::fail("קארדקום דחתה את הבקשה (קוד {$code}): {$desc}");
        } catch (\Throwable $e) {
            return ConnectionResult::fail('לא ניתן להתחבר לקארדקום: '.Str::limit(trim($e->getMessage()) ?: class_basename($e), 120));
        }
    }

    /**
     * Create a hosted Low Profile page URL for capturing a card and returning a token.
     *
     * @return array{url: string, low_profile_id: string}
     */
    public function createTokenLowProfile(int $customerId, string $successUrl, string $failureUrl, string $webhookUrl): array
    {
        $response = $this->request('LowProfile/Create', [
            'Operation' => 'CreateTokenOnly',
            'Amount' => 0,
            'ISOCoinId' => 1, // ILS
            'Language' => 'he',
            'ReturnValue' => (string) $customerId,
            'SuccessRedirectUrl' => $successUrl,
            'FailedRedirectUrl' => $failureUrl,
            'WebHookUrl' => $webhookUrl,
            // No Document object and no ApiPassword — token-only must not enter
            // document-creation mode (which demands InvoiceHead → error 5046).
        ], withApiPassword: false);

        return [
            'url' => $response['Url'] ?? '',
            'low_profile_id' => $response['LowProfileId'] ?? '',
        ];
    }

    /**
     * Charge a stored token. Amount is integer agorot; Cardcom expects ILS units.
     *
     * Per Cardcom v11 docs the token is a TOP-LEVEL `Token` field (not under
     * `Advanced`). We deliberately omit the `Document` object — invoices are
     * issued separately through Linet after a successful charge (§7), not by
     * Cardcom. ExternalUniqueTranId gives Cardcom server-side idempotency.
     */
    public function chargeToken(PaymentToken $token, int $totalAgorot, string $description, string $externalUniqueId): ChargeResult
    {
        $payload = [
            'Token' => $token->cardcom_token,
            'Amount' => round($totalAgorot / 100, 2),
            'ISOCoinId' => 1, // ILS
            'ExternalUniqueTranId' => $externalUniqueId,
            'ProductName' => $description,
        ];

        // Include the stored expiry (MMYY) when we have it — some terminals
        // require it alongside the token.
        if ($token->expiry_month && $token->expiry_year) {
            $payload['CardExpirationMMYY'] = sprintf('%02d%02d', $token->expiry_month, $token->expiry_year % 100);
        }

        // No ApiPassword / Document — we charge only; invoices are issued by Linet.
        $response = $this->request('Transactions/Transaction', $payload, withApiPassword: false);

        $code = (string) ($response['ResponseCode'] ?? '');

        return new ChargeResult(
            success: $code === '0',
            transactionId: isset($response['TranzactionId']) ? (string) $response['TranzactionId'] : null,
            responseCode: $code,
            message: $response['Description'] ?? null,
        );
    }

    /**
     * Fetch transaction details for status reconciliation.
     */
    public function getTransactionInfo(string $transactionId): array
    {
        return $this->request('Transactions/GetTransactionInfoById', [
            'TranzactionId' => $transactionId,
        ]);
    }

    /**
     * POST to Cardcom with the terminal auth merged in. ApiPassword is only sent
     * when needed (refunds / document creation) — sending it on token/hosted-page
     * calls pushes Cardcom into document mode and triggers error 5046.
     */
    protected function request(string $path, array $payload, bool $withApiPassword = true): array
    {
        $config = config('billing.cardcom');

        $auth = [
            // Cardcom expects TerminalNumber as an integer, not a string.
            'TerminalNumber' => (int) $config['terminal_number'],
            'ApiName' => $config['api_name'],
        ];

        if ($withApiPassword && filled($config['api_password'])) {
            $auth['ApiPassword'] = $config['api_password'];
        }

        $response = Http::baseUrl($config['base_url'])
            ->timeout(30)
            ->post($path, array_merge($payload, $auth));

        $response->throw();

        return $response->json() ?? [];
    }
}
