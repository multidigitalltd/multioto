<?php

namespace App\Services\Cardcom;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenStatus;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Customer;
use App\Models\PaymentToken;
use App\Models\Subscription;
use InvalidArgumentException;

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

        // The card's last-4 and brand live on TranzactionInfo, NOT TokenInfo —
        // merge them in so the saved card shows "ויזה · 6829" and not a blank.
        $tran = $result['TranzactionInfo'] ?? [];
        $cardInfo = array_merge($tokenInfo, [
            'CardLast4Digits' => $tran['Last4CardDigits'] ?? $tran['Last4CardDigitsString'] ?? $tokenInfo['CardLast4Digits'] ?? null,
            'CardBrand' => $tran['CardName'] ?? $tran['Brand'] ?? $tokenInfo['CardBrand'] ?? null,
        ]);

        return $this->store($customer, $cardInfo);
    }

    /**
     * Persist the token, make it the customer's card on file, and collect
     * anything already owed — so a debtor who just entered a card is billed at
     * once.
     *
     * @param  array<string, mixed>  $tokenInfo
     */
    public function store(Customer $customer, array $tokenInfo, bool $collectNow = true): PaymentToken
    {
        $token = $customer->paymentTokens()->create([
            'cardcom_token' => $tokenInfo['Token'],
            'card_last4' => isset($tokenInfo['CardLast4Digits']) ? (string) $tokenInfo['CardLast4Digits'] : null,
            'card_brand' => $tokenInfo['CardBrand'] ?? null,
            'expiry_month' => $tokenInfo['CardMonth'] ?? null,
            'expiry_year' => $tokenInfo['CardYear'] ?? null,
            'status' => TokenStatus::Active,
        ]);

        $this->makeDefault($customer, $token, $collectNow);

        return $token;
    }

    /**
     * Make one of the customer's saved cards THE card on file: every other
     * active card is retired, `default_token_id` points here, and every live
     * subscription is repointed at it.
     *
     * This is the only place that wiring exists, and everything that saves a
     * card goes through it. A card saved without it — which is what the hosted
     * one-off charge used to do — leaves the customer holding two "active"
     * cards while every subscription still charges the old one. That is exactly
     * how a customer who has just replaced an expired card keeps getting
     * declined on the card they replaced, with the panel showing a valid card
     * the whole time.
     *
     * @param  bool  $collectNow  Whether this card arrived as an act of payment.
     *                            True for a card capture: a trial converts and
     *                            anything overdue is collected immediately.
     *                            False when the team is merely choosing which
     *                            saved card is the live one — repointing a card
     *                            is a bookkeeping decision and must not, by
     *                            itself, end a trial or take money.
     */
    public function makeDefault(Customer $customer, PaymentToken $token, bool $collectNow = true): void
    {
        if ((int) $token->customer_id !== (int) $customer->id) {
            // A card belongs to exactly one customer. Wiring one across would
            // charge somebody else's card on this customer's subscriptions.
            throw new InvalidArgumentException("כרטיס #{$token->id} אינו שייך ללקוח #{$customer->id}.");
        }

        // A removed card gave up its token. Making it the card on file again
        // would wire every subscription to something that cannot be charged,
        // and each renewal would fail as if the card had been declined.
        if (blank($token->cardcom_token)) {
            throw new InvalidArgumentException("כרטיס #{$token->id} הוסר ואין לו טוקן — יש להזין את הכרטיס מחדש.");
        }

        if ($token->status !== TokenStatus::Active) {
            $token->update(['status' => TokenStatus::Active]);
        }

        $customer->paymentTokens()
            ->whereKeyNot($token->id)
            ->where('status', TokenStatus::Active)
            ->update(['status' => TokenStatus::Replaced]);

        $customer->update(['default_token_id' => $token->id]);

        $customer->subscriptions()
            ->whereNot('status', SubscriptionStatus::Canceled)
            ->each(function (Subscription $subscription) use ($customer, $token, $collectNow): void {
                // The customer is already in hand — hand it over rather than
                // letting each row fetch it again to answer "how is this paid".
                $subscription->setRelation('customer', $customer);
                $subscription->update(['token_id' => $token->id]);

                if (! $collectNow) {
                    return;
                }

                if ($subscription->status === SubscriptionStatus::Trialing) {
                    $subscription->update(['status' => SubscriptionStatus::Active]);
                } elseif (in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended], true)) {
                    // The debt is due now — make it collectable immediately, but
                    // WITHOUT moving the billing anchor forward, so a late payer is
                    // billed for the delayed period and keeps the original date.
                    $subscription->markDueNow();
                }

                $subscription->refresh();

                if ($subscription->status !== SubscriptionStatus::Canceled
                    && $subscription->next_charge_at
                    && $subscription->next_charge_at->isPast()
                    // A subscription the customer pays by transfer keeps its
                    // card as a fallback only. Entering a card must not collect
                    // it here — that is the fallback's decision, after its grace
                    // period, and taking the money now would be charging a card
                    // the customer did not arrange to have charged.
                    && ! $subscription->isManuallyCollected()) {
                    // The customer just updated their card in order to pay —
                    // charge now, even during the Shabbat quiet period.
                    ChargeSubscriptionJob::dispatch($subscription->id, manual: true);
                }
            });
    }

    /**
     * Take a card off the customer's file.
     *
     * The row stays. Charges reference the card they were collected on, and a
     * deleted token would take that trail with it (`subscriptions.token_id` is
     * nullOnDelete, so the pointer would vanish silently) — so the card is
     * marked removed instead: never charged again, no longer the default, and
     * unhooked from every subscription that pointed at it.
     *
     * Nothing is promoted in its place, deliberately. A customer left without a
     * card is one the collection screens are built to surface (scopeAwaitingCard);
     * quietly activating some older card instead would charge a card nobody chose.
     *
     * Nothing is deleted at Cardcom either, and nothing needs to be: the token
     * is dropped from our row, so there is no longer anything here to charge it
     * with. Removal is not a status somebody could flip back.
     */
    public function detach(Customer $customer, PaymentToken $token): void
    {
        if ((int) $token->customer_id !== (int) $customer->id) {
            throw new InvalidArgumentException("כרטיס #{$token->id} אינו שייך ללקוח #{$customer->id}.");
        }

        $token->update([
            'status' => TokenStatus::Removed,
            // The credential goes; the card's identity (brand, last four,
            // expiry) stays, because charges collected on it point here.
            'cardcom_token' => null,
        ]);

        if ((int) $customer->default_token_id === (int) $token->id) {
            $customer->update(['default_token_id' => null]);
        }

        // One at a time rather than a mass update: the model clears the
        // "card expires before the next charge" warning when token_id moves,
        // and a mass update never runs that.
        $customer->subscriptions()
            ->where('token_id', $token->id)
            ->each(fn (Subscription $subscription) => $subscription->update(['token_id' => null]));
    }
}
