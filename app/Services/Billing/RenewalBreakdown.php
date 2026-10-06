<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * What one renewal of a subscription costs, and how the invoice says it.
 *
 * Up to three lines, all integer agorot:
 *
 *   1. the plan, for the period being opened            (in advance)
 *   2. the additional manager numbers, for that period  (in advance)
 *   3. the messages the bot sent since the last invoice (in arrears), less
 *      the plan's included messages — only the ones beyond are charged
 *
 * VAT is computed once, on the net total, exactly as Subscription::vatAgorot()
 * always has — so a subscription with no extras and no messages costs to the
 * agora what it cost before this existed, and keeps its single-line invoice.
 *
 * The lines are VAT-inclusive (that is how Linet takes them) and must add up
 * to the total exactly. Each secondary line is rounded on its own, and the plan
 * line takes whatever agora of rounding is left, so the sum is never off by one.
 *
 * Every line is quantity 1 with the count in its name: "1,240 הודעות". A
 * quantity of 1,240 at a rounded unit price would not multiply back to the
 * total, and an invoice that does not add up is worse than one less granular.
 */
class RenewalBreakdown
{
    public function __construct(private SiteAgentUsageMeter $usage) {}

    /**
     * Amount, VAT, total and lines for collecting this period, counting
     * messages sent up to $until.
     *
     * `usage_until` is the cut-off the messages were counted to — null when
     * none were. The charge keeps it, and SiteAgentUsageMeter::settle stamps
     * exactly those messages when the charge succeeds, line or no line.
     *
     * @return array{amount_agorot: int, vat_agorot: int, total_agorot: int, lines: list<array<string, mixed>>|null, usage_until: CarbonInterface|null}
     */
    public function for(Subscription $subscription, CarbonInterface $periodStart, CarbonInterface $periodEnd, CarbonInterface $until): array
    {
        $plan = $subscription->plan;
        $planNet = $subscription->basePriceAgorot() - $subscription->extraNumbersAgorot();
        $extrasNet = $subscription->extraNumbersAgorot();

        $messages = $plan?->billsMessages() ? $this->usage->unbilled($subscription, $until) : 0;
        $included = min($messages, (int) ($plan?->included_messages ?? 0));
        $charged = $messages - $included;
        $messagesNet = $charged * (int) ($plan?->message_price_agorot ?? 0);
        $usageUntil = $messages > 0 ? $until : null;

        $net = $planNet + $extrasNet + $messagesNet;
        $vat = $this->vat($subscription, $net);
        $total = $net + $vat;

        // Nothing beyond the plan itself: the invoice stays exactly as it was.
        if ($extrasNet === 0 && $messagesNet === 0) {
            return ['amount_agorot' => $net, 'vat_agorot' => $vat, 'total_agorot' => $total, 'lines' => null, 'usage_until' => $usageUntil];
        }

        $extras = [];

        if ($extrasNet > 0) {
            $count = (int) $subscription->agent_extra_numbers;
            $extras[] = [
                'kind' => 'extra_numbers',
                'name' => "מספרים נוספים לניהול האתר ({$count})",
                'qty' => 1,
                'unit_price_agorot' => $this->gross($subscription, $extrasNet),
            ];
        }

        if ($messagesNet > 0) {
            $extras[] = [
                'kind' => 'messages',
                'name' => 'הודעות בוט ניהול האתר: '.($included > 0
                    ? number_format($messages).' (מתוכן '.number_format($included).' כלולות במנוי) — '.number_format($charged)
                    : number_format($messages))
                    .' × '.Money::ils((int) $plan->message_price_agorot).' (עד '.$until->format('d/m/Y').')',
                'qty' => 1,
                'unit_price_agorot' => $this->gross($subscription, $messagesNet),
                'count' => $messages,
                'included' => $included,
                // The cut-off this charge counted to. SiteAgentUsageMeter::settle
                // stamps exactly these messages when the charge succeeds.
                'until' => $until->toIso8601String(),
            ];
        }

        $first = [
            'kind' => 'plan',
            'name' => $subscription->chargeDescription($periodStart, $periodEnd),
            'qty' => 1,
            'unit_price_agorot' => $total - array_sum(array_column($extras, 'unit_price_agorot')),
        ];

        return [
            'amount_agorot' => $net,
            'vat_agorot' => $vat,
            'total_agorot' => $total,
            'lines' => [$first, ...$extras],
            'usage_until' => $usageUntil,
        ];
    }

    private function vat(Subscription $subscription, int $net): int
    {
        if ($subscription->customer?->vat_exempt || ! $subscription->vatApplies()) {
            return 0;
        }

        return (int) round($net * config('billing.vat_rate'));
    }

    private function gross(Subscription $subscription, int $net): int
    {
        return $net + $this->vat($subscription, $net);
    }
}
