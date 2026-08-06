<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\Notifications\CardCaptureLinkSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Ask a customer for a card when their charge date has passed and none is on
 * file.
 *
 * Until now nothing happened at all: no charge is attempted without a token, so
 * no failure exists, so the dunning machine — which chases failures — never
 * starts. The customer is never told, and the service keeps running unpaid.
 *
 * This is a SERVICE message, not marketing: it goes out regardless of a
 * marketing opt-out, exactly like an invoice or a dunning notice. Somebody who
 * asked us to stop advertising to them did not ask to stop being told that
 * their subscription is unpaid.
 */
class RequestMissingCardJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(CardCaptureLinkSender $sender): void
    {
        $intervalDays = max(1, (int) config('billing.cards.missing_request.interval_days', 3));
        $maxRequests = (int) config('billing.cards.missing_request.max_requests', 5);

        if ($maxRequests === 0) {
            return; // Switched off entirely.
        }

        // One message per CUSTOMER, not per subscription: the card link is
        // customer-wide, so three subscriptions missing the same card are one
        // request — three identical messages in one minute read as a machine
        // that has lost count of who it is talking to.
        $subscriptions = Subscription::query()
            ->awaitingCardOverdue()
            ->with(['customer', 'plan'])
            ->orderBy('next_charge_at')
            ->get()
            ->filter(fn (Subscription $subscription): bool => $subscription->customer !== null)
            ->unique('customer_id');

        $sentTo = 0;

        foreach ($subscriptions as $subscription) {
            $requests = $this->requestsSince($subscription);

            // Stop after the cap. A customer who has ignored five requests will
            // not be persuaded by a sixth, and past that point it is the team's
            // call — the subscription is still on the collections screen.
            if ($requests['count'] >= $maxRequests) {
                continue;
            }

            if ($requests['last'] !== null && $requests['last']->gt(now()->subDays($intervalDays))) {
                continue; // Asked recently — give them time to act.
            }

            try {
                $result = $sender->send($subscription, 'card.missing');
            } catch (\Throwable $e) {
                Log::warning('RequestMissingCardJob: send failed', [
                    'subscription' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($result['sent'] !== []) {
                $sentTo++;
            }
        }

        if ($sentTo > 0) {
            SystemLog::record('info', 'billing', "נשלחו {$sentTo} בקשות להזנת כרטיס ללקוחות שמועד החיוב שלהם עבר");
        }
    }

    /**
     * How many times we have asked this customer for a card since the charge
     * became due, and when we last asked.
     *
     * Counted from the outbound log rather than a flag on the subscription: the
     * log is what actually reached the customer, and it is per-customer, which
     * is the unit a card belongs to.
     *
     * @return array{count: int, last: Carbon|null}
     */
    private function requestsSince(Subscription $subscription): array
    {
        $logs = NotificationLog::query()
            ->where('customer_id', $subscription->customer_id)
            ->where('type', NotificationType::CardLink)
            ->where('status', 'sent')
            ->where('sent_at', '>=', $subscription->next_charge_at)
            ->orderByDesc('sent_at')
            ->get(['sent_at']);

        return ['count' => $logs->count(), 'last' => $logs->first()?->sent_at];
    }
}
