<?php

namespace App\Services\SiteAgent;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\SiteAgentRequest;
use App\Models\SiteAgentSubscriber;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;

/**
 * סוכן האתר כמוצר — האם הוא יכול לעבוד, מי משלם עליו, ומה הוא עושה.
 *
 * Everything else about this product answers a question about one customer:
 * may this number act, what does this request say, when did it lapse. Nobody
 * was asking the question a business asks about something it sells — is it
 * switched on, how many pay for it, and how much does it bring in — and so the
 * panel could not answer it either.
 *
 * The readiness half matters more than the numbers. This product speaks to
 * customers through Meta's Cloud API, where anything WE start — a verification
 * code, a "subscription lapsed" notice — is refused outright unless it goes out
 * on an approved template. A missing template name is therefore not a degraded
 * feature: the very first message to a new customer never arrives, there is no
 * error on any screen, and the customer is simply left holding a phone that
 * never beeped. That is what readiness() exists to say out loud.
 */
class SiteAgentProduct
{
    /**
     * What the product needs in order to work, and whether it is there.
     *
     * Ordered the way it breaks: nothing below matters while the switch is off,
     * and nothing below the number matters while there is no number to send
     * from. Each entry says what STOPS working without it, in the words of the
     * person it stops working for — "the customer never gets a code" is
     * actionable, "SITE_AGENT_WA_TEMPLATE_VERIFICATION is empty" is not.
     *
     * @return list<array{key: string, label: string, ready: bool, detail: string}>
     */
    public function readiness(): array
    {
        $templates = (array) config('siteagent.whatsapp.templates', []);

        return [
            $this->requirement(
                'enabled',
                'השירות מופעל',
                $this->enabled(),
                'כבוי. המספר עונה שהשירות אינו זמין, גם ללקוח שמשלם עליו.',
            ),
            $this->requirement(
                'number',
                'מספר הוואטסאפ',
                filled(config('siteagent.whatsapp.phone_number_id')) && filled(config('siteagent.whatsapp.token')),
                'חסר מזהה מספר או טוקן. בלעדיהם הסוכן אינו יכול לשלוח דבר.',
            ),
            $this->requirement(
                'app_secret',
                'אימות חתימת מטא',
                filled(config('siteagent.whatsapp.app_secret')),
                'חסר סוד האפליקציה. בלעדיו אי אפשר לאמת שהודעה נכנסת הגיעה ממטא, וכל ההודעות נדחות.',
            ),
            $this->requirement(
                'verify_token',
                'טוקן אימות ה-Webhook',
                filled(config('siteagent.whatsapp.verify_token')),
                'חסר. מטא לא תאשר את כתובת ה-Webhook, ולכן לא תישלח אף הודעה נכנסת.',
            ),
            $this->requirement(
                'template_verification',
                'תבנית קוד האימות',
                filled($templates['verification'] ?? null),
                'חסרה. קוד האימות נשלח למספר שמעולם לא כתב אלינו — מחוץ לחלון 24 השעות — ולכן מטא תדחה אותו. '
                    .'לקוח חדש לא יקבל קוד, ובלעדיו אינו יכול להתחיל להשתמש במוצר.',
            ),
            $this->requirement(
                'template_paused',
                'תבנית "המנוי מושהה"',
                filled($templates['service_paused'] ?? null),
                'חסרה. לקוח שהמנוי שלו נפסק לא יקבל הודעה על כך, והסוכן פשוט ישתוק בלי הסבר.',
            ),
            $this->requirement(
                'template_resumed',
                'תבנית "המנוי חזר"',
                filled($templates['service_resumed'] ?? null),
                'חסרה. לקוח ששילם וחידש לא יקבל הודעה שהסוכן חזר לעבוד.',
            ),
        ];
    }

    /** The product is switched on at all. */
    public function enabled(): bool
    {
        return (bool) config('siteagent.enabled');
    }

    /**
     * Only what is missing — the list a person has to act on.
     *
     * @return list<array{key: string, label: string, ready: bool, detail: string}>
     */
    public function missing(): array
    {
        return array_values(array_filter($this->readiness(), fn (array $check): bool => ! $check['ready']));
    }

    /** Everything the product needs is in place. */
    public function ready(): bool
    {
        return $this->missing() === [];
    }

    /**
     * The numbers the product answers, by the state they are actually in.
     *
     * "paused" is the one worth having a name for: a number that proved itself
     * and was never revoked, whose customer has stopped paying. Nothing is
     * broken and nobody did anything wrong — the agent has simply gone quiet on
     * a person who was using it yesterday, and that is a phone call the team
     * would rather make than receive.
     *
     * @return array{active: int, pending: int, paused: int, revoked: int}
     */
    public function numbers(): array
    {
        return [
            'active' => (int) $this->entitled(SiteAgentSubscriber::query()->usable())->count(),
            'pending' => (int) SiteAgentSubscriber::query()
                ->whereNull('verified_at')
                ->whereNull('revoked_at')
                ->count(),
            'paused' => (int) SiteAgentSubscriber::query()
                ->usable()
                ->whereDoesntHave('customer.subscriptions', fn (Builder $query) => SiteAgentAccess::entitling($query))
                ->count(),
            'revoked' => (int) SiteAgentSubscriber::query()->whereNotNull('revoked_at')->count(),
        ];
    }

    /**
     * What the product brings in.
     *
     * Recurring revenue counts ACTIVE subscriptions only, while the subscriber
     * count above counts trials too. That is deliberate and it is why the two
     * numbers are allowed to disagree: a trial entitles somebody to use the
     * product and bills nothing for it, so counting it as income would report
     * money that is not coming.
     *
     * `unbilled` is the pair of facts that hides between them — a trial with no
     * saved card. The scheduler skips it for want of a token and the debtor
     * screens skip it for being a trial, so it is a customer using the product
     * that nothing at all will ever charge.
     *
     * `monthly_agorot` is per month whatever the plan's billing cycle is — see
     * perMonthAgorot().
     *
     * @return array{subscribed: int, monthly_agorot: int, past_due: int, unbilled: int}
     */
    public function money(): array
    {
        $active = SiteAgentAccess::entitling(Subscription::query())
            ->where('status', SubscriptionStatus::Active)
            // totalChargeAgorot() reads the plan's price and the customer's VAT
            // exemption; without both this is two queries per subscription.
            ->with(['plan', 'customer'])
            ->get();

        return [
            'subscribed' => (int) SiteAgentAccess::entitling(Subscription::query())->count(),
            'monthly_agorot' => (int) $active->sum(fn (Subscription $subscription): int => $this->perMonthAgorot($subscription)),
            'past_due' => (int) Subscription::query()
                ->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended])
                ->whereHas('plan', fn (Builder $plan) => $plan->where('includes_site_agent', true))
                ->count(),
            'unbilled' => (int) SiteAgentAccess::unbilled(Subscription::query())->count(),
        ];
    }

    /**
     * What customers actually did with it, over the last week.
     *
     * A week rather than today, because the product is used in bursts — a shop
     * owner changes five prices on Sunday and nothing until Thursday — and a
     * daily count reads as "nobody is using this" on a perfectly healthy
     * Tuesday.
     *
     * @return array{awaiting: int, applied: int, failed: int}
     */
    public function activity(): array
    {
        $since = now()->subWeek();

        return [
            'awaiting' => (int) SiteAgentRequest::query()->awaitingConfirmation()->count(),
            'applied' => (int) SiteAgentRequest::query()
                ->where('state', SiteAgentRequest::APPLIED)
                ->where('applied_at', '>=', $since)
                ->count(),
            'failed' => (int) SiteAgentRequest::query()
                ->where('state', SiteAgentRequest::FAILED)
                ->where('updated_at', '>=', $since)
                ->count(),
        ];
    }

    /**
     * One subscription's contribution to a MONTHLY figure.
     *
     * `totalChargeAgorot()` is what the customer is charged each billing cycle,
     * and the cycle is not always a month: the activation screen accepts any
     * active plan carrying the agent flag, a yearly one included. Summed as-is,
     * a ₪1,200-a-year customer would be reported as ₪1,200 a month — a tile
     * that overstates the product's income twelvefold and reads perfectly
     * plausibly while doing it.
     *
     * Rounded rather than floored so twelve months of a yearly plan still add
     * up to roughly the year, instead of quietly losing up to 11 agorot a month
     * across every such customer.
     */
    private function perMonthAgorot(Subscription $subscription): int
    {
        $charge = $subscription->totalChargeAgorot();

        return $subscription->plan?->billing_interval === BillingInterval::Yearly
            ? (int) round($charge / 12)
            : $charge;
    }

    /** Subscribers whose customer holds a subscription that switches the agent on. */
    private function entitled(Builder $subscribers): Builder
    {
        return $subscribers->whereHas('customer.subscriptions', fn (Builder $query) => SiteAgentAccess::entitling($query));
    }

    /** @return array{key: string, label: string, ready: bool, detail: string} */
    private function requirement(string $key, string $label, bool $ready, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ready' => $ready,
            'detail' => $ready ? 'מוגדר.' : $detail,
        ];
    }
}
