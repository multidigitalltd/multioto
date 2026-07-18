<?php

namespace App\Jobs;

use App\Enums\BillingInterval;
use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\Concerns\PausesForShabbat;
use App\Models\Charge;
use App\Models\Subscription;
use App\Services\Billing\DunningMachine;
use App\Services\Cardcom\CardcomClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Charge a due subscription against its stored Cardcom token.
 *
 * Idempotency guarantees:
 *  - A per-subscription cache lock ensures a single charge attempt in flight.
 *  - The due-date guard re-checks next_charge_at inside the lock, so a stale
 *    duplicate dispatch becomes a no-op.
 *  - Every attempt is recorded with a unique (subscription, period, attempt)
 *    charge row, and the Cardcom ExternalUniqueTranId carries the same triple
 *    so Cardcom rejects a double submission server-side.
 */
class ChargeSubscriptionJob implements ShouldQueue
{
    use PausesForShabbat;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $subscriptionId) {}

    /** @return array<int, int> */
    protected function shabbatDispatchArgs(): array
    {
        return [$this->subscriptionId];
    }

    public function handle(CardcomClient $cardcom, DunningMachine $dunning): void
    {
        if ($this->rescheduledForShabbat()) {
            return;
        }

        $subscription = Subscription::with(['plan', 'customer', 'token'])
            ->find($this->subscriptionId);

        if (! $subscription || ! $subscription->isChargeable()) {
            return;
        }

        $lock = Cache::lock("charge-subscription:{$subscription->id}", 300);

        if (! $lock->get()) {
            return; // Another charge for this subscription is already in flight.
        }

        try {
            $subscription->refresh()->load(['plan', 'customer', 'token']);

            if ($subscription->next_charge_at === null || $subscription->next_charge_at->isFuture()) {
                return; // Already charged by a concurrent/earlier run.
            }

            $charge = $this->createPendingCharge($subscription);

            $result = $cardcom->chargeToken(
                $subscription->token,
                $charge->total_agorot,
                sprintf('%s — %s עד %s', $subscription->planName(), $charge->period_start->format('d/m/Y'), $charge->period_end->format('d/m/Y')),
                sprintf('sub-%d-%s-a%d', $subscription->id, $charge->period_start->format('Ymd'), $charge->attempt_number),
            );

            $charge->update([
                'status' => $result->success ? ChargeStatus::Succeeded : ChargeStatus::Failed,
                'cardcom_transaction_id' => $result->transactionId,
                'cardcom_response_code' => $result->responseCode,
                'failure_reason' => $result->success ? null : $result->message,
                'charged_at' => $result->success ? now() : null,
            ]);

            if ($result->success) {
                $this->activatePaidPeriod($subscription, $charge);
                IssueInvoiceJob::dispatch($charge->id);
            } else {
                $dunning->handleFailure($subscription, $charge);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Create the pending charge row for the period being collected.
     * During dunning we retry the same unpaid period; otherwise the period
     * starts at the due date.
     */
    protected function createPendingCharge(Subscription $subscription): Charge
    {
        $lastFailed = $subscription->charges()
            ->where('status', ChargeStatus::Failed)
            ->latest('id')
            ->first();

        $periodStart = ($subscription->dunning_stage > 0 && $lastFailed)
            ? $lastFailed->period_start
            : $subscription->next_charge_at->toDateString();

        $periodStart = Carbon::parse($periodStart);
        $periodEnd = $subscription->billingInterval() === BillingInterval::Yearly
            ? $periodStart->copy()->addYear()
            : $periodStart->copy()->addMonth();

        // An earlier attempt whose outcome is unknown (the HTTP call threw
        // after Cardcom may have processed it) leaves a pending row. Reuse it —
        // same attempt number → same ExternalUniqueTranId → Cardcom dedupes
        // server-side instead of charging the period twice.
        $pending = $subscription->charges()
            ->where('status', ChargeStatus::Pending)
            ->whereDate('period_start', $periodStart)
            ->latest('id')
            ->first();

        if ($pending) {
            return $pending;
        }

        $attempt = (int) $subscription->charges()
            ->whereDate('period_start', $periodStart)
            ->max('attempt_number') + 1;

        return $subscription->charges()->create([
            'amount_agorot' => $subscription->basePriceAgorot(),
            'vat_agorot' => $subscription->vatAgorot(),
            'total_agorot' => $subscription->totalChargeAgorot(),
            'currency' => config('billing.currency'),
            'status' => ChargeStatus::Pending,
            'attempt_number' => $attempt,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    /**
     * Success: roll the subscription into the paid period, clear dunning, and
     * restore the site if a previous dunning cycle suspended it.
     */
    protected function activatePaidPeriod(Subscription $subscription, Charge $charge): void
    {
        $wasSuspended = $subscription->status === SubscriptionStatus::Suspended;

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $charge->period_start,
            'current_period_end' => $charge->period_end,
            'next_charge_at' => $charge->period_end->copy()->startOfDay(),
            'dunning_stage' => 0,
        ]);

        if ($wasSuspended && $subscription->site_id) {
            RestoreSiteJob::dispatch($subscription->site_id);
        }

        // The billing day is the natural cadence for the customer's monthly
        // monitoring report (no-op unless the feature is enabled; sent at most
        // once per month).
        SendMonthlyMonitoringReportJob::dispatch($subscription->customer_id);
    }
}
