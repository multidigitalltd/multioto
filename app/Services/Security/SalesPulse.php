<?php

namespace App\Services\Security;

/**
 * Reads a store's sales pulse (the `wc_order_stats_get` payload) and decides
 * whether the shop has silently stopped selling.
 *
 * Two failures that uptime monitoring can never see:
 *  - "checkout dead": the site answers 200, but no order has been CREATED for a
 *    full day on a shop that normally receives several a day.
 *  - "gateway dead": orders are still being created, but not one of them was
 *    paid — the classic broken payment-provider symptom.
 *
 * The baseline is the store's OWN recent history (a median, so one exceptional
 * day cannot drag it), never a fixed number — a shop with two orders a week and
 * one with fifty a day must both be judged fairly.
 */
class SalesPulse
{
    /**
     * @param  array<string, mixed>  $stats  decoded wc_order_stats_get payload
     * @return array{kind: ?string, orders: int, paid: int, baseline_orders: float, baseline_paid: float, days: int}
     */
    public function evaluate(array $stats): array
    {
        $daily = (array) ($stats['daily'] ?? []);
        $last24h = (array) ($stats['last_24h'] ?? []);

        $orders = (int) ($last24h['orders'] ?? 0);
        $paid = (int) ($last24h['paid'] ?? 0);

        // Complete days only: today is still filling up, so it would drag the
        // median down and mask a real outage.
        $complete = array_slice($daily, 0, max(0, count($daily) - 1), true);

        $baselineOrders = $this->median(array_map(fn ($d): float => (float) ($d['orders'] ?? 0), array_values($complete)));
        $baselinePaid = $this->median(array_map(fn ($d): float => (float) ($d['paid'] ?? 0), array_values($complete)));

        $minOrders = (float) config('billing.monitoring.store_pulse.min_baseline_orders', 2);

        $kind = null;

        if ($orders === 0 && $baselineOrders >= $minOrders) {
            // Nothing even reached the order stage — checkout or the shop itself.
            $kind = 'store_silent';
        } elseif ($paid === 0 && $orders > 0 && $baselinePaid >= $minOrders) {
            // Orders arrive but none can pay — a payment gateway failure.
            $kind = 'store_payments';
        }

        return [
            'kind' => $kind,
            'orders' => $orders,
            'paid' => $paid,
            'baseline_orders' => round($baselineOrders, 1),
            'baseline_paid' => round($baselinePaid, 1),
            'days' => count($complete),
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
