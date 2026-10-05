<?php

namespace App\Services\SiteAgent;

use App\Enums\SubscriptionStatus;
use App\Models\Charge;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Counts the messages the site agent sends, for the line on the invoice that
 * bills them.
 *
 * What counts: every reply the bot delivered to a number that is entitled to
 * use it — an answer, a preview, a "בוצע". What does not: verification codes,
 * "your subscription paused" notices, refusals to unknown numbers, and the
 * "which site?" question. Those are the system talking about itself, and a
 * customer billed for being told their card failed would be right to object.
 *
 * Billed in arrears and exactly once. A renewal counts what was sent up to the
 * moment its charge is created, and when that charge succeeds those very rows
 * are stamped with it, so the next renewal starts from where this one stopped.
 * A charge that fails stamps nothing, and the next attempt counts them again.
 */
class SiteAgentUsageMeter
{
    public function __construct(private SiteAgentBilling $billing) {}

    /**
     * One delivered reply.
     *
     * Never allowed to break the conversation: the reply has already reached
     * the customer, and losing a row of billing is better than an exception
     * after the fact.
     */
    public function record(SiteAgentSubscriber $subscriber, ?string $providerMessageId): void
    {
        try {
            $subscription = $this->billing->subscriptionForSite($subscriber->customer, $subscriber->site_id);

            SiteAgentUsage::create([
                'customer_id' => $subscriber->customer_id,
                'subscription_id' => $subscription?->id,
                'site_id' => $subscriber->site_id,
                'site_agent_subscriber_id' => $subscriber->id,
                'provider_message_id' => $providerMessageId,
                // Settled now, not at billing time: a week of trial messages
                // must not turn into a line on the first real invoice, and a
                // plan that starts pricing messages must not reach back to
                // the ones sent before it did.
                'billable' => $subscription !== null
                    && $subscription->status !== SubscriptionStatus::Trialing
                    && (bool) $subscription->plan?->billsMessages(),
                'sent_at' => now(),
            ]);
        } catch (QueryException $e) {
            // The same Meta id twice is the same message twice — counted once.
            Log::info('SiteAgentUsageMeter: message not recorded', ['error' => $e->getMessage()]);
        }
    }

    /** Billable messages of this subscription not yet on any invoice, sent up to $until. */
    public function unbilled(Subscription $subscription, CarbonInterface $until): int
    {
        return SiteAgentUsage::query()
            ->where('subscription_id', $subscription->id)
            ->where('billable', true)
            ->whereNull('charge_id')
            ->where('sent_at', '<=', $until)
            ->count();
    }

    /**
     * Stamp the messages a successful charge billed.
     *
     * Reads the cut-off the charge was built with from its own line, so it
     * stamps exactly what it counted — not whatever was sent while the card
     * was being charged.
     */
    public function settle(Charge $charge): void
    {
        $line = collect($charge->lines ?? [])->firstWhere('kind', 'messages');

        if (! is_array($line) || ! isset($line['until']) || $charge->subscription_id === null) {
            return;
        }

        SiteAgentUsage::query()
            ->where('subscription_id', $charge->subscription_id)
            ->where('billable', true)
            ->whereNull('charge_id')
            ->where('sent_at', '<=', $line['until'])
            ->update(['charge_id' => $charge->id]);
    }

    /**
     * One subscription's usage since its last invoice — for the portal, the
     * panel and the bot itself.
     *
     * The estimate is computed the way the renewal will compute it (VAT on the
     * total, not per message), so the number a customer is told is the number
     * they are charged.
     *
     * @return array{sent: int, billable: int, unit_gross_agorot: int|null, estimate_gross_agorot: int, next_charge_at: CarbonInterface|null, since: CarbonInterface|null}
     */
    public function current(Subscription $subscription): array
    {
        $since = $subscription->current_period_start;
        $billable = $this->unbilled($subscription, now());
        $plan = $subscription->plan;
        $exempt = (bool) $subscription->customer?->vat_exempt;

        return [
            'sent' => SiteAgentUsage::query()
                ->where('subscription_id', $subscription->id)
                ->when($since !== null, fn ($q) => $q->where('sent_at', '>=', $since))
                ->count(),
            'billable' => $billable,
            'unit_gross_agorot' => $plan?->messageGrossAgorot($exempt),
            'estimate_gross_agorot' => $plan?->billsMessages()
                ? $plan->withVat($billable * (int) $plan->message_price_agorot, $exempt)
                : 0,
            'next_charge_at' => $subscription->next_charge_at,
            'since' => $since,
        ];
    }
}
