<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\IssueInvoiceJob;
use App\Jobs\RestoreSiteJob;
use App\Jobs\SendMonthlyMonitoringReportJob;
use App\Models\Charge;
use App\Models\Subscription;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a manual (off-card) payment for a subscription collected by hand —
 * bank transfer, standing order or cheques. It writes a succeeded charge for
 * the due period, rolls the subscription into the next period, and issues the
 * Linet invoice, exactly like a successful card charge would. This is the
 * "סמן כשולם" action behind the "דרישות תשלום" screen.
 *
 * Idempotent per (subscription, period): a lock + a duplicate-period guard mean
 * a double click can never bill or invoice the same period twice.
 */
class SubscriptionCollectionService
{
    /**
     * Record that a manually-collected subscription paid its due period, advance
     * it, and queue the invoice. Returns the recorded charge, or the existing one
     * if this period was already collected.
     */
    public function recordPayment(Subscription $subscription, ?string $notes = null): Charge
    {
        return Cache::lock("charge-subscription:{$subscription->id}", 300)->block(10, function () use ($subscription, $notes): Charge {
            return DB::transaction(function () use ($subscription, $notes): Charge {
                $subscription->refresh()->loadMissing(['plan', 'customer']);
                $transition = app(SiteAgentBillingTransition::class)->transitionLocked($subscription);

                if ($transition === SiteAgentBillingTransition::INCOMPLETE) {
                    throw ValidationException::withMessages(['payment' => 'יש להשלים את תקופת השירות ששולמה לפני רישום תשלום נוסף.']);
                }

                if (SiteAgentArrearsBilling::applies($subscription)) {
                    return $this->recordArrearsPayment($subscription, $notes);
                }

                $stoppedLegacy = $subscription->status === SubscriptionStatus::Canceled
                    && $subscription->billing_stop_at !== null;

                if ($subscription->status === SubscriptionStatus::Canceled && $transition !== SiteAgentBillingTransition::UNRESOLVED) {
                    $last = $subscription->charges()->where('status', ChargeStatus::Succeeded)->latest('id')->first();

                    if ($last !== null) {
                        return $last;
                    }

                    throw ValidationException::withMessages(['payment' => 'המנוי בוטל ואין חיוב קיים שממתין להסדרה.']);
                }

                $legacyAttempt = null;

                if ($transition === SiteAgentBillingTransition::UNRESOLVED) {
                    $this->assertNoUnknownAttempt($subscription);
                    $legacyAttempt = $subscription->unsettledLegacyCharges()
                        ->where('status', ChargeStatus::Failed)->oldest('id')->first();

                    if ($legacyAttempt === null) {
                        throw ValidationException::withMessages(['payment' => 'יש לבדוק את החוב הקודם לפני רישום תקופת חיוב חדשה.']);
                    }
                }

                // A payment plan that is fully paid has nothing left to collect.
                // This check comes FIRST because closing the plan clears
                // next_charge_at, and the future-date guard below reads a null
                // date as "collect from today" — so a second click on the final
                // instalment would open a brand-new period, record another
                // payment and issue another invoice for money nobody owes.
                if ($subscription->installmentPlanComplete()) {
                    $last = $subscription->charges()->where('status', ChargeStatus::Succeeded)->latest('id')->first();

                    if ($last) {
                        return $last;
                    }
                }

                // Already collected for the current period: next_charge_at has
                // rolled into the future. A second click (double submit) must not
                // bill the next period too — return the last recorded payment.
                if ($legacyAttempt === null && $subscription->next_charge_at !== null && $subscription->next_charge_at->isFuture()) {
                    $last = $subscription->charges()->where('status', ChargeStatus::Succeeded)->latest('id')->first();

                    if ($last) {
                        return $last;
                    }
                }

                $periodStart = $legacyAttempt?->period_start ?? Carbon::parse($subscription->next_charge_at ?? now());
                $periodEnd = $legacyAttempt?->period_end ?? ($subscription->billingInterval() === BillingInterval::Yearly
                    ? $periodStart->copy()->addYear()
                    : $periodStart->copy()->addMonth());

                // Never collect the same period twice.
                $existing = $subscription->charges()
                    ->where('status', ChargeStatus::Succeeded)
                    ->whereDate('period_start', $periodStart)
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $attempt = (int) $subscription->charges()
                    ->whereDate('period_start', $periodStart)
                    ->max('attempt_number') + 1;

                // The same breakdown a card renewal uses: plan, extra numbers,
                // and the messages sent since the last invoice.
                $breakdown = $legacyAttempt?->only(['amount_agorot', 'vat_agorot', 'total_agorot', 'lines', 'usage_until'])
                    ?? app(RenewalBreakdown::class)->for($subscription, $periodStart, $periodEnd, now());

                $charge = $subscription->charges()->create([
                    'customer_id' => $subscription->customer_id,
                    'amount_agorot' => $breakdown['amount_agorot'],
                    'vat_agorot' => $breakdown['vat_agorot'],
                    'total_agorot' => $breakdown['total_agorot'],
                    'lines' => $breakdown['lines'],
                    'usage_until' => $breakdown['usage_until'],
                    'currency' => config('billing.currency'),
                    // Recorded here, not inferred at invoicing time: this money
                    // arrived by transfer / standing order / cheque, whatever
                    // the customer's usual arrangement says.
                    'payment_method' => $subscription->effectivePaymentMethod(),
                    'status' => ChargeStatus::Succeeded,
                    'attempt_number' => $attempt,
                    'description' => $subscription->chargeDescription($periodStart, $periodEnd),
                    'invoice_notes' => filled($notes) ? $notes : null,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'charged_at' => now(),
                ]);

                app(SiteAgentUsageMeter::class)->settle($charge);

                $wasSuspended = $subscription->status === SubscriptionStatus::Suspended;

                $subscription->update([
                    'status' => $stoppedLegacy ? SubscriptionStatus::Canceled : SubscriptionStatus::Active,
                    ...($stoppedLegacy ? [
                        'next_charge_at' => $subscription->hasFinalLegacyDebt() ? now() : null,
                    ] : [
                        'current_period_start' => $periodStart,
                        'current_period_end' => $periodEnd,
                        'next_charge_at' => $periodEnd->copy()->startOfDay(),
                    ]),
                    'dunning_stage' => 0,
                ]);

                if ($wasSuspended && ! $stoppedLegacy && ! $subscription->plan?->includes_site_agent && $subscription->site_id) {
                    RestoreSiteJob::dispatch($subscription->site_id);
                }

                // A payment plan collected by hand ends the same way one collected
                // by card does — the last instalment closes it, whichever route
                // the money arrived by.
                $subscription->closeIfInstallmentPlanComplete();

                // Issue the Linet invoice for the recorded payment (idempotent).
                IssueInvoiceJob::dispatch($charge->id);

                // The billing day drives the customer's monthly monitoring report
                // (no-op unless enabled; once per month).
                SendMonthlyMonitoringReportJob::dispatch($subscription->customer_id);

                return $charge;
            });
        });
    }

    /** Under the shared financial lock and transaction, collect only completed service. */
    private function recordArrearsPayment(Subscription $subscription, ?string $notes): Charge
    {
        $this->assertNoUnknownAttempt($subscription);
        ['start' => $start, 'end' => $end] = SiteAgentArrearsBilling::period($subscription);

        if ($end->isFuture() || $subscription->status === SubscriptionStatus::Trialing
            || ($subscription->status === SubscriptionStatus::Canceled && ! $subscription->hasFinalArrearsDebt())) {
            $last = $subscription->charges()->where('status', ChargeStatus::Succeeded)->latest('id')->get()
                ->first(fn (Charge $charge): bool => SiteAgentArrearsBilling::metadata($charge) !== []);

            if ($last !== null) {
                return $last;
            }

            throw ValidationException::withMessages(['payment' => 'החיוב על הבוט מתבצע לאחר השלמת חודש השירות האישי.']);
        }

        $attempts = $subscription->charges()->whereDate('period_start', $start)->orderBy('id')->get();
        $arrearsAttempts = $attempts->filter(fn (Charge $charge): bool => SiteAgentArrearsBilling::metadata($charge) !== []);
        $charge = $arrearsAttempts->firstWhere('status', ChargeStatus::Succeeded);

        if ($charge === null) {
            $first = $arrearsAttempts->first();
            $breakdown = $first?->only(['amount_agorot', 'vat_agorot', 'total_agorot', 'lines', 'usage_until'])
                ?? app(RenewalBreakdown::class)->for($subscription, $start, $end, $end);

            $charge = $subscription->charges()->create([
                ...$breakdown,
                'customer_id' => $subscription->customer_id,
                'currency' => $first?->currency ?? config('billing.currency'),
                'payment_method' => $subscription->effectivePaymentMethod(),
                'status' => ChargeStatus::Succeeded,
                'attempt_number' => (int) $attempts->max('attempt_number') + 1,
                'description' => $subscription->chargeDescription(Carbon::instance($start), Carbon::instance($end)),
                'invoice_notes' => filled($notes) ? $notes : null,
                'period_start' => $start,
                'period_end' => $end,
                'charged_at' => now(),
            ]);
        }

        app(SiteAgentUsageMeter::class)->settle($charge);
        $stopped = $subscription->hasStoppedArrearsBilling();
        $subscription->update([
            ...SiteAgentArrearsBilling::afterPayment($subscription, $charge),
            'status' => $stopped ? SubscriptionStatus::Canceled : SubscriptionStatus::Active,
            'dunning_stage' => 0,
        ]);

        if ($charge->total_agorot > 0) {
            IssueInvoiceJob::dispatch($charge->id);
        }

        SendMonthlyMonitoringReportJob::dispatch($subscription->customer_id);

        return $charge;
    }

    private function assertNoUnknownAttempt(Subscription $subscription): void
    {
        if ($subscription->charges()->where('status', ChargeStatus::Pending)->exists()) {
            throw ValidationException::withMessages(['payment' => 'קיים ניסיון חיוב שטרם הוכרע. יש לסנכרן את תוצאתו לפני רישום תשלום נוסף.']);
        }
    }
}
