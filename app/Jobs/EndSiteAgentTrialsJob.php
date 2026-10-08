<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Mail\NotificationMail;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\Billing\SiteAgentArrearsBilling;
use App\Services\Billing\SiteAgentBillingTransition;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * The end of a free trial: a reminder two days before, and the switch to a
 * paying subscription when it is over.
 *
 * Ending a trial is not charging. New arrears subscriptions begin their first
 * paid service month here and are collected only when that month closes.
 * Historical prepaid trials retain their original due date. Both use the
 * ordinary dispatcher and its locks, attempt numbers, quiet period and dunning.
 *
 * A trial whose card is gone (removed during the week) cannot become a paying
 * subscription. It is closed, the bot stops, and the owner is told the way
 * every paused owner is told (SyncSiteAgentServiceStateJob).
 *
 * Idempotent and safe to run as often as the scheduler likes: each step is
 * guarded by the state it moves the row out of.
 */
class EndSiteAgentTrialsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** How far ahead of the end the reminder goes out. */
    public const REMIND_DAYS = 2;

    public function handle(): void
    {
        $this->remind();
        $this->end();
    }

    /**
     * "Your trial ends on Thursday, and then ₪149 a month."
     *
     * Before the card is charged, never after: a first charge nobody saw coming
     * is the charge that becomes a dispute.
     */
    private function remind(): void
    {
        Subscription::query()
            ->with(['customer', 'plan'])
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNull('trial_reminded_at')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now())
            ->where('trial_ends_at', '<=', now()->addDays(self::REMIND_DAYS))
            ->chunkById(100, function ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    // Stamped first: a mail server error must not turn into a
                    // reminder sent every hour.
                    $subscription->update(['trial_reminded_at' => now()]);

                    $email = (string) $subscription->customer?->email;

                    if ($email === '') {
                        continue;
                    }

                    rescue(fn () => Mail::to($email)->send(new NotificationMail(
                        'תקופת הניסיון של בוט ניהול האתר מסתיימת ב־'.$subscription->trial_ends_at->format('d/m/Y'),
                        $this->reminderText($subscription),
                    )));
                }
            });
    }

    /** Trials that are over: start paid service, or close when the card is gone. */
    private function end(): void
    {
        Subscription::query()
            ->with('customer')
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->chunkById(100, function ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    Cache::lock("charge-subscription:{$subscription->id}", 300)->get(function () use ($subscription): void {
                        $subscription->refresh();
                        if ($subscription->status !== SubscriptionStatus::Trialing
                            || $subscription->trial_ends_at === null
                            || $subscription->trial_ends_at->isFuture()) {
                            return;
                        }

                        // Transition while still Trialing. Otherwise an old trial
                        // becomes Active without paid-period dates and can never
                        // be migrated when the reconciliation job runs later.
                        app(SiteAgentBillingTransition::class)->transitionLocked($subscription);
                        $hasCard = $subscription->hasChargeableToken();

                        if ($hasCard) {
                            // Rechecked while holding the same lock used by charging
                            // and cancellation: a stale trial cannot reopen service
                            // or move a cursor after a payment has already closed it.
                            $subscription->update([
                                'status' => SubscriptionStatus::Active,
                                ...($subscription->billing_mode === SiteAgentArrearsBilling::MODE
                                    ? SiteAgentArrearsBilling::initializeDates($subscription->billing_anchor_at ?? $subscription->trial_ends_at)
                                    : ['next_charge_at' => $subscription->trial_ends_at]),
                            ]);

                            return;
                        }

                        $subscription->update([
                            'status' => SubscriptionStatus::Canceled,
                            'canceled_at' => now(),
                            'next_charge_at' => null,
                            'billing_stop_at' => null,
                        ]);

                        SystemLog::record('info', 'siteagent',
                            "תקופת ניסיון הסתיימה בלי כרטיס פעיל — המנוי נסגר ({$subscription->customer?->name}).",
                            ['subscription_id' => $subscription->id]);
                    });
                }
            });
    }

    private function reminderText(Subscription $subscription): string
    {
        $plan = $subscription->plan;
        $exempt = (bool) $subscription->customer?->vat_exempt;

        return implode("\n\n", array_filter([
            'שלום '.trim((string) $subscription->customer?->name).',',
            'תקופת הניסיון של בוט ניהול האתר מסתיימת ב־'.$subscription->trial_ends_at->format('d/m/Y').'.',
            $plan !== null
                ? ($subscription->billing_mode === SiteAgentArrearsBilling::MODE
                    ? 'לאחר הניסיון יתחיל חודש השירות הראשון בתשלום. בסופו, ב־'.$subscription->next_charge_at->timezone('Asia/Jerusalem')->format('d/m/Y').', ייגבה בכרטיס ששמרתם מחיר המנוי: '.Money::ils($plan->withVat($plan->siteAgentMonthlyNetAgorot(), $exempt)).' לחודש'
                        .($plan->billsMessages() ? ', ובנוסף '.Money::ils((int) $plan->messageGrossAgorot($exempt)).' לכל הודעה יוצאת מעבר למכסה הכלולה.' : '.')
                        .($plan->billsWritings() ? ' תוספות כתיבה מעבר למכסה יחויבו באותו מועד.' : '')
                    : 'מאותו יום המנוי ימשיך אוטומטית ויחויב בכרטיס ששמרתם: '.$plan->priceLabel($exempt)
                        .($plan->billsMessages() ? ', ובנוסף '.Money::ils((int) $plan->messageGrossAgorot($exempt)).' לכל הודעה שהבוט שולח מעבר למכסה הכלולה (נגבה בחידוש שאחריו).' : '.'))
                : null,
            'אם תרצו להפסיק — השיבו למייל הזה או כתבו לנו מהאזור האישי במהלך הניסיון, לפני '.$subscription->trial_ends_at->format('d/m/Y').', ולא תחויבו.',
        ]));
    }
}
