<?php

namespace App\Services\Billing;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Move existing bot subscriptions to postpaid months without moving money.
 * A paid legacy term is retained as one usage-only closing period, including
 * an already paid year. Unknown attempts and unpaid debt keep their original
 * financial identity until resolved; the scheduled reconciliation retries them.
 */
class SiteAgentBillingTransition
{
    public const TRANSITIONED = 'transitioned';

    public const UNRESOLVED = 'unresolved';

    public const INCOMPLETE = 'incomplete';

    public function transition(int $subscriptionId): string
    {
        $lock = Cache::lock("charge-subscription:{$subscriptionId}", 300);

        if (! $lock->get()) {
            return 'locked';
        }

        try {
            $subscription = Subscription::with('plan')->find($subscriptionId);

            return $subscription ? $this->transitionLocked($subscription) : 'missing';
        } finally {
            $lock->release();
        }
    }

    /** Caller must hold the shared charge-subscription lock. No charges are created. */
    public function transitionLocked(Subscription $subscription): string
    {
        if (SiteAgentArrearsBilling::applies($subscription)) {
            return 'already';
        }

        if ($subscription->status === SubscriptionStatus::Canceled) {
            if ($subscription->hasFinalLegacyDebt()) {
                return self::UNRESOLVED;
            }

            if ($subscription->billing_stop_at !== null && $subscription->next_charge_at !== null) {
                $subscription->update(['next_charge_at' => null, 'dunning_stage' => 0]);
            }

            return 'inapplicable';
        }

        if (! $subscription->plan?->includes_site_agent || $subscription->isInstallmentPlan()) {
            return 'inapplicable';
        }

        if ($subscription->dunning_stage > 0
            || in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended], true)
            || $this->hasUnresolvedAttempts($subscription)) {
            return self::UNRESOLVED;
        }

        if ($subscription->status === SubscriptionStatus::Trialing) {
            $subscription->update(SiteAgentArrearsBilling::initializeDates($subscription->trial_ends_at ?? now()));

            return self::TRANSITIONED;
        }

        if ($subscription->current_period_start === null || $subscription->current_period_end === null
            || $subscription->next_charge_at === null
            || $subscription->current_period_end->lte($subscription->current_period_start)) {
            // A guessed prepaid boundary can erase debt or bill paid service
            // twice. Keep the row visible for review instead of inventing one.
            return self::INCOMPLETE;
        }

        $start = CarbonImmutable::instance($subscription->current_period_start)->startOfDay();
        $end = CarbonImmutable::instance($subscription->current_period_end)->startOfDay();
        $earliest = $subscription->charges()->where('status', ChargeStatus::Succeeded)->min('period_start');
        $anchor = $earliest === null ? $start : CarbonImmutable::parse($earliest)->startOfDay();

        // Historical imports may not include the original invoice; in that
        // case the first known paid period is the authoritative anniversary.
        if ($anchor->gt($start)) {
            $anchor = $start;
        }

        $subscription->update([
            'billing_mode' => SiteAgentArrearsBilling::MODE,
            'billing_anchor_at' => $anchor,
            'billing_period_start_at' => $start,
            'billing_prepaid_until' => $end,
            'next_charge_at' => $subscription->next_charge_at->lt($end) ? $end : $subscription->next_charge_at,
        ]);

        return self::TRANSITIONED;
    }

    /** Financial attempts remain authoritative even when dunning was cleared by hand. */
    private function hasUnresolvedAttempts(Subscription $subscription): bool
    {
        if ($subscription->charges()->where('status', ChargeStatus::Pending)->exists()) {
            return true;
        }

        $failedPeriods = $subscription->charges()->where('status', ChargeStatus::Failed)
            ->pluck('period_start')->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString());

        if ($failedPeriods->isEmpty()) {
            return false;
        }

        $paidPeriods = $subscription->charges()->where('status', ChargeStatus::Succeeded)
            ->pluck('period_start')->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString());

        return $failedPeriods->diff($paidPeriods)->isNotEmpty();
    }
}
