<?php

namespace App\Jobs;

use App\Enums\ChargeStatus;
use App\Models\Charge;
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

            $customer = $charge->customer;
            $token = $customer?->defaultToken ?? $customer?->paymentTokens()->latest('id')->first();

            if (! $token) {
                $charge->update(['status' => ChargeStatus::Failed, 'failure_reason' => 'ללקוח אין כרטיס שמור']);

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
}
