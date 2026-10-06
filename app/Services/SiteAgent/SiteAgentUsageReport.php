<?php

namespace App\Services\SiteAgent;

use App\Enums\ChargeStatus;
use App\Models\AiCustomerUsage;
use App\Models\Charge;
use App\Models\SiteAgentSubscriber;
use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use Illuminate\Support\Collection;

/**
 * Who uses the site agent, what they paid, and what the AI cost to serve them.
 *
 * One row per customer with a live site-agent subscription, for a trailing
 * window: messages and writing units sent, revenue actually collected on
 * those subscriptions, AI spend booked to the customer, and how close they
 * are to the message ceiling they set. Sorted so that the customers who cost
 * more than they bring in come first — the point of the screen is to see
 * where the product loses money.
 *
 * Everything in agorot. AI spend is priced from tokens (USD) at the
 * configured rate — an estimate; the provider's invoice is the truth.
 */
class SiteAgentUsageReport
{
    public function __construct(private SiteAgentUsageMeter $usage) {}

    /**
     * @return Collection<int, array{customer_id: int, name: string, numbers: int, messages: int, writings: int, revenue_agorot: int, ai_cost_agorot: int, margin_agorot: int, cap: int|null, cap_used: int|null}>
     */
    public function rows(int $days): Collection
    {
        $since = now()->subDays($days)->startOfDay();

        $subscriptions = Subscription::query()
            ->with(['customer:id,name', 'plan:id,includes_site_agent'])
            ->whereHas('plan', fn ($query) => $query->where('includes_site_agent', true))
            // Only subscriptions the bot actually serves — the same rule access uses.
            ->whereIn('status', SiteAgentAccess::ENTITLING)
            ->get();

        if ($subscriptions->isEmpty()) {
            return collect();
        }

        $customerIds = $subscriptions->pluck('customer_id')->unique()->values();

        $usage = SiteAgentUsage::query()
            ->whereIn('customer_id', $customerIds)
            ->where('sent_at', '>=', $since)
            ->selectRaw('customer_id, kind, COUNT(*) as total')
            ->groupBy('customer_id', 'kind')
            ->get()
            ->groupBy('customer_id');

        // Renewal charges carry no customer_id of their own — the customer is
        // the subscription's. Net of VAT, to stand next to a cost that has none.
        $revenue = Charge::query()
            ->join('subscriptions', 'subscriptions.id', '=', 'charges.subscription_id')
            ->whereIn('charges.subscription_id', $subscriptions->pluck('id'))
            ->where('charges.status', ChargeStatus::Succeeded)
            ->where('charges.charged_at', '>=', $since)
            ->selectRaw('subscriptions.customer_id as cid, SUM(charges.amount_agorot) as total')
            ->groupBy('subscriptions.customer_id')
            ->pluck('total', 'cid');

        $aiCost = AiCustomerUsage::query()
            ->whereIn('customer_id', $customerIds)
            ->where('date', '>=', $since->toDateString())
            ->get(['customer_id', 'model', 'input_tokens', 'output_tokens'])
            ->groupBy('customer_id')
            ->map(fn (Collection $rows): int => (int) round(
                $rows->sum(fn (AiCustomerUsage $row): float => $row->costUsd()) * (float) config('billing.ai.usd_ils_rate', 3.7) * 100,
            ));

        $numbers = SiteAgentSubscriber::query()
            ->whereIn('customer_id', $customerIds)
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at')
            ->selectRaw('customer_id, COUNT(*) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        return $subscriptions
            ->groupBy('customer_id')
            ->map(function (Collection $theirs, int $customerId) use ($usage, $revenue, $aiCost, $numbers): array {
                $kinds = ($usage[$customerId] ?? collect())->pluck('total', 'kind');
                $capped = $theirs->first(fn (Subscription $s): bool => $s->site_agent_message_cap !== null);
                $paid = (int) ($revenue[$customerId] ?? 0);
                $cost = (int) ($aiCost[$customerId] ?? 0);

                return [
                    'customer_id' => $customerId,
                    'name' => (string) $theirs->first()->customer?->name,
                    'numbers' => (int) ($numbers[$customerId] ?? 0),
                    'messages' => (int) ($kinds[SiteAgentUsage::MESSAGE] ?? 0),
                    'writings' => (int) ($kinds[SiteAgentUsage::WRITING] ?? 0),
                    'revenue_agorot' => $paid,
                    'ai_cost_agorot' => $cost,
                    'margin_agorot' => $paid - $cost,
                    'cap' => $capped?->site_agent_message_cap,
                    'cap_used' => $capped !== null ? $this->usage->unbilled($capped, now()) : null,
                ];
            })
            ->sortBy('margin_agorot')
            ->values();
    }
}
