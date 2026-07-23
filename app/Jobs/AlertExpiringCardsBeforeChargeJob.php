<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Jobs\Concerns\PausesForShabbat;
use App\Models\Subscription;
use App\Services\Notifications\CardCaptureLinkSender;
use App\Services\Notifications\TeamNotifier;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A saved card that will expire BEFORE the subscription's next charge date means
 * the auto-charge is already doomed — it will decline for an expired card and
 * drop the customer into dunning. This job catches that ahead of time: for each
 * subscription whose card expires before an upcoming charge, it proactively
 * invites the customer to update their card (reusing the card-capture link) and
 * flags the team, exactly once per card (see card_expiry_alerted_at, which is
 * cleared when a fresh card is saved).
 *
 * Runs daily. Honours the Shabbat quiet period like every other outward job.
 */
class AlertExpiringCardsBeforeChargeJob implements ShouldQueue
{
    use PausesForShabbat;
    use Queueable;

    public int $tries = 1;

    public function handle(CardCaptureLinkSender $links, TeamNotifier $team): void
    {
        if ($this->rescheduledForShabbat()) {
            return;
        }

        $days = (int) config('billing.reminders.card_before_charge_days', 14);

        if ($days <= 0) {
            return; // Alert disabled.
        }

        // Card-charged subscriptions with a charge coming up inside the window
        // that we have not warned about yet. Manual (no-token) subscriptions
        // don't auto-charge, so an expired card can't fail them.
        $subs = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('token_id')
            ->whereNotNull('next_charge_at')
            ->whereNull('card_expiry_alerted_at')
            ->whereBetween('next_charge_at', [now(), now()->addDays($days)])
            ->with(['customer', 'token', 'plan'])
            ->orderBy('next_charge_at')
            ->get()
            // Keep only those whose active card actually expires before the charge.
            ->filter(function (Subscription $s): bool {
                $token = $s->token;

                if ($token === null || $token->status !== TokenStatus::Active) {
                    return false;
                }

                $expiresAt = $token->expiresAt();

                return $expiresAt !== null && $expiresAt->lessThan($s->next_charge_at);
            })
            ->values();

        if ($subs->isEmpty()) {
            return;
        }

        $lines = [];

        foreach ($subs as $sub) {
            $result = $sub->customer ? $links->send($sub) : ['sent' => [], 'failed' => [], 'skipped' => ['אין לקוח']];

            // Warn once — even if the customer send failed, dunning picks it up
            // when the charge declines; re-nagging the team daily helps no one.
            $sub->forceFill(['card_expiry_alerted_at' => now()])->save();

            $lines[] = $this->line($sub, $result);
        }

        $team->alert(
            '⚠️ כרטיסים שיפוגו לפני החיוב הבא',
            "לכל אלה נשלח ללקוח לינק לעדכון כרטיס (פעם אחת):\n".implode("\n", $lines),
        );
    }

    /**
     * @param  array{sent: array<int, string>, failed: array<int, string>, skipped: array<int, string>}  $result
     */
    private function line(Subscription $sub, array $result): string
    {
        $token = $sub->token;
        $expiry = $token?->expiresAt();

        $status = $result['sent'] !== []
            ? 'נשלח ('.implode(', ', $result['sent']).')'
            : 'לא נשלח ('.implode('; ', array_merge($result['failed'], $result['skipped'])).')';

        return sprintf('• %s — כרטיס ...%s בתוקף עד %s, חיוב ב-%s (%s) · %s',
            $sub->customer?->name ?? 'לקוח',
            $token?->card_last4 ?? '????',
            $expiry?->format('m/y') ?? '—',
            $sub->next_charge_at->format('d/m'),
            Money::ils($sub->totalChargeAgorot()),
            $status,
        );
    }
}
