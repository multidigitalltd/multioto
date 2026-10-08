<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Models\Charge;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/** Personal monthly cycles, collected only after the service was supplied. */
class SiteAgentArrearsBilling
{
    public const MODE = 'arrears';

    private const TIMEZONE = 'Asia/Jerusalem';

    public static function applies(Subscription $subscription): bool
    {
        // Enrollment checks the product flag. Once enrolled, that financial
        // agreement cannot turn into advance billing because someone edits a plan.
        return $subscription->billing_mode === self::MODE;
    }

    /** @return array<string, mixed> */
    public static function initializeDates(CarbonInterface $paidStart): array
    {
        $start = CarbonImmutable::instance($paidStart)->setTimezone(self::TIMEZONE)->startOfSecond();
        $end = self::firstChargeAt($start);

        return [
            'billing_mode' => self::MODE,
            'billing_anchor_at' => $start,
            'billing_period_start_at' => $start,
            'current_period_start' => $start->toDateString(),
            'current_period_end' => $end->toDateString(),
            'next_charge_at' => $end,
        ];
    }

    public static function firstChargeAt(CarbonInterface $anchor): CarbonImmutable
    {
        return CarbonImmutable::instance($anchor)->setTimezone(self::TIMEZONE)->startOfSecond()->addMonthNoOverflow();
    }

    /** The oldest service period still awaiting payment; end is exclusive. */
    public static function period(Subscription $subscription): array
    {
        $anchor = self::anchor($subscription);
        $start = $subscription->billing_period_start_at;

        if (! $start instanceof CarbonInterface) {
            throw new LogicException('An arrears subscription needs a billing period cursor.');
        }

        $start = CarbonImmutable::instance($start)->setTimezone(self::TIMEZONE)->startOfSecond();
        $end = self::nextAnniversary($anchor, $start);
        $prepaid = $subscription->billing_prepaid_until;

        // A migrated prepaid year remains one already-funded legacy period.
        // Its existing usage allowance is applied once at its original end.
        if ($prepaid instanceof CarbonInterface && $prepaid->gt($start)) {
            $end = CarbonImmutable::instance($prepaid)->setTimezone(self::TIMEZONE)->startOfSecond();
        }

        if ($start->lt($anchor) || $end->lte($start)) {
            throw new LogicException('The arrears billing cursor is outside its service interval.');
        }

        return ['start' => $start, 'end' => $end];
    }

    /** The personal usage window containing a moment, independent of overdue invoices. */
    public static function windowAt(Subscription $subscription, CarbonInterface $at): array
    {
        $anchor = self::anchor($subscription);
        $at = CarbonImmutable::instance($at)->setTimezone(self::TIMEZONE);
        $prepaid = $subscription->billing_prepaid_until;

        if ($prepaid instanceof CarbonInterface && $at->lt($prepaid)) {
            return [
                'start' => CarbonImmutable::instance($subscription->billing_period_start_at ?? $anchor)->setTimezone(self::TIMEZONE),
                'end' => CarbonImmutable::instance($prepaid)->setTimezone(self::TIMEZONE),
            ];
        }

        $index = max(0, self::monthIndex($anchor, $at));
        $start = $anchor->addMonthsNoOverflow($index);

        if ($start->gt($at) && $index > 0) {
            $start = $anchor->addMonthsNoOverflow(--$index);
        }

        if ($prepaid instanceof CarbonInterface && $prepaid->gt($start) && $prepaid->lte($at)) {
            $start = CarbonImmutable::instance($prepaid)->setTimezone(self::TIMEZONE);
        }

        return ['start' => $start, 'end' => $anchor->addMonthsNoOverflow($index + 1)];
    }

    /** @return array<string, mixed> */
    public static function afterPayment(Subscription $subscription, Charge $charge): array
    {
        $anchor = self::anchor($subscription);
        $metadata = self::metadata($charge);
        $end = isset($metadata['period_end_at'])
            ? CarbonImmutable::parse($metadata['period_end_at'])->setTimezone(self::TIMEZONE)
            : self::period($subscription)['end'];
        $next = self::nextAnniversary($anchor, $end);
        $stop = $subscription->billing_stop_at;
        $finished = $stop instanceof CarbonInterface && $end->gte($stop);

        return [
            'billing_period_start_at' => $end,
            'current_period_start' => $end->toDateString(),
            'current_period_end' => $next->toDateString(),
            'next_charge_at' => $finished ? null : $next,
        ];
    }

    /** Frozen accounting metadata travels with the invoice breakdown on every retry. */
    public static function metadata(Charge $charge): array
    {
        foreach ($charge->lines ?? [] as $line) {
            if (($line['billing_mode'] ?? null) === self::MODE) {
                return $line;
            }
        }

        return [];
    }

    /** The service and extra seats delivered in this period, excluding prepaid time. */
    public static function baseAmounts(Subscription $subscription, CarbonInterface $start, CarbonInterface $end): array
    {
        $anchor = self::anchor($subscription);
        $index = self::monthIndex($anchor, $start);
        $cycleStart = $anchor->addMonthsNoOverflow($index);

        if ($cycleStart->gt($start)) {
            $cycleStart = $anchor->addMonthsNoOverflow(--$index);
        }

        $cycleEnd = $anchor->addMonthsNoOverflow($index + 1);
        $plan = $subscription->basePriceAgorot() - $subscription->extraNumbersAgorot();
        $extras = $subscription->extraNumbersAgorot();

        if ($subscription->billingInterval() === BillingInterval::Yearly) {
            // Distribute indivisible agorot across twelve personal months so
            // the annual total is unchanged, rather than rounding it up twelve times.
            $plan = self::annualShare($plan, $index);
            $extras = self::annualShare($extras, $index);
        }

        $prepaid = $subscription->billing_prepaid_until;
        $billableStart = $prepaid instanceof CarbonInterface && $prepaid->gt($start) ? $prepaid : $start;
        $serviceEnd = self::serviceEnd($subscription, $end);
        $seconds = max(0, $serviceEnd->getTimestamp() - $billableStart->getTimestamp());
        $cycleSeconds = $cycleEnd->getTimestamp() - $cycleStart->getTimestamp();
        $seconds = min($seconds, $cycleSeconds);
        // Divide the amount first to avoid amount × seconds overflowing;
        // the remainder product is bounded by the square of one month's seconds.
        $prorate = fn (int $amount): int => intdiv($amount, $cycleSeconds) * $seconds
            + intdiv(($amount % $cycleSeconds) * $seconds + intdiv($cycleSeconds, 2), $cycleSeconds);
        $plan = $prorate($plan);
        $extras = $prorate($extras);

        return ['plan' => $plan, 'extras' => $extras];
    }

    public static function annualShare(int $amount, int $cycleIndex): int
    {
        return intdiv($amount * ($cycleIndex + 1), 12) - intdiv($amount * $cycleIndex, 12);
    }

    public static function serviceEnd(Subscription $subscription, CarbonInterface $periodEnd): CarbonImmutable
    {
        $stop = $subscription->billing_stop_at;

        return CarbonImmutable::instance($stop instanceof CarbonInterface && $stop->lt($periodEnd) ? $stop : $periodEnd);
    }

    private static function anchor(Subscription $subscription): CarbonImmutable
    {
        if (! $subscription->billing_anchor_at instanceof CarbonInterface) {
            throw new LogicException('An arrears subscription needs its original billing anniversary.');
        }

        return CarbonImmutable::instance($subscription->billing_anchor_at)->setTimezone(self::TIMEZONE)->startOfSecond();
    }

    private static function monthIndex(CarbonInterface $anchor, CarbonInterface $date): int
    {
        return (($date->year - $anchor->year) * 12) + $date->month - $anchor->month;
    }

    private static function nextAnniversary(CarbonImmutable $anchor, CarbonInterface $after): CarbonImmutable
    {
        $index = max(0, self::monthIndex($anchor, $after));
        $next = $anchor->addMonthsNoOverflow($index);

        return $next->gt($after) ? $next : $anchor->addMonthsNoOverflow($index + 1);
    }
}
