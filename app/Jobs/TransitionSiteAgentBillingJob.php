<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Services\Billing\SiteAgentArrearsBilling;
use App\Services\Billing\SiteAgentBillingTransition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Reconcile repeatedly: a charge unresolved at deployment can settle later. */
class TransitionSiteAgentBillingJob implements ShouldQueue
{
    use Queueable;

    public function handle(SiteAgentBillingTransition $transition): void
    {
        $counts = [];

        Subscription::query()
            ->where(fn ($query) => $query->whereNull('billing_mode')->orWhere('billing_mode', '!=', SiteAgentArrearsBilling::MODE))
            ->whereNot('status', SubscriptionStatus::Canceled)
            ->whereNull('installments_total')
            ->whereHas('plan', fn ($query) => $query->where('includes_site_agent', true))
            ->select('id')->chunkById(100, function ($subscriptions) use ($transition, &$counts): void {
                foreach ($subscriptions as $subscription) {
                    $result = $transition->transition($subscription->id);
                    $counts[$result] = ($counts[$result] ?? 0) + 1;
                }
            });

        if (($counts[SiteAgentBillingTransition::TRANSITIONED] ?? 0) > 0) {
            SystemLog::record('info', 'billing', 'מנויי הבוט הועברו לחיוב חודשי לאחר תקופת השירות', $counts);
        }

        $blocked = ($counts[SiteAgentBillingTransition::UNRESOLVED] ?? 0) + ($counts[SiteAgentBillingTransition::INCOMPLETE] ?? 0);

        if ($blocked > 0 && Cache::add('site-agent-arrears-transition-review', true, now()->addDay())) {
            SystemLog::record('warning', 'billing', 'מעבר מנויי בוט לחיוב בדיעבד ממתין להסדרת חיובים או השלמת תקופות חיוב', $counts);
        }
    }
}
