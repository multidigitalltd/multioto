<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Notifications\CardCaptureLinkSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Invite a newly onboarded customer to enter their card on Cardcom's hosted
 * page. Sent on WhatsApp and/or email with a short-lived signed link — the
 * card itself is captured entirely by Cardcom (PCI scope stays with them).
 *
 * Heavy/external work runs here in the queue, never in the onboarding request.
 */
class SendCardCaptureLinkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [120, 600];

    public function __construct(public int $subscriptionId) {}

    public function handle(CardCaptureLinkSender $sender): void
    {
        $subscription = Subscription::with(['customer', 'plan'])->find($this->subscriptionId);

        // Skip if the subscription vanished or was canceled between enqueue and
        // run — a canceled subscription must never receive a card-capture link.
        if (! $subscription || $subscription->status === SubscriptionStatus::Canceled) {
            return;
        }

        $result = $sender->send($subscription);

        if ($result['failed'] !== []) {
            Log::warning('Card-capture link delivery had failures', [
                'subscription_id' => $subscription->id,
                'sent' => $result['sent'],
                'failed' => $result['failed'],
            ]);
        }

        // If nothing got through, throw so the job retries (a transient WhatsApp
        // or mail error may clear); if at least one channel delivered, we're done.
        if ($result['sent'] === []) {
            throw new \RuntimeException('Card-capture link delivery failed: '.implode('; ', $result['failed']));
        }
    }
}
