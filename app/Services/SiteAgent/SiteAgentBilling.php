<?php

namespace App\Services\SiteAgent;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use App\Support\CardLink;

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
    public function __construct(private WhatsAppCloudClient $whatsapp) {}

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
            $this->recovery($subscriber, $subscription),
        ]));
    }

    /**
     * The same two facts, as positional parameters for the approved template.
     *
     * Used when the notice is the one WE start, which is outside any service
     * window and therefore has to be a template. The wording lives in the
     * template Meta approved; what varies — which site, and what to do about it
     * — comes from here, so the two never say different things.
     *
     * @return list<string> {{1}} the domain · {{2}} what to do next
     */
    public function pausedTemplateParameters(SiteAgentSubscriber $subscriber): array
    {
        $subscription = $subscriber->customer !== null
            ? $this->subscriptionFor($subscriber->customer)
            : null;

        $subscription?->setRelation('customer', $subscriber->customer);

        return [
            $subscriber->site?->domain ?? '',
            $this->recovery($subscriber, $subscription),
        ];
    }

    /** @return list<string> {{1}} the domain */
    public function resumedTemplateParameters(SiteAgentSubscriber $subscriber): array
    {
        return [$subscriber->site?->domain ?? ''];
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
     * The one actionable line — and the decision about whether a payment link
     * may be sent to THIS number at all.
     *
     * A card link is an invitation to enter a business's card details, so it
     * goes only to the number the customer record itself carries. The product
     * allows a business to hand the agent to an employee or to an agency, and
     * handing them a payment page for their client's business by way of a
     * courtesy notification is not a thing we get to do quietly.
     *
     * It is also withheld from a customer who pays by transfer or standing
     * order: sending them a card page tells them to pay a second time, by a
     * method they explicitly did not choose.
     */
    private function recovery(SiteAgentSubscriber $subscriber, ?Subscription $subscription): string
    {
        $support = trim((string) config('billing.email.support_address'));
        $contact = $support !== '' ? 'לכל שאלה: '.$support : 'לכל שאלה אנחנו כאן.';

        $inArrears = in_array($subscription?->status, [
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Suspended,
        ], true);

        if (! $inArrears || $subscription->isManuallyCollected() || ! $this->isCustomerOwnNumber($subscriber)) {
            return 'לחידוש המנוי דברו איתנו ונפעיל מחדש. '.$contact;
        }

        return implode("\n", [
            'לעדכון אמצעי התשלום ולחידוש מיידי:',
            CardLink::for($subscriber->customer_id),
            '',
            $contact,
        ]);
    }

    /**
     * Is this the number on the customer record itself?
     *
     * Compared in the normalised form both sides are stored in, so 050-1234567
     * on the customer and 972501234567 on the subscriber are recognised as the
     * same person rather than treated as a stranger.
     */
    private function isCustomerOwnNumber(SiteAgentSubscriber $subscriber): bool
    {
        $customer = $subscriber->customer;

        if ($customer === null || $subscriber->phone === '') {
            return false;
        }

        foreach ([$customer->phone, $customer->whatsapp_jid] as $candidate) {
            if (blank($candidate)) {
                continue;
            }

            if (hash_equals($subscriber->phone, $this->whatsapp->normalize((string) $candidate))) {
                return true;
            }
        }

        return false;
    }
}
