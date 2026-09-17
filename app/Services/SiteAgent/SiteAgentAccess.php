<?php

namespace App\Services\SiteAgent;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;

/**
 * May this number drive this site right now?
 *
 * One place answers it, because it is asked on every inbound message and the
 * two failure directions are both bad in their own way: letting a number
 * through means somebody rewriting a site they do not own, and turning a paying
 * customer away means the product silently not working for exactly the person
 * who bought it.
 *
 * The answer is a reason, never a bare bool. "לא" and "לא, כי המנוי הופסק"
 * are the same refusal to the code and completely different messages to the
 * person waiting for an answer on their phone.
 */
class SiteAgentAccess
{
    /** Verified, not revoked, subscribed, and the site is connected. */
    public const ALLOWED = 'allowed';

    /** The product itself is switched off. */
    public const OFF = 'off';

    /** Nobody bound this number to a site. */
    public const UNKNOWN_NUMBER = 'unknown_number';

    /** Bound, but they never proved they hold the number. */
    public const UNVERIFIED = 'unverified';

    /** A person took the access away. */
    public const REVOKED = 'revoked';

    /** No live subscription that includes the agent. */
    public const NO_SUBSCRIPTION = 'no_subscription';

    /** Subscribed, but the site is not reachable — nothing can be done to it. */
    public const SITE_DISCONNECTED = 'site_disconnected';

    /**
     * Statuses that entitle a customer to the agent.
     *
     * A trial counts: somebody evaluating the product has to be able to use it.
     * PastDue does NOT — the owner chose that the agent stops when payment
     * stops, and a subscription in arrears is one that is not being paid. The
     * site itself keeps working either way; only the agent goes quiet.
     */
    public const ENTITLING = [SubscriptionStatus::Active, SubscriptionStatus::Trialing];

    /**
     * @return array{status: string, subscriber: SiteAgentSubscriber|null, site: Site|null, customer: Customer|null}
     */
    public function for(string $phone): array
    {
        $none = ['status' => self::OFF, 'subscriber' => null, 'site' => null, 'customer' => null];

        if (! (bool) config('siteagent.enabled', false)) {
            return $none;
        }

        $subscriber = SiteAgentSubscriber::query()
            ->with(['customer', 'site'])
            ->where('phone', $phone)
            ->orderByDesc('verified_at')
            ->first();

        if ($subscriber === null) {
            return [...$none, 'status' => self::UNKNOWN_NUMBER];
        }

        $found = [
            'subscriber' => $subscriber,
            'site' => $subscriber->site,
            'customer' => $subscriber->customer,
        ];

        if ($subscriber->revoked_at !== null) {
            return [...$found, 'status' => self::REVOKED];
        }

        if ($subscriber->verified_at === null) {
            return [...$found, 'status' => self::UNVERIFIED];
        }

        if ($subscriber->customer === null || ! $this->subscribed($subscriber->customer)) {
            return [...$found, 'status' => self::NO_SUBSCRIPTION];
        }

        $site = $subscriber->site;

        if ($site === null || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return [...$found, 'status' => self::SITE_DISCONNECTED];
        }

        return [...$found, 'status' => self::ALLOWED];
    }

    /**
     * Does this customer hold a live subscription that includes the agent?
     *
     * Read from the plan's own flag, so renaming a plan or opening a second one
     * at a different price cannot quietly switch a paying customer off — or
     * switch on somebody who never bought it.
     */
    public function subscribed(Customer $customer): bool
    {
        // getQuery(): the relation already carries the customer constraint, and
        // what entitling() needs is the Eloquent builder underneath it.
        return self::entitling($customer->subscriptions()->getQuery())->exists();
    }

    /**
     * The one definition of "a subscription that switches the agent on",
     * expressed as a constraint on a subscriptions query.
     *
     * Every screen and job that has to ask this of many rows at once — the
     * subscriber list, the pause/resume reconciliation, the activation screen —
     * asks it through here. The question is "is this customer paying for the
     * agent", and two versions of that predicate drifting apart means one
     * screen showing a customer as paying while the agent turns them away.
     *
     * @param  Builder<Subscription>  $subscriptions
     * @return Builder<Subscription>
     */
    public static function entitling(Builder $subscriptions): Builder
    {
        return $subscriptions
            ->whereIn('status', self::ENTITLING)
            ->whereHas('plan', fn (Builder $plan) => $plan->where('includes_site_agent', true));
    }

    /**
     * Agent subscriptions that entitle but cannot bill: a trial with no card.
     *
     * Its own predicate because it is invisible everywhere else — the scheduler
     * skips it for want of a token, the debtor screens skip it for being a
     * trial, and the customer is using a product nobody is charging for.
     *
     * @param  Builder<Subscription>  $subscriptions
     * @return Builder<Subscription>
     */
    public static function unbilled(Builder $subscriptions): Builder
    {
        return $subscriptions
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNull('token_id')
            ->whereHas('plan', fn (Builder $plan) => $plan->where('includes_site_agent', true));
    }
}
