<?php

namespace App\Jobs;

use App\Enums\ChargeStatus;
use App\Enums\TokenStatus;
use App\Models\Charge;
use App\Models\PaymentToken;
use App\Services\Cardcom\CardcomClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Run a one-off (manual) charge against a customer's saved token, then issue a
 * Linet invoice on success — the same guarantees as a subscription charge, for
 * a charge that has no subscription. Heavy/external work stays off the HTTP
 * request (architecture rule #3).
 *
 * Idempotent: only a still-pending charge is processed, under a per-charge lock.
 */
class ProcessManualChargeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // A charge must never be retried automatically.

    public function __construct(public int $chargeId) {}

    public function handle(CardcomClient $cardcom): void
    {
        $lock = Cache::lock("manual-charge:{$this->chargeId}", 120);

        if (! $lock->get()) {
            return; // Already being processed.
        }

        try {
            $charge = Charge::with(['customer.defaultToken', 'customer.paymentTokens'])->find($this->chargeId);

            if (! $charge || $charge->status !== ChargeStatus::Pending) {
                return; // Gone, or already processed.
            }

            $token = $this->activeToken($charge);

            if (! $token) {
                $charge->update(['status' => ChargeStatus::Failed, 'failure_reason' => 'ללקוח אין כרטיס פעיל שמור']);

                return;
            }

            $result = $cardcom->chargeToken(
                $token,
                $charge->total_agorot,
                $charge->description ?: 'חיוב ידני',
                "manual-{$charge->id}",
            );

            $charge->update([
                'status' => $result->success ? ChargeStatus::Succeeded : ChargeStatus::Failed,
                'cardcom_transaction_id' => $result->transactionId,
                'cardcom_response_code' => $result->responseCode,
                'failure_reason' => $result->success ? null : $result->message,
                'charged_at' => $result->success ? now() : null,
            ]);

            if ($result->success) {
                IssueInvoiceJob::dispatch($charge->id);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * The customer's usable card: their default token if it's active, otherwise
     * the most recent active token. Superseded/expired tokens are never charged
     * (card capture marks replaced tokens TokenStatus::Replaced).
     */
    private function activeToken(Charge $charge): ?PaymentToken
    {
        $customer = $charge->customer;

        if (! $customer) {
            return null;
        }

        $default = $customer->defaultToken;

        if ($default && $default->status === TokenStatus::Active) {
            return $default;
        }

        return $customer->paymentTokens()
            ->where('status', TokenStatus::Active)
            ->latest('id')
            ->first();
    }
}
