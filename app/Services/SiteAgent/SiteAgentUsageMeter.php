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
    /** Most of the text's words were already in the owner's own message: they wrote it. */
    public const OWNER_TEXT_SHARE = 0.7;

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
     * One writing unit, for a long text our AI wrote into an offer.
     *
     * Counted when the text is written, not when it goes live: the tokens are
     * spent the moment it is generated, whether the owner then says "כן",
     * "לא", asks for another version, or the save fails. Each offer is its own
     * draft, so a rewrite is a new unit. Keyed by the offer, so retries of the
     * same one never count twice.
     *
     * A text the owner wrote themselves and asked to put up is not ours to
     * bill: it cost no generation. See writtenByUs().
     */
    public function recordWriting(SiteAgentRequest $request): void
    {
        $words = self::writtenByUs($request);
        $subscriber = $request->subscriber;

        if ($words === 0 || $subscriber === null) {
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
     * The words of a long text our AI wrote into this offer — or 0 when it is
     * short, or when the owner wrote it and only asked us to put it up.
     *
     * "Wrote it themselves" is read from the words, not the wording of the
     * request: when most of the text's words already appear in what the owner
     * sent, the text came from them and no generation was spent on it.
     */
    public static function writtenByUs(SiteAgentRequest $request): int
    {
        $text = self::writingText((array) $request->plan);
        $words = self::words($text);

        if (count($words) <= (int) config('siteagent.writing.min_words', 300)) {
            return 0;
        }

        $theirs = array_flip(self::words((string) $request->message));
        $shared = count(array_filter($words, fn (string $word): bool => isset($theirs[$word])));

        return $shared / count($words) >= self::OWNER_TEXT_SHARE ? 0 : count($words);
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
        return count(self::words(self::writingText($plan)));
    }

    /** @param array<string, mixed> $plan */
    private static function writingText(array $plan): string
    {
        $fields = (array) ($plan['fields'] ?? []);

        return implode(' ', array_map('strval', array_filter([
            $plan['text'] ?? null,
            $fields['content'] ?? null,
            $fields['excerpt'] ?? null,
            $fields['description'] ?? null,
            $fields['short_description'] ?? null,
        ], fn ($text): bool => is_string($text) && $text !== '')));
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        // Tags become spaces before they go: "<p>one</p><p>two</p>" is two
        // words, and strip_tags alone would glue them into one.
        $plain = trim(html_entity_decode(strip_tags((string) preg_replace('/<[^>]*>/', ' ', $text)), ENT_QUOTES | ENT_HTML5));

        return $plain === '' ? [] : array_values(array_map(
            fn (string $word): string => mb_strtolower(trim($word, ".,;:!?\"'()[]{}–—-")),
            preg_split('/\s+/u', $plain) ?: [],
        ));
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

        $plan = $charge->subscription?->plan;
        $priced = array_keys(array_filter([
            SiteAgentUsage::MESSAGE => (bool) $plan?->billsMessages(),
            SiteAgentUsage::WRITING => (bool) $plan?->billsWritings(),
        ]));

        $rows = fn () => SiteAgentUsage::query()
            ->where('subscription_id', $charge->subscription_id)
            ->where('billable', true)
            ->whereNull('charge_id')
            ->where('sent_at', '<=', $until);

        // The kinds this charge priced are stamped with it.
        $rows()->whereIn('kind', $priced)->update(['charge_id' => $charge->id]);

        // A kind the plan no longer prices was not on this charge and never
        // will be: closed as not billable, rather than waiting for ever — or
        // being stamped as paid when it was not.
        $rows()->whereNotIn('kind', $priced)->update(['billable' => false]);
    }

    /** Is any billable row of this subscription still waiting, of any kind? */
    public function anyUnsettled(Subscription $subscription, CarbonInterface $until): bool
    {
        return SiteAgentUsage::query()
            ->where('subscription_id', $subscription->id)
            ->where('billable', true)
            ->whereNull('charge_id')
            ->where('sent_at', '<=', $until)
            ->exists();
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

        // A plan that does not price messages has nothing for a ceiling to hold.
        return $cap !== null && (bool) $subscription->plan?->billsMessages()
            && $this->unbilled($subscription, now()) >= $cap;
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
