<?php

namespace App\Services\Cardcom;

use App\Enums\ChargeStatus;
use App\Jobs\IssueInvoiceJob;
use App\Models\Charge;

/**
 * Reconciles a charge whose Cardcom result we never recorded — a lost webhook,
 * a crashed job, or a network error after Cardcom already charged the card.
 * Cardcom is the source of truth: we look the transaction up and, ONLY on a
 * confirmed success, mark the charge succeeded and issue the invoice. We never
 * guess a failure, so a card is never re-charged by mistake.
 */
class ChargeReconciler
{
    public function __construct(private CardcomClient $cardcom) {}

    /**
     * @return string the resulting status: 'succeeded' | 'failed' | 'pending'
     */
    public function reconcile(Charge $charge): string
    {
        if ($charge->status !== ChargeStatus::Pending) {
            return $charge->status->value;
        }

        // Hosted (walk-in) charge → look up the Low Profile result; saved-token
        // (manual) charge → look up by the ExternalUniqueTranId we sent.
        $result = filled($charge->cardcom_low_profile_id)
            ? $this->cardcom->getLpResult($charge->cardcom_low_profile_id)
            : $this->cardcom->transactionByExternalId("manual-{$charge->id}");

        $code = (string) ($result['ResponseCode'] ?? '');
        $confirmedSuccess = in_array($code, ['0', '700', '701'], true);
        $tranId = $result['TranzactionId'] ?? ($result['TranzactionInfo']['TranzactionId'] ?? null);

        if (! $confirmedSuccess || blank($tranId)) {
            return 'pending'; // Not confirmed — leave it for the next check.
        }

        $charge->update([
            'status' => ChargeStatus::Succeeded,
            'cardcom_transaction_id' => (string) $tranId,
            'cardcom_response_code' => $code,
            'charged_at' => now(),
        ]);

        IssueInvoiceJob::dispatch($charge->id);

        return 'succeeded';
    }
}
