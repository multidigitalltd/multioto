<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Models\Customer;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Process a completed Cardcom Low Profile session: store the returned token,
 * make it the customer's default, and immediately retry any subscription that
 * is in dunning — this is the recovery path of the dunning machine (§5).
 */
class ProcessCardcomLowProfileJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if (! $event || $event->processed_at !== null) {
            return;
        }

        $payload = $event->payload;
        $tokenInfo = $payload['TokenInfo'] ?? [];
        $customerId = (int) ($payload['ReturnValue'] ?? 0);
        $customer = Customer::find($customerId);

        if (! $customer || empty($tokenInfo['Token'])) {
            Log::warning('Cardcom low profile webhook without customer/token', [
                'webhook_event_id' => $event->id,
            ]);
            $event->markProcessed();

            return;
        }

        $token = $customer->paymentTokens()->create([
            'cardcom_token' => $tokenInfo['Token'],
            'card_last4' => isset($tokenInfo['CardLast4Digits']) ? (string) $tokenInfo['CardLast4Digits'] : null,
            'card_brand' => $tokenInfo['CardBrand'] ?? null,
            'expiry_month' => $tokenInfo['CardMonth'] ?? null,
            'expiry_year' => $tokenInfo['CardYear'] ?? null,
            'status' => TokenStatus::Active,
        ]);

        $customer->paymentTokens()
            ->whereKeyNot($token->id)
            ->where('status', TokenStatus::Active)
            ->update(['status' => TokenStatus::Replaced]);

        $customer->update(['default_token_id' => $token->id]);

        // Point every non-canceled subscription at the fresh token. A brand-new
        // (Trialing) subscription is activated so the scheduler starts charging
        // it on its due date; anything stuck in dunning is retried right away.
        $customer->subscriptions()
            ->whereNot('status', SubscriptionStatus::Canceled)
            ->each(function ($subscription) use ($token) {
                $subscription->update(['token_id' => $token->id]);

                if ($subscription->status === SubscriptionStatus::Trialing) {
                    $subscription->update(['status' => SubscriptionStatus::Active]);
                }

                if (in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended], true)) {
                    $subscription->update(['next_charge_at' => now()]);
                    ChargeSubscriptionJob::dispatch($subscription->id);
                }
            });

        $event->markProcessed();
    }
}
