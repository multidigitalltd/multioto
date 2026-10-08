<?php

namespace App\Services\Billing;

use App\Models\SiteAgentUsage;
use App\Models\Subscription;
use App\Services\SiteAgent\SiteAgentUsageMeter;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What one renewal of a subscription costs, and how the invoice says it.
 *
 * Up to four lines, all integer agorot:
 *
 *   1. the plan, for the period being opened            (in advance)
 *   2. the additional manager numbers, for that period  (in advance)
 *   3. the messages the bot sent since the last invoice (in arrears), less
 *      the plan's included messages — only the ones beyond are charged
 *   4. generated writing units, less the plan's included ones
 *
 * Site-agent arrears subscriptions instead bill the personal month that just
 * ended, including its base price. Their usage window and prices are frozen
 * with the charge, so a delayed retry never bills next month's messages.
 *
 * VAT is computed once, on the net total, exactly as Subscription::vatAgorot()
 * always has — so a subscription with no extras and no messages costs to the
 * agora what it cost before this existed, and keeps its single-line invoice.
 *
 * The lines are VAT-inclusive (that is how Linet takes them) and must add up
 * to the total exactly. Any rounding remainder is assigned to positive lines;
 * an already prepaid base remains zero and never becomes a negative invoice line.
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
        $arrears = SiteAgentArrearsBilling::applies($subscription);

        if ($arrears && $periodEnd->isFuture()) {
            throw new \LogicException('The service month must finish before it can be collected.');
        }

        // A whole second behind now: a row recorded later in this same second
        // would carry this very timestamp and be stamped as billed by a count
        // that never saw it.
        $until = CarbonImmutable::instance($arrears ? SiteAgentArrearsBilling::serviceEnd($subscription, $periodEnd) : $until)->startOfSecond()->subSecond();
        $from = $arrears ? $periodStart : null;
        $maxId = $arrears ? (int) SiteAgentUsage::query()
            ->where('subscription_id', $subscription->id)
            ->where('sent_at', '>=', $from)
            ->where('sent_at', '<=', $until)
            ->max('id') : null;

        $plan = $subscription->plan;
        $base = $arrears ? SiteAgentArrearsBilling::baseAmounts($subscription, $periodStart, $periodEnd) : null;
        $planNet = $base['plan'] ?? ($subscription->basePriceAgorot() - $subscription->extraNumbersAgorot());
        $extrasNet = $base['extras'] ?? $subscription->extraNumbersAgorot();

        $messages = $plan?->billsMessages() ? $this->usage->unbilled($subscription, $until, SiteAgentUsage::MESSAGE, $from, $maxId) : 0;
        $included = min($messages, (int) ($plan?->included_messages ?? 0));
        $charged = $messages - $included;
        $messagesNet = $charged * (int) ($plan?->message_price_agorot ?? 0);

        $writings = $plan?->billsWritings() ? $this->usage->unbilled($subscription, $until, SiteAgentUsage::WRITING, $from, $maxId) : 0;
        $writingsIncluded = min($writings, (int) ($plan?->included_writings ?? 0));
        $writingsCharged = $writings - $writingsIncluded;
        $writingsNet = $writingsCharged * (int) ($plan?->writing_price_agorot ?? 0);

        // Set whenever anything is waiting, priced or not: settle() also closes
        // the rows of a kind this plan no longer prices, so they neither wait
        // for ever nor hold a ceiling shut.
        $usageUntil = $arrears || $messages > 0 || $writings > 0 || $this->usage->anyUnsettled($subscription, $until) ? $until : null;

        $net = $planNet + $extrasNet + $messagesNet + $writingsNet;
        $vat = $this->vat($subscription, $net);
        $total = $net + $vat;

        // Nothing beyond the plan itself: the invoice stays exactly as it was.
        if (! $arrears && $extrasNet === 0 && $messagesNet === 0 && $writingsNet === 0) {
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
                // The same figure before VAT. Recorded rather than derived,
                // because deriving it means dividing the gross back out — and a
                // net reconstructed by division is not the net that was charged.
                // The message-cost report compares against OUR revenue, and VAT
                // is not ours.
                'net_agorot' => $messagesNet,
                'included' => $included,
                // The cut-off this charge counted to. SiteAgentUsageMeter::settle
                // stamps exactly these messages when the charge succeeds.
                'until' => $until->toIso8601String(),
            ];
        }

        if ($writingsNet > 0) {
            $extras[] = [
                'kind' => 'writings',
                'name' => 'כתיבת תוכן (טקסטים של מעל '.(int) config('siteagent.writing.min_words', 300).' מילים): '
                    .($writingsIncluded > 0
                        ? number_format($writings).' (מתוכם '.number_format($writingsIncluded).' כלולים במנוי) — '.number_format($writingsCharged)
                        : number_format($writings))
                    .' × '.Money::ils((int) $plan->writing_price_agorot).' (עד '.$until->format('d/m/Y').')',
                'qty' => 1,
                'unit_price_agorot' => $this->gross($subscription, $writingsNet),
                'count' => $writings,
                'included' => $writingsIncluded,
            ];
        }

        $first = [
            'kind' => 'plan',
            'name' => $subscription->chargeDescription(Carbon::instance($periodStart), Carbon::instance($periodEnd)),
            'qty' => 1,
            'unit_price_agorot' => $this->gross($subscription, $planNet),
        ];

        if ($arrears) {
            $first += [
                'billing_mode' => SiteAgentArrearsBilling::MODE,
                'usage_from' => $periodStart->toIso8601String(),
                'period_end_at' => $periodEnd->toIso8601String(),
                'usage_max_id' => $maxId,
                'priced_kinds' => array_keys(array_filter([
                    SiteAgentUsage::MESSAGE => (bool) $plan?->billsMessages(),
                    SiteAgentUsage::WRITING => (bool) $plan?->billsWritings(),
                ])),
            ];
        }

        return [
            'amount_agorot' => $net,
            'vat_agorot' => $vat,
            'total_agorot' => $total,
            'lines' => $this->balanceLines([$first, ...$extras], $total),
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

    /** Keep free lines free and every payable line positive while totals agree. */
    private function balanceLines(array $lines, int $total): array
    {
        $remainder = $total - array_sum(array_column($lines, 'unit_price_agorot'));

        foreach ($lines as &$line) {
            if ($remainder === 0) {
                break;
            }

            $amount = $line['unit_price_agorot'];

            if ($amount <= 0) {
                continue;
            }

            // A negative remainder can span several lines; retain at least one
            // agora for each positive service rather than losing it at invoice export.
            $adjustment = $remainder > 0 ? $remainder : max($remainder, 1 - $amount);
            $line['unit_price_agorot'] += $adjustment;
            $remainder -= $adjustment;
        }
        unset($line);

        if ($remainder !== 0) {
            throw new \LogicException('Invoice rounding cannot be allocated to the charged services.');
        }

        return $lines;
    }

    private function gross(Subscription $subscription, int $net): int
    {
        return $net + $this->vat($subscription, $net);
    }
}
