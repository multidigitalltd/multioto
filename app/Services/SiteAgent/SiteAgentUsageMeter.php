<?php

namespace App\Services\SiteAgent;

use App\Enums\SubscriptionStatus;
use App\Models\Charge;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
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
    /** The share of the cap at which the customer is told, once per cycle. */
    public const CAP_WARNING_SHARE = 0.8;

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

            if ($subscription?->site_agent_message_cap !== null) {
                // The owner's ceiling is held here, where the row is written,
                // under one lock per subscription: two workers that both passed
                // an earlier "under the cap?" check — two numbers, or a reply
                // racing a lead alert — cannot both bill the last message. One
                // over the ceiling is still delivered and recorded, never billed.
                Cache::lock("site-agent:usage:{$subscription->id}", 10)->block(5, function () use ($subscriber, $subscription, $providerMessageId): void {
                    $this->write($subscriber, $subscription, $providerMessageId, ! $this->capReached($subscription));
                });

                return;
            }

            $this->write($subscriber, $subscription, $providerMessageId, true);
        } catch (QueryException $e) {
            // The same Meta id twice is the same message twice — counted once.
            Log::info('SiteAgentUsageMeter: message not recorded', ['error' => $e->getMessage()]);
        } catch (LockTimeoutException) {
            Log::warning('SiteAgentUsageMeter: usage lock timed out, message not recorded', ['subscriber_id' => $subscriber->id]);
        }
    }

    /**
     * One writing unit, for an approved change that put more than the
     * threshold of words on the site.
     *
     * Recorded after the change went live, never at the offer: a text the
     * owner declined or that failed to save is not written. Keyed by the
     * request, so one approved change is one unit whatever retries.
     */
    public function recordWriting(SiteAgentRequest $request): void
    {
        $words = self::writingWords((array) $request->plan);
        $subscriber = $request->subscriber;

        if ($words <= (int) config('siteagent.writing.min_words', 300) || $subscriber === null) {
            return;
        }

        try {
            $subscription = $this->billing->subscriptionForSite($subscriber->customer, $subscriber->site_id);

            SiteAgentUsage::create([
                'kind' => SiteAgentUsage::WRITING,
                'words' => $words,
                'customer_id' => $subscriber->customer_id,
                'subscription_id' => $subscription?->id,
                'site_id' => $request->site_id,
                'site_agent_subscriber_id' => $subscriber->id,
                'provider_message_id' => "writing:{$request->id}",
                'billable' => $subscription !== null
                    && $subscription->status !== SubscriptionStatus::Trialing
                    && (bool) $subscription->plan?->billsWritings(),
                'sent_at' => now(),
            ]);
        } catch (QueryException $e) {
            Log::info('SiteAgentUsageMeter: writing unit not recorded', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The words of new text a change puts on the site: a page section, a
     * post's content and excerpt, a product's descriptions. Titles, prices and
     * statuses are not writing.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function writingWords(array $plan): int
    {
        $fields = (array) ($plan['fields'] ?? []);
        $texts = [
            $plan['text'] ?? null,
            $fields['content'] ?? null,
            $fields['excerpt'] ?? null,
            $fields['description'] ?? null,
            $fields['short_description'] ?? null,
        ];

        return array_sum(array_map(function ($text): int {
            $plain = trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));

            return $plain === '' ? 0 : count(preg_split('/\s+/u', $plain) ?: []);
        }, $texts));
    }

    private function write(SiteAgentSubscriber $subscriber, ?Subscription $subscription, ?string $providerMessageId, bool $withinCap): void
    {
        SiteAgentUsage::create([
            'kind' => SiteAgentUsage::MESSAGE,
            'customer_id' => $subscriber->customer_id,
            'subscription_id' => $subscription?->id,
            'site_id' => $subscriber->site_id,
            'site_agent_subscriber_id' => $subscriber->id,
            'provider_message_id' => $providerMessageId,
            // Settled now, not at billing time: a week of trial messages
            // must not turn into a line on the first real invoice, and a
            // plan that starts pricing messages must not reach back to
            // the ones sent before it did.
            'billable' => $withinCap
                && $subscription !== null
                && $subscription->status !== SubscriptionStatus::Trialing
                && (bool) $subscription->plan?->billsMessages(),
            'sent_at' => now(),
        ]);
    }

    /**
     * Billable units of this subscription not yet on any invoice, up to $until —
     * messages by default, or writing units.
     */
    public function unbilled(Subscription $subscription, CarbonInterface $until, string $kind = SiteAgentUsage::MESSAGE): int
    {
        return SiteAgentUsage::query()
            ->where('subscription_id', $subscription->id)
            ->where('kind', $kind)
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
        // The charge's own cut-off first: a charge whose messages were all
        // included has no messages line, and they are counted all the same.
        // The line's cut-off is for charges made before the column existed.
        $line = collect($charge->lines ?? [])->firstWhere('kind', 'messages');
        $until = $charge->usage_until ?? (is_array($line) ? ($line['until'] ?? null) : null);

        if ($until === null || $charge->subscription_id === null) {
            return;
        }

        SiteAgentUsage::query()
            ->where('subscription_id', $charge->subscription_id)
            ->where('billable', true)
            ->whereNull('charge_id')
            ->where('sent_at', '<=', $until)
            ->update(['charge_id' => $charge->id]);
    }

    /**
     * Has this subscription reached the ceiling its customer set?
     *
     * Counted as the cycle's billable messages so far — the same count the
     * invoice will carry — so "you have reached 500" and the invoice agree.
     */
    public function capReached(?Subscription $subscription): bool
    {
        $cap = $subscription?->site_agent_message_cap;

        return $cap !== null && $this->unbilled($subscription, now()) >= $cap;
    }

    /**
     * Claim the 80% notice for this cycle, if it is due — atomically, so two
     * workers crossing the mark together send it once. The caller sends it and
     * calls releaseWarning() if the send failed, so a delivery that did not
     * happen is tried again rather than lost for the rest of the cycle.
     */
    public function claimWarning(Subscription $subscription): bool
    {
        if (! $this->shouldWarn($subscription)) {
            return false;
        }

        $previous = $subscription->getRawOriginal('site_agent_cap_warned_at');
        $now = now();

        $claimed = Subscription::query()
            ->whereKey($subscription->id)
            ->where(fn ($query) => $previous === null
                ? $query->whereNull('site_agent_cap_warned_at')
                : $query->where('site_agent_cap_warned_at', $previous))
            ->update(['site_agent_cap_warned_at' => $now]) === 1;

        if ($claimed) {
            $subscription->forceFill(['site_agent_cap_warned_at' => $now])->syncOriginalAttribute('site_agent_cap_warned_at');
        }

        return $claimed;
    }

    /** Give the notice back after a send that did not go through. */
    public function releaseWarning(Subscription $subscription): void
    {
        $subscription->forceFill(['site_agent_cap_warned_at' => null])->save();
    }

    /**
     * Should the 80% notice go now? True once per cycle, as the count crosses it.
     */
    public function shouldWarn(Subscription $subscription): bool
    {
        $cap = $subscription->site_agent_message_cap;

        if ($cap === null || $cap <= 0) {
            return false;
        }

        $warned = $subscription->site_agent_cap_warned_at;
        $cycle = $subscription->current_period_start;

        if ($warned !== null && ($cycle === null || $warned->gte($cycle))) {
            return false;
        }

        return $this->unbilled($subscription, now()) >= (int) ceil($cap * self::CAP_WARNING_SHARE);
    }

    /**
     * One subscription's usage since its last invoice — for the portal, the
     * panel and the bot itself.
     *
     * The estimate is computed the way the renewal will compute it (VAT on the
     * total, not per message), so the number a customer is told is the number
     * they are charged.
     *
     * @return array{writings: int, included_writings: int, writing_unit_gross_agorot: int|null, writings_estimate_gross_agorot: int, included: int, cap: int|null, sent: int, billable: int, unit_gross_agorot: int|null, estimate_gross_agorot: int, next_charge_at: CarbonInterface|null, since: CarbonInterface|null}
     */
    public function current(Subscription $subscription): array
    {
        $since = $subscription->current_period_start;
        $billable = $this->unbilled($subscription, now());
        $plan = $subscription->plan;
        $exempt = (bool) $subscription->customer?->vat_exempt;

        $included = (int) ($plan?->included_messages ?? 0);
        $charged = max(0, $billable - $included);
        $writings = $this->unbilled($subscription, now(), SiteAgentUsage::WRITING);
        $writingsCharged = max(0, $writings - (int) ($plan?->included_writings ?? 0));

        return [
            'writings' => $writings,
            'included_writings' => (int) ($plan?->included_writings ?? 0),
            'writing_unit_gross_agorot' => $plan?->writingGrossAgorot($exempt),
            'writings_estimate_gross_agorot' => $plan?->billsWritings()
                ? $plan->withVat($writingsCharged * (int) $plan->writing_price_agorot, $exempt)
                : 0,
            'included' => $included,
            'cap' => $subscription->site_agent_message_cap,
            'sent' => SiteAgentUsage::query()
                ->where('subscription_id', $subscription->id)
                ->where('kind', SiteAgentUsage::MESSAGE)
                ->when($since !== null, fn ($q) => $q->where('sent_at', '>=', $since))
                ->count(),
            'billable' => $billable,
            'unit_gross_agorot' => $plan?->messageGrossAgorot($exempt),
            'estimate_gross_agorot' => $plan?->billsMessages()
                ? $plan->withVat($charged * (int) $plan->message_price_agorot, $exempt)
                : 0,
            'next_charge_at' => $subscription->next_charge_at,
            'since' => $since,
        ];
    }
}
