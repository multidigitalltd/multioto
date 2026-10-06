<?php

namespace App\Services\SiteAgent;

use App\Models\SiteAgentRequest;
use App\Support\Money;

/**
 * The line an offer carries when approving it would cost a writing unit.
 *
 * A long text is charged once it goes live, so the owner is told before the
 * "כן" — in the preview itself, next to the text they are approving — and
 * never first on the invoice. Said only where the plan actually prices
 * writing; a plan that does not has nothing to disclose.
 */
class SiteAgentWritingNotice
{
    public function __construct(private SiteAgentBilling $billing, private SiteAgentUsageMeter $usage) {}

    public function for(SiteAgentRequest $request): ?string
    {
        $words = SiteAgentUsageMeter::writtenByUs($request);
        $subscriber = $request->subscriber;

        if ($words === 0 || $subscriber === null) {
            return null;
        }

        $subscription = $this->billing->subscriptionForSite($subscriber->customer, $subscriber->site_id);
        $plan = $subscription?->plan;

        if ($plan === null || ! $plan->billsWritings()) {
            return null;
        }

        $included = (int) $plan->included_writings;
        $used = $this->usage->current($subscription)['writings'];
        $price = Money::ils((int) $plan->writingGrossAgorot((bool) $subscriber->customer?->vat_exempt));

        // Counted for the writing itself, whatever the answer to this offer —
        // and never a promise that it is included: another number of the same
        // subscription may use the last included unit first.
        return "✍️ נכתב בשבילכם טקסט של {$words} מילים — נספר כיחידת כתיבה אחת (גם אם לא תאשרו): {$price}"
            .($included > 0
                ? ', אלא אם עדיין נשארו יחידות מתוך '.number_format($included).' הכלולות במנוי (נוצלו עד עכשיו '.number_format($used).').'
                : '.');
    }
}
