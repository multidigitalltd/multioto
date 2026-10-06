<?php

namespace App\Services\SiteAgent;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Models\SiteAgentUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * מה שמטא גובה מאיתנו על ההודעות, מול מה שגבינו על הן.
 *
 * The product bills messages by the count, at a price somebody typed into the
 * plan screen. Nothing until now compared that price to what the messages
 * actually cost — so the answer to "are we making or losing money on messages?"
 * was a guess, and it changed under us on 1 October 2026, when Meta turned
 * service messages (every reply the bot sends inside the 24-hour window) from
 * free into paid at the utility rate. A product can absorb that silently for
 * months.
 *
 * **The cost side comes from Meta, not from a rate card we maintain.** A rate
 * card is a number that was true when it was typed: Meta changes rates, prices
 * by the recipient's country, and applies volume tiers. Asking Meta what it
 * charged is the only figure that cannot drift, and it is what the operator
 * would otherwise read off an invoice by hand.
 *
 * **The revenue side comes from the charges, not from the plan price.** What we
 * billed is what the invoice says, and the plan's price today is not necessarily
 * the price a charge three weeks ago used.
 *
 * Two honesty rules run through all of it:
 *
 *   1. "Could not find out" is never reported as zero. Meta withholds COST
 *      entirely for accounts on a Solution Partner's credit line, and a token
 *      can expire; ₪0 for messages is the one figure nobody questions, so an
 *      unavailable cost is returned as null with the reason.
 *   2. Meta reports in the WABA's own currency. Where that is not ILS we do NOT
 *      invent an exchange rate to make a margin: the figure is shown in the
 *      currency it arrived in and the margin is withheld, because a made-up
 *      rate turns a reported loss into a reported profit.
 */
class MessagingCostReport
{
    /** Cache key for the figures the scheduled pull leaves behind. */
    public const CACHE_KEY = 'siteagent.messaging_cost';

    /**
     * How long a pulled figure stays usable.
     *
     * Longer than the daily pull on purpose: a day Meta is unreachable should
     * show yesterday's figure, dated, rather than an empty screen.
     */
    private const CACHE_DAYS = 8;

    public function __construct(private WhatsAppCloudClient $whatsapp) {}

    /**
     * Ask Meta what the period cost and remember the answer.
     *
     * Called from the scheduled job, never from a page: this is an outbound HTTP
     * call, and nothing heavy runs inside a request.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function refresh(int $days = 30): array
    {
        $end = Carbon::now();
        $start = $end->copy()->subDays(max(1, $days));

        $data = $this->whatsapp->pricingAnalytics($start, $end);

        if ($data === null) {
            // Kept separately from the figures, so a failed pull leaves the last
            // good numbers on screen beside the reason they are not fresher.
            Cache::put(self::CACHE_KEY.'.error', [
                'reason' => $this->whatsapp->lastError() ?: 'מטא לא החזירה נתוני עלות.',
                'at' => Carbon::now()->toIso8601String(),
            ], now()->addDays(self::CACHE_DAYS));

            return ['ok' => false, 'reason' => $this->whatsapp->lastError()];
        }

        $parsed = $this->parse($data);

        Cache::put(self::CACHE_KEY, [
            'from' => $start->toIso8601String(),
            'to' => $end->toIso8601String(),
            'pulled_at' => Carbon::now()->toIso8601String(),
            'currency' => $parsed['currency'],
            'by_category' => $parsed['by_category'],
            'total' => $parsed['total'],
            'messages' => $parsed['messages'],
        ], now()->addDays(self::CACHE_DAYS));

        Cache::forget(self::CACHE_KEY.'.error');

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Meta's reply, reduced to what the screen asks of it.
     *
     * Meta nests the numbers as data_points under the field, with a currency on
     * the envelope. Every figure is read defensively: this is an external shape
     * that has changed before, and a key that moved must produce "unknown"
     * rather than a confident zero.
     *
     * Amounts arrive as a decimal in the account's currency and are turned into
     * integer agorot/cents once, here — the only place a float touches money.
     *
     * @param  array<string, mixed>  $data
     * @return array{currency: string, by_category: array<string, array{cost: int, messages: int}>, total: int, messages: int}
     */
    private function parse(array $data): array
    {
        $points = (array) ($data['data_points'] ?? []);
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));

        $byCategory = [];
        $total = 0;
        $messages = 0;

        foreach ($points as $point) {
            if (! is_array($point)) {
                continue;
            }

            $category = strtolower(trim((string) ($point['pricing_category'] ?? $point['category'] ?? 'unknown')));
            $category = $category === '' ? 'unknown' : $category;

            // round(), not (int): 0.0061 × 100 lands on 0.60999… in binary, and
            // truncating it loses an agora on every single row.
            $cost = (int) round(((float) ($point['cost'] ?? 0)) * 100);
            $count = (int) ($point['volume'] ?? $point['message_volume'] ?? 0);

            $byCategory[$category]['cost'] = ($byCategory[$category]['cost'] ?? 0) + $cost;
            $byCategory[$category]['messages'] = ($byCategory[$category]['messages'] ?? 0) + $count;

            $total += $cost;
            $messages += $count;
        }

        krsort($byCategory);

        return [
            'currency' => $currency,
            'by_category' => $byCategory,
            'total' => $total,
            'messages' => $messages,
        ];
    }

    /**
     * The whole comparison, for the screen.
     *
     * Reads the cached cost — never calls Meta — and computes the revenue side
     * fresh, because that is our own database and costs one query.
     *
     * @return array{
     *     cost: ?array<string, mixed>, cost_error: ?array<string, mixed>,
     *     revenue_net: int, billed_messages: int, estimated_rows: int,
     *     sent_messages: int, unbilled_messages: int,
     *     margin: ?int, comparable: bool, currency: string, days: int
     * }
     */
    public function summary(int $days = 30): array
    {
        $cost = Cache::get(self::CACHE_KEY);
        $error = Cache::get(self::CACHE_KEY.'.error');

        $since = Carbon::now()->subDays(max(1, $days));
        $revenue = $this->revenue($since);
        $counts = $this->counts($since);

        $currency = is_array($cost) ? (string) ($cost['currency'] ?? '') : '';

        // A margin only where both sides are in the same money. Meta reporting
        // in USD against revenue in shekels is two numbers that must not be
        // subtracted, and an exchange rate we picked would be the one assumption
        // nobody could see on the screen.
        $comparable = is_array($cost) && in_array($currency, ['ILS', 'NIS'], true);

        return [
            'cost' => is_array($cost) ? $cost : null,
            'cost_error' => is_array($error) ? $error : null,
            'revenue_net' => $revenue['net'],
            'billed_messages' => $revenue['messages'],
            'estimated_rows' => $revenue['estimated'],
            'sent_messages' => $counts['sent'],
            'unbilled_messages' => $counts['unbilled'],
            'margin' => $comparable ? $revenue['net'] - (int) $cost['total'] : null,
            'comparable' => $comparable,
            'currency' => $currency,
            'days' => $days,
        ];
    }

    /**
     * What we billed for messages since a date, before VAT.
     *
     * From the charges, because that is what was invoiced. Only charges that
     * SUCCEEDED count: a failed charge is not revenue, and counting it would
     * make a dunning problem look like a margin.
     *
     * `net_agorot` is read off the line where it is present. Lines written
     * before it existed carry only the VAT-inclusive figure, so those are
     * reported separately as estimated rather than silently divided back out.
     *
     * @return array{net: int, messages: int, estimated: int}
     */
    private function revenue(Carbon $since): array
    {
        $net = 0;
        $messages = 0;
        $estimated = 0;

        Charge::query()
            ->where('status', ChargeStatus::Succeeded)
            ->where('created_at', '>=', $since)
            ->whereNotNull('lines')
            ->select(['id', 'lines'])
            ->chunkById(200, function ($charges) use (&$net, &$messages, &$estimated): void {
                foreach ($charges as $charge) {
                    $line = collect($charge->lines ?? [])->firstWhere('kind', 'messages');

                    if ($line === null) {
                        continue;
                    }

                    $messages += (int) ($line['count'] ?? 0);

                    if (isset($line['net_agorot'])) {
                        $net += (int) $line['net_agorot'];

                        continue;
                    }

                    // No net on the line: count it, say so, and leave the figure
                    // out of the total rather than guessing at it.
                    $estimated++;
                }
            });

        return ['net' => $net, 'messages' => $messages, 'estimated' => $estimated];
    }

    /**
     * Messages the bot actually sent, and how many of them nobody will pay for.
     *
     * The gap matters more than either number: a message on a plan that does not
     * price messages, or inside a free trial, costs us exactly as much as one we
     * bill for. A margin that ignores them is a margin that flatters itself.
     *
     * @return array{sent: int, unbilled: int}
     */
    private function counts(Carbon $since): array
    {
        // Two plain counts rather than one aggregate with a CASE: a boolean in
        // raw SQL is 0/1 on SQLite and true/false on Postgres, and the tests run
        // on one while production runs on the other.
        $base = fn () => SiteAgentUsage::query()->where('sent_at', '>=', $since);

        return [
            'sent' => $base()->count(),
            'unbilled' => $base()->where('billable', false)->count(),
        ];
    }

    /**
     * What a message costs us on average, in agorot, over the cached period.
     *
     * The number the plan's "price per message" has to beat. Null when the cost
     * is unavailable or in another currency, or when no messages were counted —
     * dividing by zero to show "₪0.00 per message" would read as free.
     */
    public function costPerMessage(): ?int
    {
        $cost = Cache::get(self::CACHE_KEY);

        if (! is_array($cost) || (int) ($cost['messages'] ?? 0) <= 0) {
            return null;
        }

        if (! in_array(strtoupper((string) ($cost['currency'] ?? '')), ['ILS', 'NIS'], true)) {
            return null;
        }

        return (int) round(((int) $cost['total']) / ((int) $cost['messages']));
    }
}
