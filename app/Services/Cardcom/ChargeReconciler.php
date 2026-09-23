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

        // Only manual/one-off charges are reconciled here. Subscription charges
        // use a different external id and recover via the dunning machine.
        if ($charge->subscription_id !== null) {
            return 'pending';
        }

        // Which rail took the money is asked in the order the answer is
        // certain, not by which id happens to be on the row.
        //
        // A payment demand carries a hosted page AND can be charged against the
        // saved card from the panel, so both ids are present on the same row.
        // Branching on the low-profile id alone asked Cardcom about a hosted
        // session nobody paid, got "nothing here", and left a demand pending
        // for ever — with the money already taken off the card.
        //
        // So the transaction WE initiated is asked about first. When none was
        // ever initiated, Cardcom has no such external id and this costs one
        // lookup before falling through to the hosted session, which is the
        // same answer as before. Reconciliation only runs on charges already
        // stuck pending, so that extra call is rare by construction.
        $external = "manual-{$charge->id}";

        if (blank($charge->cardcom_low_profile_id)) {
            $result = $this->cardcom->transactionByExternalId($external);
        } else {
            // Both rails are possible on this row. The speculative lookup is
            // rescued because for most such rows no token charge was ever
            // initiated, and Cardcom answering that question badly must not
            // abort a reconciliation that the hosted session can still settle.
            // A lookup that cannot answer is not a confirmed success, which is
            // the only thing this class ever acts on.
            $result = rescue(fn (): array => $this->cardcom->transactionByExternalId($external), [], report: false);

            if (blank($this->transactionId($result))) {
                $result = $this->cardcom->getLpResult($charge->cardcom_low_profile_id);
            }
        }

        $code = (string) ($result['ResponseCode'] ?? '');
        $confirmedSuccess = in_array($code, ['0', '700', '701'], true);
        $tranId = $this->transactionId($result);

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

    /** Cardcom puts the transaction id in one of two places depending on the call. */
    private function transactionId(array $result): int|string|null
    {
        return $result['TranzactionId'] ?? ($result['TranzactionInfo']['TranzactionId'] ?? null);
    }
}
