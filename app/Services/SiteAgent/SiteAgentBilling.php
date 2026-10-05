<?php

namespace App\Services\SiteAgent;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;

/**
 * The money side of the product, said in words a site owner can act on.
 *
 * The agent stops when the subscription stops — that is the arrangement, and it
 * is enforced live by SiteAgentAccess on every single message. What this class
 * adds is the other half of it: telling the person holding the phone WHY their
 * agent went quiet, and giving them the one thing that fixes it.
 *
 * "המנוי אינו פעיל" on its own is how a paying customer concludes the product
 * is broken. "התשלום לא נקלט — הנה קישור לעדכון הכרטיס" is how they fix it in
 * thirty seconds, which is the difference between a lapse and a churn.
 */
class SiteAgentBilling
{
    /**
     * The subscription that carries the agent for this customer, if any.
     *
     * Almost always exactly one. When there are several — a lapsed one and its
     * replacement, the usual shape after a customer re-subscribes — the live one
     * is the one that describes reality, then the one in arrears, and only then
     * a closed one. Picking the newest row instead would answer "המנוי הסתיים"
     * to somebody whose new subscription is running perfectly well.
     */
    public function subscriptionFor(Customer $customer): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('customer_id', $customer->id)
            ->whereHas('plan', fn ($query) => $query->where('includes_site_agent', true))
            ->orderByDesc('id')
            ->get()
            // PHP's sort is stable, so equal ranks keep the newest-first order
            // above. Written as a rank rather than a SQL CASE so the ordering
            // reads the same as the sentence that explains it.
            ->sortByDesc(fn (Subscription $subscription): int => match ($subscription->status) {
                SubscriptionStatus::Active, SubscriptionStatus::Trialing => 3,
                SubscriptionStatus::PastDue, SubscriptionStatus::Suspended => 2,
                default => 1,
            })
            ->first();
    }

    /**
     * The subscription that carries the agent FOR ONE SITE.
     *
     * A customer may hold more than one: the product is sold per site, and a
     * second site is a second service at its own price rather than a free rider
     * on the first. Preferred in that order — this site's own subscription, then
     * one that names no site at all (how the team screen has always opened them,
     * and how every subscription created before sites were recorded looks).
     */
    public function subscriptionForSite(Customer $customer, ?int $siteId): ?Subscription
    {
        $candidates = Subscription::query()
            ->with('plan')
            ->where('customer_id', $customer->id)
            ->whereHas('plan', fn ($query) => $query->where('includes_site_agent', true))
            ->whereNot('status', SubscriptionStatus::Canceled)
            ->get();

        return $candidates->first(fn (Subscription $s): bool => $siteId !== null && $s->site_id === $siteId)
            ?? $candidates->first(fn (Subscription $s): bool => $s->site_id === null);
    }

    /**
     * Set the paid manager seats from what is actually bound.
     *
     * Counted rather than nudged up and down, because the two directions do not
     * stay in step. Revoking blindly decremented whichever number was removed —
     * so a customer who dropped the number their plan INCLUDES, while still
     * having a paid extra, stopped being charged for the extra that was still
     * working. That is an undercharge nobody would ever notice, on a row that
     * looks perfectly consistent.
     *
     * The first number on a site is the one the plan includes; everything beyond
     * it is paid. A subscription that names no site falls back to counting the
     * customer's numbers, which is the only thing such a row can mean.
     */
    public function recountManagerSeats(Subscription $subscription): void
    {
        $bound = SiteAgentSubscriber::query()
            ->whereNull('revoked_at')
            ->when(
                $subscription->site_id !== null,
                fn ($query) => $query->where('site_id', $subscription->site_id),
                fn ($query) => $query->where('customer_id', $subscription->customer_id),
            )
            ->count();

        $subscription->update(['agent_extra_numbers' => max(0, $bound - 1)]);
    }

    /**
     * What to say when the agent is not available to this number.
     *
     * Three things, in this order, because that is the order the person on the
     * other end needs them: what happened, what did NOT happen to their site,
     * and what to do now.
     */
    public function pausedMessage(SiteAgentSubscriber $subscriber): string
    {
        $subscription = $subscriber->customer !== null
            ? $this->subscriptionFor($subscriber->customer)
            : null;

        // The customer is already in hand. Handing it over rather than letting
        // the subscription fetch it again is the difference between one query
        // and one per notice on a run that touches every lapsed customer.
        $subscription?->setRelation('customer', $subscriber->customer);

        $reason = match ($subscription?->status) {
            SubscriptionStatus::PastDue, SubscriptionStatus::Suspended => 'התשלום על מנוי ניהול האתר לא נקלט, ולכן הסוכן מושהה.',
            SubscriptionStatus::Canceled => 'מנוי ניהול האתר הסתיים, ולכן הסוכן מושהה.',
            default => 'מנוי ניהול האתר אינו פעיל כרגע, ולכן איני יכול לבצע שינויים.',
        };

        return implode("\n", array_filter([
            $reason,
            '',
            // Said every time, deliberately. The first thing a business owner
            // fears when an automated service writes to them is that something
            // of theirs was switched off.
            'האתר עצמו ממשיך לעבוד כרגיל, ושום שינוי שכבר בוצע לא בוטל.',
            '',
            $this->recovery(),
        ]));
    }

    /**
     * The one thing that varies, for the approved template: which site.
     *
     * Used when the notice is the one WE start, which is outside any service
     * window and therefore has to be a template.
     *
     * The way back — "sign in to your account" and the address — is NOT a
     * parameter. It is the same sentence for every customer in every state, so
     * passing it in would be sending a constant over the wire and asking Meta
     * to render it; as fixed text in the approved body it is reviewed once,
     * cannot be truncated or mangled by the parameter rules, and leaves a
     * template with a single variable to get wrong.
     *
     * @return array<string, string> the domain
     */
    public function pausedTemplateParameters(SiteAgentSubscriber $subscriber): array
    {
        return ['domain' => $subscriber->site?->domain ?? ''];
    }

    /** @return array<string, string> the domain */
    public function resumedTemplateParameters(SiteAgentSubscriber $subscriber): array
    {
        return ['domain' => $subscriber->site?->domain ?? ''];
    }

    /** What to say when it comes back. Short: the good news is the message. */
    public function resumedMessage(SiteAgentSubscriber $subscriber): string
    {
        return implode("\n", [
            '✅ מנוי ניהול האתר פעיל שוב, והסוכן חזר לעבוד.',
            '',
            'אפשר להמשיך לכתוב לי מה לשנות באתר '.($subscriber->site?->domain ?? 'שלכם').'.',
        ]);
    }

    /**
     * The one actionable line: where to go and renew.
     *
     * Used by the free-text message — the one a customer gets when they write
     * to the agent themselves, inside the service window. The template says the
     * same thing in its own approved body; the two are kept saying it the same
     * way on purpose, because somebody who writes a week after the notice
     * should not get a different answer than the notice gave.
     *
     * It points at the personal area's SIGN-IN page, and that is the whole
     * design. A signed card-update link is an invitation to type a business's
     * card details, and this product deliberately lets a business hand the
     * agent to an employee or to an agency — so a notice that carried such a
     * link would be mailing a payment page for somebody else's business to
     * whoever happens to hold that phone. The sign-in page gives away nothing:
     * whoever opens it still has to receive a login link on the address or
     * number the customer record itself carries, which is exactly the check we
     * would otherwise have to write here and get right.
     *
     * The same line therefore suits every case — arrears, cancellation, a
     * customer who pays by transfer — because what is on the other side of it
     * is their own account, showing what is actually owed.
     */
    private function recovery(): string
    {
        $support = trim((string) config('billing.email.support_address'));
        $contact = $support !== '' ? 'לכל שאלה: '.$support : 'לכל שאלה אנחנו כאן.';

        return implode("\n", [
            'לחידוש המנוי ולעדכון אמצעי התשלום היכנסו לאזור האישי:',
            route('portal.login'),
            '',
            $contact,
        ]);
    }
}
