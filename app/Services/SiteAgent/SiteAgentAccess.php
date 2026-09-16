<?php

namespace App\Services\SiteAgent;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SiteAgentSubscriber;

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
        return $customer->subscriptions()
            ->whereIn('status', self::ENTITLING)
            ->whereHas('plan', fn ($query) => $query->where('includes_site_agent', true))
            ->exists();
    }
}
