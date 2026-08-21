<?php

namespace App\Jobs;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\WebhookEvent;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\CardTokenService;
use App\Services\Notifications\TeamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Process a completed Cardcom Low Profile session. Two shapes arrive here:
 *  - Token capture (CreateTokenOnly): store the returned token, make it the
 *    customer's default, and retry any dunning subscription (recovery, §5).
 *  - Hosted one-off charge (ChargeOnly, manual walk-in): matched by LowProfileId
 *    to a pending charge, confirmed via GetLpResult, then invoiced.
 */
class ProcessCardcomLowProfileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if (! $event || $event->processed_at !== null) {
            return;
        }

        $payload = $event->payload;

        // A hosted one-off charge (walk-in) completes here too — matched by
        // LowProfileId, not ReturnValue (per Cardcom guidance).
        if ($this->finishManualChargeIfMatched($payload, $event)) {
            return;
        }

        // Token capture (subscription setup / card update). Cardcom's webhook
        // body is minimal — the token itself lives in the authoritative
        // GetLpResult, so fetch it whenever the payload doesn't already carry it.
        $lowProfileId = $payload['LowProfileId'] ?? null;
        $result = $payload;

        if ($lowProfileId && empty(data_get($payload, 'TokenInfo.Token'))) {
            $result = app(CardcomClient::class)->getLpResult((string) $lowProfileId);
        }

        $responseCode = (string) ($result['ResponseCode'] ?? '0');
        $customerId = (int) ($result['ReturnValue'] ?? $payload['ReturnValue'] ?? 0);
        $customer = Customer::find($customerId);

        $token = $customer ? app(CardTokenService::class)->storeFromLpResult($customer, $result) : null;

        if ($token === null) {
            // A customer who tried to hand us their card and could not is a
            // billing emergency, not a log line: their next charge fails and
            // dunning starts against somebody who already tried to pay.
            //
            // Cardcom's own words go in — a code on its own ("2", "60000042")
            // says nothing to whoever reads it, and the reason is the only part
            // that tells the team whether to call the customer or fix a
            // terminal setting.
            $reason = trim((string) ($result['Description'] ?? '')) ?: 'לא צוינה סיבה';

            Log::warning('Cardcom low profile webhook without a usable token', [
                'webhook_event_id' => $event->id,
                'low_profile_id' => $lowProfileId,
                'response_code' => $responseCode,
                'description' => $reason,
                'customer_id' => $customer?->id,
                'has_customer' => (bool) $customer,
                'has_token' => ! empty(data_get($result, 'TokenInfo.Token')),
            ]);

            $this->tellTheTeam($customer, $responseCode, $reason, (string) $lowProfileId);
        } elseif ($lowProfileId && (string) $customer->pending_card_lp_id === (string) $lowProfileId) {
            // The webhook handled this capture — clear the pending marker so the
            // manual "sync card" action can't later re-process the same session
            // (which would duplicate the token and re-charge). Only clear when it
            // still points at THIS session, so a newer capture isn't erased.
            $customer->update(['pending_card_lp_id' => null]);
        }

        $event->markProcessed();
    }

    /**
     * Say out loud that a card capture failed.
     *
     * Never allowed to break the webhook: the event is still marked processed
     * either way, because failing here would make Cardcom redeliver a
     * notification we have already recorded.
     */
    private function tellTheTeam(?Customer $customer, string $responseCode, string $reason, string $lowProfileId): void
    {
        try {
            app(TeamNotifier::class)->alert(
                '💳 עדכון כרטיס נכשל'.($customer ? " — {$customer->name}" : ''),
                implode("\n", array_filter([
                    $customer ? "לקוח: {$customer->name} (#{$customer->id})" : 'לא זוהה לקוח בהתראה מקארדקום.',
                    "סיבה מקארדקום: {$reason}",
                    "קוד: {$responseCode}",
                    $lowProfileId !== '' ? "מזהה סשן: {$lowProfileId}" : null,
                    'הכרטיס לא נשמר — החיוב הבא ייכשל אם לא יטופל.',
                ])),
                $customer ? route('filament.admin.resources.customers.view', ['record' => $customer->id]) : null,
            );
        } catch (\Throwable $e) {
            Log::warning('Cardcom card-failure alert could not be sent', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Finalise a hosted one-off charge if this webhook belongs to one. Returns
     * true when handled (so the token-capture path is skipped). The webhook body
     * is minimal, so we read the authoritative result from GetLpResult.
     */
    private function finishManualChargeIfMatched(array $payload, WebhookEvent $event): bool
    {
        $lowProfileId = $payload['LowProfileId'] ?? null;

        if (! $lowProfileId) {
            return false;
        }

        $charge = Charge::where('cardcom_low_profile_id', $lowProfileId)
            ->where('status', ChargeStatus::Pending)
            ->first();

        if (! $charge) {
            return false;
        }

        $result = app(CardcomClient::class)->getLpResult((string) $lowProfileId);
        $code = (string) ($result['ResponseCode'] ?? '');
        $success = $code === '0';
        $tranId = $result['TranzactionId'] ?? ($result['TranzactionInfo']['TranzactionId'] ?? null);

        $charge->update([
            'status' => $success ? ChargeStatus::Succeeded : ChargeStatus::Failed,
            'cardcom_transaction_id' => $tranId ? (string) $tranId : null,
            'cardcom_response_code' => $code,
            'failure_reason' => $success ? null : ($result['Description'] ?? 'החיוב נכשל'),
            'charged_at' => $success ? now() : null,
        ]);

        if ($success) {
            $this->storeTokenForManualCharge($charge, $result);
            IssueInvoiceJob::dispatch($charge->id);
        }

        $event->markProcessed();

        return true;
    }

    /**
     * Save the card token captured during a hosted one-off charge, so a walk-in
     * customer becomes reusable for future manual charges. Best-effort — a
     * missing token never fails the charge.
     */
    private function storeTokenForManualCharge(Charge $charge, array $result): void
    {
        $tokenInfo = $result['TokenInfo'] ?? [];
        $customer = $charge->customer;

        if (! $customer || empty($tokenInfo['Token'])) {
            return;
        }

        $token = $customer->paymentTokens()->create([
            'cardcom_token' => $tokenInfo['Token'],
            'card_last4' => isset($tokenInfo['CardLast4Digits']) ? (string) $tokenInfo['CardLast4Digits'] : null,
            'card_brand' => $tokenInfo['CardBrand'] ?? null,
            'expiry_month' => $tokenInfo['CardMonth'] ?? null,
            'expiry_year' => $tokenInfo['CardYear'] ?? null,
            'status' => TokenStatus::Active,
        ]);

        if (! $customer->default_token_id) {
            $customer->update(['default_token_id' => $token->id]);
        }
    }
}
