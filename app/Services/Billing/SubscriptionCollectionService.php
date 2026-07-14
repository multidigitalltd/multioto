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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        return Cache::lock("manual-collect:{$subscription->id}", 30)->block(10, function () use ($subscription, $notes): Charge {
            return DB::transaction(function () use ($subscription, $notes): Charge {
                $subscription->refresh()->loadMissing(['plan', 'customer']);

                // Already collected for the current period: next_charge_at has
                // rolled into the future. A second click (double submit) must not
                // bill the next period too — return the last recorded payment.
                if ($subscription->next_charge_at !== null && $subscription->next_charge_at->isFuture()) {
                    $last = $subscription->charges()->where('status', ChargeStatus::Succeeded)->latest('id')->first();

                    if ($last) {
                        return $last;
                    }
                }

                $periodStart = Carbon::parse($subscription->next_charge_at ?? now());
                $periodEnd = $subscription->billingInterval() === BillingInterval::Yearly
                    ? $periodStart->copy()->addYear()
                    : $periodStart->copy()->addMonth();

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

                $charge = $subscription->charges()->create([
                    'customer_id' => $subscription->customer_id,
                    'amount_agorot' => $subscription->basePriceAgorot(),
                    'vat_agorot' => $subscription->vatAgorot(),
                    'total_agorot' => $subscription->totalChargeAgorot(),
                    'currency' => config('billing.currency'),
                    'status' => ChargeStatus::Succeeded,
                    'attempt_number' => $attempt,
                    'description' => sprintf('%s — %s עד %s', $subscription->planName(), $periodStart->format('d/m/Y'), $periodEnd->format('d/m/Y')),
                    'invoice_notes' => filled($notes) ? $notes : null,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'charged_at' => now(),
                ]);

                $wasSuspended = $subscription->status === SubscriptionStatus::Suspended;

                $subscription->update([
                    'status' => SubscriptionStatus::Active,
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                    'next_charge_at' => $periodEnd->copy()->startOfDay(),
                    'dunning_stage' => 0,
                ]);

                if ($wasSuspended && $subscription->site_id) {
                    RestoreSiteJob::dispatch($subscription->site_id);
                }

                // Issue the Linet invoice for the recorded payment (idempotent).
                IssueInvoiceJob::dispatch($charge->id);

                // The billing day drives the customer's monthly monitoring report
                // (no-op unless enabled; once per month).
                SendMonthlyMonitoringReportJob::dispatch($subscription->customer_id);

                return $charge;
            });
        });
    }
}
