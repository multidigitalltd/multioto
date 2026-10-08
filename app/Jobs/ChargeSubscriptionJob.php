<?php

namespace App\Jobs;

use App\Enums\BillingInterval;
use App\Enums\ChargeStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Jobs\Concerns\PausesForShabbat;
use App\Jobs\Concerns\WaitsForRestore;
use App\Models\Charge;
use App\Models\Subscription;
use App\Services\Billing\DunningMachine;
use App\Services\Billing\RenewalBreakdown;
use App\Services\Billing\SiteAgentArrearsBilling;
use App\Services\Billing\SiteAgentBillingTransition;
use App\Services\Cardcom\CardcomClient;
use App\Services\Cardcom\ChargeResult;
use App\Services\SiteAgent\SiteAgentUsageMeter;
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
    use WaitsForRestore;

    public int $tries = 1;

    /** Queued by the ordinary due-charge run (Subscription::dueForCharge). */
    public const MODE_SCHEDULED = 'scheduled';

    /** Queued because a manual collection never arrived (dueForCardFallback). */
    public const MODE_FALLBACK = 'fallback';

    /**
     * $manual marks a charge a human explicitly requested right now (operator
     * "חייב עכשיו", or a customer who just updated their card to pay) — those
     * run immediately and never defer to after Shabbat; only SCHEDULED charges
     * hold for the quiet period.
     *
     * $mode records WHICH scheduled run queued this, so the worker can ask that
     * same question again before taking the money. Null means a person asked
     * for this charge directly, and there is no scheduling rule to re-check.
     */
    public function __construct(public int $subscriptionId, public bool $manual = false, public ?string $mode = null) {}

    /** @return array<int, mixed> */
    protected function shabbatDispatchArgs(): array
    {
        return [$this->subscriptionId, $this->manual, $this->mode];
    }

    /** @return array<int, mixed> */
    protected function backupWaitDispatchArgs(): array
    {
        return [$this->subscriptionId, $this->manual, $this->mode];
    }

    /**
     * Is the reason this job was queued still true?
     *
     * A job sits in the queue for a while, and in that time somebody can move
     * the subscription to a bank transfer, or take away the card fallback, or
     * lengthen its grace period. The due-date check alone does not notice any
     * of that — it would charge a card on an arrangement that no longer says
     * to. So the scheduling rule is asked again, here, inside the lock, using
     * the same scope that queued the job in the first place rather than a
     * second copy of its conditions.
     */
    private function stillEligible(): bool
    {
        return match ($this->mode) {
            self::MODE_SCHEDULED => Subscription::query()->whereKey($this->subscriptionId)->dueForCharge()->exists(),
            self::MODE_FALLBACK => Subscription::query()->whereKey($this->subscriptionId)->dueForCardFallback()->exists(),
            // A human asked for this one. Their decision is the authority, and
            // isChargeable() below is the only gate it answers to.
            default => true,
        };
    }

    public function handle(CardcomClient $cardcom, DunningMachine $dunning): void
    {
        if (! $this->manual && $this->rescheduledForShabbat()) {
            return;
        }

        // Money leaving the customer's card while a restore replaces the row
        // recording it is the one outcome no later repair can undo.
        if ($this->heldForBackupOperation()) {
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

            if (! $subscription->isChargeable()) {
                return; // Cancellation or card removal may have won the lock first.
            }

            $transition = app(SiteAgentBillingTransition::class)->transitionLocked($subscription);

            if ($transition === SiteAgentBillingTransition::INCOMPLETE) {
                return; // An ambiguous legacy paid interval needs review before any new charge.
            }

            if ($subscription->next_charge_at === null || $subscription->next_charge_at->isFuture()) {
                return; // Already charged by a concurrent/earlier run.
            }

            if (! $this->stillEligible()) {
                return; // The arrangement changed while this waited in the queue.
            }

            if (SiteAgentArrearsBilling::applies($subscription) && SiteAgentArrearsBilling::period($subscription)['end']->isFuture()) {
                return; // Even a manual request cannot collect a month before it ends.
            }

            $charge = $transition === SiteAgentBillingTransition::UNRESOLVED
                ? $this->legacyRetry($subscription)
                : $this->createPendingCharge($subscription);

            if ($charge === null) {
                return; // Legacy debt without a known attempt must be reviewed, not re-created.
            }

            if (SiteAgentArrearsBilling::applies($subscription) && $charge->status === ChargeStatus::Succeeded) {
                // Recover a completed payment whose worker stopped before the
                // cycle cursor advanced. Never send that payment to Cardcom again.
                $this->activatePaidPeriod($subscription, $charge);

                if ($charge->total_agorot > 0) {
                    IssueInvoiceJob::dispatch($charge->id);
                }

                return;
            }

            $result = SiteAgentArrearsBilling::applies($subscription) && $charge->total_agorot === 0
                ? new ChargeResult(true, null, '0')
                : $cardcom->chargeToken(
                    $subscription->token,
                    $charge->total_agorot,
                    $subscription->chargeDescription($charge->period_start, $charge->period_end),
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

                if ($charge->total_agorot > 0) {
                    IssueInvoiceJob::dispatch($charge->id);
                }
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
        if (SiteAgentArrearsBilling::applies($subscription)) {
            return $this->createArrearsCharge($subscription);
        }

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

        // The plan, the extra numbers and the messages sent so far — see
        // RenewalBreakdown. Counted up to now: the messages counted here are
        // exactly the ones this charge stamps if it succeeds.
        $breakdown = app(RenewalBreakdown::class)->for($subscription, $periodStart, $periodEnd, now());

        return $subscription->charges()->create([
            'amount_agorot' => $breakdown['amount_agorot'],
            'vat_agorot' => $breakdown['vat_agorot'],
            'total_agorot' => $breakdown['total_agorot'],
            'lines' => $breakdown['lines'],
            'usage_until' => $breakdown['usage_until'],
            'currency' => config('billing.currency'),
            // Everything this job collects runs on a card, including a fallback
            // charge for a subscription nominally paid by transfer — and the
            // receipt has to say card, because that is where the money came
            // from and what the customer will see on their statement.
            'payment_method' => PaymentMethod::CreditCard->value,
            'status' => ChargeStatus::Pending,
            'attempt_number' => $attempt,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    private function createArrearsCharge(Subscription $subscription): Charge
    {
        ['start' => $start, 'end' => $end] = SiteAgentArrearsBilling::period($subscription);
        $attempts = $subscription->charges()->whereDate('period_start', $start)->orderBy('id')->get();
        // The closing usage invoice can share its start date with a legacy
        // prepaid base invoice. Only postpaid snapshots belong to this cycle.
        $arrearsAttempts = $attempts->filter(fn (Charge $charge): bool => SiteAgentArrearsBilling::metadata($charge) !== []);
        $settled = $arrearsAttempts->firstWhere('status', ChargeStatus::Succeeded);

        if ($settled !== null) {
            return $settled;
        }

        $pending = $arrearsAttempts->firstWhere('status', ChargeStatus::Pending);

        if ($pending !== null) {
            return $pending;
        }

        $first = $arrearsAttempts->first();
        $breakdown = $first !== null ? $first->only([
            'amount_agorot', 'vat_agorot', 'total_agorot', 'lines', 'usage_until',
        ]) : app(RenewalBreakdown::class)->for($subscription, $start, $end, $end);

        return $subscription->charges()->create([
            ...$breakdown,
            'currency' => $first?->currency ?? config('billing.currency'),
            'payment_method' => PaymentMethod::CreditCard->value,
            'status' => ChargeStatus::Pending,
            'attempt_number' => (int) $attempts->max('attempt_number') + 1,
            'period_start' => $start,
            'period_end' => $end,
        ]);
    }

    private function legacyRetry(Subscription $subscription): ?Charge
    {
        $pending = $subscription->charges()->where('status', ChargeStatus::Pending)->oldest('id')->first();

        if ($pending !== null) {
            return $pending;
        }

        $failed = $subscription->unsettledLegacyCharges()
            ->where('status', ChargeStatus::Failed)->oldest('id')->first();

        if ($failed === null) {
            return null;
        }

        return $subscription->charges()->create([
            ...$failed->only(['amount_agorot', 'vat_agorot', 'total_agorot', 'lines', 'usage_until', 'currency', 'period_start', 'period_end']),
            'payment_method' => PaymentMethod::CreditCard->value,
            'status' => ChargeStatus::Pending,
            'attempt_number' => (int) $subscription->charges()->whereDate('period_start', $failed->period_start)->max('attempt_number') + 1,
        ]);
    }

    /**
     * Success: roll the subscription into the paid period, clear dunning, and
     * restore the site if a previous dunning cycle suspended it.
     */
    protected function activatePaidPeriod(Subscription $subscription, Charge $charge): void
    {
        $wasSuspended = $subscription->status === SubscriptionStatus::Suspended;
        $stopped = $subscription->billing_stop_at !== null;
        $arrears = SiteAgentArrearsBilling::applies($subscription);

        // The messages this charge billed are now billed; the next renewal
        // counts from here.
        app(SiteAgentUsageMeter::class)->settle($charge);

        if ($arrears) {
            $dates = SiteAgentArrearsBilling::afterPayment($subscription, $charge);
        } elseif ($stopped) {
            // Cancellation preserves known legacy debt, not permission to
            // open another prepaid period after that debt is paid.
            $dates = ['next_charge_at' => $subscription->hasFinalLegacyDebt() ? now() : null];
        } else {
            $dates = [
                'current_period_start' => $charge->period_start,
                'current_period_end' => $charge->period_end,
                'next_charge_at' => $charge->period_end->copy()->startOfDay(),
            ];
        }

        $subscription->update([
            'status' => $stopped ? SubscriptionStatus::Canceled : SubscriptionStatus::Active,
            ...$dates,
            'dunning_stage' => 0,
        ]);

        if ($wasSuspended && ! $stopped && ! SiteAgentArrearsBilling::applies($subscription)
            && ! $subscription->plan?->includes_site_agent && $subscription->site_id) {
            RestoreSiteJob::dispatch($subscription->site_id);
        }

        // A payment plan that just collected its last instalment closes itself.
        // The line above has already scheduled the next charge, as it must for
        // an ordinary subscription — this takes that date away again, so there
        // is nothing left for the scheduler (or a manual "charge now") to find.
        $subscription->closeIfInstallmentPlanComplete();

        // The billing day is the natural cadence for the customer's monthly
        // monitoring report (no-op unless the feature is enabled; sent at most
        // once per month).
        SendMonthlyMonitoringReportJob::dispatch($subscription->customer_id);
    }
}
