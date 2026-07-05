<?php

namespace App\Services\Cardcom;

use App\Models\PaymentToken;
use Illuminate\Support\Facades\Http;

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
     * Create a hosted Low Profile page URL for capturing a card and returning a token.
     *
     * @return array{url: string, low_profile_id: string}
     */
    public function createTokenLowProfile(int $customerId, string $successUrl, string $failureUrl, string $webhookUrl): array
    {
        $response = $this->request('LowProfile/Create', [
            'Operation' => 'CreateTokenOnly',
            'ReturnValue' => (string) $customerId,
            'SuccessRedirectUrl' => $successUrl,
            'FailedRedirectUrl' => $failureUrl,
            'WebHookUrl' => $webhookUrl,
            'Document' => null,
        ]);

        return [
            'url' => $response['Url'] ?? '',
            'low_profile_id' => $response['LowProfileId'] ?? '',
        ];
    }

    /**
     * Charge a stored token. Amount is integer agorot; Cardcom expects ILS units.
     */
    public function chargeToken(PaymentToken $token, int $totalAgorot, string $description, string $externalUniqueId): ChargeResult
    {
        $response = $this->request('Transactions/Transaction', [
            'Amount' => round($totalAgorot / 100, 2),
            'ExternalUniqueTranId' => $externalUniqueId,
            'Advanced' => [
                'Token' => $token->cardcom_token,
                'JValidateType' => null,
            ],
            'ISOCoinId' => 1, // ILS
            'ProductName' => $description,
        ]);

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

    protected function request(string $path, array $payload): array
    {
        $config = config('billing.cardcom');

        $response = Http::baseUrl($config['base_url'])
            ->timeout(30)
            ->post($path, array_merge($payload, [
                'TerminalNumber' => $config['terminal_number'],
                'ApiName' => $config['api_name'],
                'ApiPassword' => $config['api_password'],
            ]));

        $response->throw();

        return $response->json() ?? [];
    }
}
