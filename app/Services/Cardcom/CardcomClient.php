<?php

namespace App\Services\Cardcom;

use App\Models\Customer;
use App\Models\PaymentToken;
use App\Services\Health\ConnectionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            // Per the official v11 spec (CreateLowProfile schema) the required
            // fields are TerminalNumber, ApiName, Amount, SuccessRedirectUrl,
            // FailedRedirectUrl and WebHookUrl. Operation "CreateTokenOnly" is a
            // valid enum value and Document is optional — so this request is
            // spec-complete for capturing a token without charging.
            $response = $this->request('LowProfile/Create', array_filter([
                'Operation' => 'CreateTokenOnly',
                'Amount' => 0,
                'ISOCoinId' => 1, // ILS
                'Language' => 'he',
                'ReturnValue' => 'connection-test',
                'SuccessRedirectUrl' => url('/'),
                'FailedRedirectUrl' => url('/'),
                'WebHookUrl' => url('/'), // required by the spec even for a token-only test
                // J5 authorization (not J2) — matches the real token-capture flow.
                'AdvancedDefinition' => ['JValidateType' => 5],
                // Terminals that mandate a document reject a request without one
                // (error 5046). This session is discarded, so nothing is issued.
                'Document' => $this->buildDocument('בדיקת חיבור', null, null, 'בדיקת חיבור', 0),
            ], fn ($v) => $v !== null), withApiPassword: false);

            $code = (string) ($response['ResponseCode'] ?? '');

            if ($code === '0' && filled($response['Url'] ?? null)) {
                return ConnectionResult::ok('החיבור תקין — המסוף אימת את הבקשה');
            }

            // Log the full raw response so we can diagnose from real data, not
            // guesses. A token-only response carries no card data.
            Log::warning('Cardcom LowProfile/Create (CreateTokenOnly) returned a non-zero code', [
                'response' => $response,
            ]);

            $desc = $response['Description'] ?? 'תשובה לא צפויה מקארדקום';

            // Surface the full raw JSON on screen too, so the exact reason is
            // visible without digging in the server logs.
            $raw = Str::limit(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 400);

            return ConnectionResult::fail("קארדקום דחתה את הבקשה (קוד {$code}): {$desc}".$this->hintForCode($code)."\n\nתשובה גולמית: {$raw}");
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
        $customer = Customer::find($customerId);

        $response = $this->request('LowProfile/Create', array_filter([
            'Operation' => 'CreateTokenOnly',
            'Amount' => 0,
            'ISOCoinId' => 1, // ILS
            'Language' => 'he',
            'ReturnValue' => (string) $customerId,
            'SuccessRedirectUrl' => $successUrl,
            'FailedRedirectUrl' => $failureUrl,
            'WebHookUrl' => $webhookUrl,
            // Item text shown to the card holder on the hosted page. Cardcom
            // renders ProductName when no Document is sent (our default), so this
            // preserves the label the Document used to carry.
            'ProductName' => 'עדכון אמצעי תשלום',
            // Validate the card with a J5 authorization (not J2 simple check).
            // Cards that require an authorization query reject J2 with code
            // 60000042 ("ישנה חובת יציאה לשאילתא"); J5 performs the query.
            'AdvancedDefinition' => ['JValidateType' => 5],
            // Terminals that mandate a document reject a token-only request
            // without one (error 5046). Type comes from config — 'Order' keeps
            // it non-fiscal so Linet stays the invoicer.
            'Document' => $this->buildDocument(
                $customer?->name,
                $customer?->email,
                $customer?->phone,
                'עדכון אמצעי תשלום',
                0,
            ),
        ], fn ($v) => $v !== null), withApiPassword: false);

        $url = (string) ($response['Url'] ?? '');

        // A rejected request returns no absolute URL — often a RELATIVE error path
        // like "/SuccessAndFailDealPage/...?massage=5046" that, if framed, resolves
        // against our own domain and 404s. Treat anything that isn't a real https
        // page as a failure, and log the exact reason so it's diagnosable.
        if (! Str::startsWith($url, 'https://')) {
            Log::warning('Cardcom LowProfile/Create (token) returned no usable URL', [
                'customer_id' => $customerId,
                'response_code' => $response['ResponseCode'] ?? null,
                'description' => $response['Description'] ?? null,
                'response' => $response,
            ]);
        }

        return [
            'url' => $url,
            'low_profile_id' => $response['LowProfileId'] ?? '',
        ];
    }

    /**
     * Create a hosted Low Profile page that CHARGES a card (Operation ChargeOnly)
     * for a one-off / walk-in customer. The card is entered on Cardcom's secure
     * page — never here. The result is delivered to the webhook; we match it back
     * to the pending charge by the returned LowProfileId.
     *
     * @return array{url: string, low_profile_id: string}
     */
    public function createChargeLowProfile(int $chargeId, int $totalAgorot, string $description, ?string $name, ?string $email, ?string $phone, string $successUrl, string $failureUrl, string $webhookUrl): array
    {
        $amountNis = round($totalAgorot / 100, 2);

        $response = $this->request('LowProfile/Create', array_filter([
            'Operation' => 'ChargeOnly',
            'Amount' => $amountNis,
            'ISOCoinId' => 1, // ILS
            'Language' => 'he',
            'ReturnValue' => "charge:{$chargeId}",
            'SuccessRedirectUrl' => $successUrl,
            'FailedRedirectUrl' => $failureUrl,
            'WebHookUrl' => $webhookUrl,
            // Shown to the card holder on the hosted page when no Document is
            // sent (our default) — otherwise the charge would be text-less.
            'ProductName' => $description,
            'Document' => $this->buildDocument($name, $email, $phone, $description, $amountNis),
        ], fn ($v) => $v !== null), withApiPassword: false);

        return [
            'url' => $response['Url'] ?? '',
            'low_profile_id' => $response['LowProfileId'] ?? '',
        ];
    }

    /**
     * Fetch the authoritative result of a completed Low Profile session (used by
     * the webhook to confirm a hosted charge). The webhook body itself is
     * minimal, so we read the transaction result here.
     */
    public function getLpResult(string $lowProfileId): array
    {
        return $this->request('LowProfile/GetLpResult', [
            'LowProfileId' => $lowProfileId,
        ], withApiPassword: false);
    }

    /**
     * Charge a stored token. Amount is integer agorot; Cardcom expects ILS units.
     *
     * Per Cardcom v11 docs the token is a TOP-LEVEL `Token` field (not under
     * `Advanced`). ExternalUniqueTranId gives Cardcom server-side idempotency.
     * A Document is attached because some terminals require one (error 5046);
     * the config document_type ('Order' by default) keeps it non-fiscal so the
     * real tax invoice is still issued by Linet after the charge (§7).
     */
    public function chargeToken(PaymentToken $token, int $totalAgorot, string $description, string $externalUniqueId): ChargeResult
    {
        $customer = $token->customer;
        $amountNis = round($totalAgorot / 100, 2);

        $payload = array_filter([
            'Token' => $token->cardcom_token,
            'Amount' => $amountNis,
            'ISOCoinId' => 1, // ILS
            // Cardcom's documented field name is ExternalUniqTranId (v11). This
            // gives server-side idempotency AND is the key we reconcile by.
            'ExternalUniqTranId' => $externalUniqueId,
            'ProductName' => $description,
            'Document' => $this->buildDocument(
                $customer?->name,
                $customer?->email,
                $customer?->phone,
                $description,
                $amountNis,
            ),
        ], fn ($v) => $v !== null);

        // Include the stored expiry (MMYY) when we have it — some terminals
        // require it alongside the token.
        if ($token->expiry_month && $token->expiry_year) {
            $payload['CardExpirationMMYY'] = sprintf('%02d%02d', $token->expiry_month, $token->expiry_year % 100);
        }

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
     * Look up a transaction by the ExternalUniqueTranId we sent when charging.
     * Used to reconcile a charge whose response we never recorded (a crashed
     * job or lost webhook) — Cardcom is the source of truth. ResponseCode 0
     * (or 700/701) means the charge exists and succeeded.
     */
    public function transactionByExternalId(string $externalId): array
    {
        // Per the v11 spec this endpoint requires only TerminalNumber + ApiName
        // (no ApiPassword) and the key field is ExternalUniqTranId.
        return $this->request('Transactions/GetTransactionByExternalUniqTran', [
            'ExternalUniqTranId' => $externalId,
        ], withApiPassword: false);
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

    /**
     * Actionable Hebrew hint for well-known Cardcom response codes. 5046 ("No
     * InvoiceHead data was send") means Cardcom tried to produce a document —
     * even though this request sends no Document object (we invoice via Linet).
     * The raw response is logged and surfaced so the real trigger is visible.
     */
    /**
     * Build the Cardcom Document object, or null when documents are disabled
     * (config document_type empty). The type is configurable: 'Order' (default)
     * is non-fiscal — it satisfies terminals that mandate a document (avoiding
     * error 5046) without issuing a tax invoice, so Linet remains the invoicer.
     * Set it to 'Auto'/'TaxInvoiceAndReceipt' to let Cardcom issue the invoice.
     *
     * @return array<string, mixed>|null
     */
    protected function buildDocument(?string $name, ?string $email, ?string $phone, string $productDescription, float $unitCostNis): ?array
    {
        $type = config('billing.cardcom.document_type');

        if (blank($type)) {
            return null; // Terminal does not require a document — send none.
        }

        return [
            'DocumentTypeToCreate' => $type,
            'IsAllowEditDocument' => true,
            'Name' => $name ?: 'לקוח',
            'Email' => $email ?: '',
            'Mobile' => $phone ?: '',
            'Language' => 'he',
            'Products' => [
                ['Description' => $productDescription, 'UnitCost' => $unitCostNis],
            ],
        ];
    }

    protected function hintForCode(string $code): string
    {
        return match ($code) {
            '5046' => ' — המסוף דורש נתוני מסמך. אנחנו כבר שולחים אובייקט Document; אם השגיאה נמשכת, ייתכן שסוג המסמך אינו נתמך במסוף — נסו לשנות את CARDCOM_DOCUMENT_TYPE (למשל ל-Auto) או שלחו לי את התשובה הגולמית.',
            default => '',
        };
    }
}
