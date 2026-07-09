<?php

namespace App\Jobs;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\WebhookEvent;
use App\Services\Cardcom\CardcomClient;
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
        $tokenInfo = $result['TokenInfo'] ?? [];
        $customerId = (int) ($result['ReturnValue'] ?? $payload['ReturnValue'] ?? 0);
        $customer = Customer::find($customerId);

        if ($responseCode !== '0' || ! $customer || empty($tokenInfo['Token'])) {
            Log::warning('Cardcom low profile webhook without a usable token', [
                'webhook_event_id' => $event->id,
                'low_profile_id' => $lowProfileId,
                'response_code' => $responseCode,
                'has_customer' => (bool) $customer,
                'has_token' => ! empty($tokenInfo['Token']),
            ]);
            $event->markProcessed();

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

        $customer->paymentTokens()
            ->whereKeyNot($token->id)
            ->where('status', TokenStatus::Active)
            ->update(['status' => TokenStatus::Replaced]);

        $customer->update(['default_token_id' => $token->id]);

        // Point every non-canceled subscription at the fresh token. A brand-new
        // (Trialing) subscription is activated so the scheduler starts charging
        // it on its due date; anything stuck in dunning is retried right away.
        $customer->subscriptions()
            ->whereNot('status', SubscriptionStatus::Canceled)
            ->each(function ($subscription) use ($token) {
                $subscription->update(['token_id' => $token->id]);

                if ($subscription->status === SubscriptionStatus::Trialing) {
                    $subscription->update(['status' => SubscriptionStatus::Active]);

                    // Immediate-start signup: if the first charge is already due,
                    // collect it now instead of waiting for the scheduler.
                    if ($subscription->next_charge_at && $subscription->next_charge_at->isPast()) {
                        ChargeSubscriptionJob::dispatch($subscription->id);
                    }

                    return;
                }

                if (in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended], true)) {
                    $subscription->update(['next_charge_at' => now()]);
                    ChargeSubscriptionJob::dispatch($subscription->id);
                }
            });

        $event->markProcessed();
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
