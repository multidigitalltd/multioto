<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Mail\NotificationMail;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * The end of a free trial: a reminder two days before, and the switch to a
 * paying subscription when it is over.
 *
 * Ending a trial is NOT charging. This only moves the subscription to Active
 * with its first charge already due; the ordinary dispatcher collects it on its
 * next run, with every guard a renewal has — the per-subscription lock, the
 * attempt numbers, the Shabbat quiet period, dunning if the card fails. A
 * second, trial-only way of charging a card is a second place for a charge to
 * go wrong, and there is no reason for one.
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

    /** Trials that are over: on to the first charge, or closed. */
    private function end(): void
    {
        Subscription::query()
            ->with('customer')
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->chunkById(100, function ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    $hasCard = $subscription->token_id !== null
                        && $subscription->token()->where('status', TokenStatus::Active)->exists();

                    if ($hasCard) {
                        // Due now, from the day the trial ended — the
                        // dispatcher takes it from here.
                        $subscription->update([
                            'status' => SubscriptionStatus::Active,
                            'next_charge_at' => $subscription->trial_ends_at,
                        ]);

                        continue;
                    }

                    $subscription->update(['status' => SubscriptionStatus::Canceled, 'canceled_at' => now()]);

                    SystemLog::record('info', 'siteagent',
                        "תקופת ניסיון הסתיימה בלי כרטיס פעיל — המנוי נסגר ({$subscription->customer?->name}).",
                        ['subscription_id' => $subscription->id]);
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
                ? 'מאותו יום המנוי ימשיך אוטומטית ויחויב בכרטיס ששמרתם: '.$plan->priceLabel($exempt)
                    .($plan->billsMessages() ? ', ובנוסף '.Money::ils((int) $plan->messageGrossAgorot($exempt)).' לכל הודעה שהבוט שולח (נגבה בחידוש שאחריו).' : '.')
                : null,
            'אם תרצו להפסיק — השיבו למייל הזה או כתבו לנו מהאזור האישי לפני התאריך, ולא תחויבו.',
        ]));
    }
}
