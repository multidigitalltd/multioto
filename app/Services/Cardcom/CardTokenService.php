<?php

namespace App\Services\Cardcom;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Customer;
use App\Models\PaymentToken;

/**
 * Turns a completed Cardcom Low Profile result into a saved card token and wires
 * it to the customer's subscriptions. Shared by the webhook (automatic) and the
 * "sync card from Cardcom" panel action (manual reconciliation when the webhook
 * never arrived), so both behave identically.
 */
class CardTokenService
{
    /**
     * Store the token carried by a GetLpResult / webhook payload, if it holds a
     * usable one. Returns the new PaymentToken, or null when the result has no
     * successful token to store.
     *
     * @param  array<string, mixed>  $result
     */
    public function storeFromLpResult(Customer $customer, array $result): ?PaymentToken
    {
        if ((string) ($result['ResponseCode'] ?? '0') !== '0') {
            return null;
        }

        $tokenInfo = $result['TokenInfo'] ?? [];

        if (empty($tokenInfo['Token'])) {
            return null;
        }

        return $this->store($customer, $tokenInfo);
    }

    /**
     * Persist the token, make it the customer's default (retiring any previous
     * active card), point every live subscription at it, and collect anything
     * already owed — so a debtor who just entered a card is billed at once.
     *
     * @param  array<string, mixed>  $tokenInfo
     */
    public function store(Customer $customer, array $tokenInfo): PaymentToken
    {
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

        $customer->subscriptions()
            ->whereNot('status', SubscriptionStatus::Canceled)
            ->each(function ($subscription) use ($token) {
                $subscription->update(['token_id' => $token->id]);

                if ($subscription->status === SubscriptionStatus::Trialing) {
                    $subscription->update(['status' => SubscriptionStatus::Active]);
                } elseif (in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended], true)) {
                    // The debt is due now — pull the next charge forward.
                    $subscription->update(['next_charge_at' => now()]);
                }

                $subscription->refresh();

                if ($subscription->status !== SubscriptionStatus::Canceled
                    && $subscription->next_charge_at
                    && $subscription->next_charge_at->isPast()) {
                    ChargeSubscriptionJob::dispatch($subscription->id);
                }
            });

        return $token;
    }
}
