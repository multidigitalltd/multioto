<?php

namespace App\Services\SiteAgent;

use App\Enums\ChargeStatus;
use App\Enums\SubscriptionStatus;
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
    /** Cache key prefix for the figures the scheduled pull leaves behind. */
    public const CACHE_KEY = 'siteagent.messaging_cost';

    /**
     * The windows the screen offers, and therefore the windows that are pulled.
     *
     * Cost is cached PER window. Meta is asked about a period, and a single
     * cached figure would mean a 7-day view subtracting a 30-day cost from
     * 7 days of revenue — a margin that is wrong by a factor of four, and wrong
     * in whichever direction nobody checks.
     */
    public const WINDOWS = [7, 30, 90];

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
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;

        /*
         | Truncated to the second, because that is what is transmitted: the Graph
         | call sends a UNIX timestamp, and a cached bound carrying milliseconds
         | would not be the bound Meta actually answered about.
         */
        $end = Carbon::now()->startOfSecond();
        $start = $end->copy()->subDays($days);

        $data = $this->whatsapp->pricingAnalytics($start, $end);

        if ($data === null) {
            return $this->fail($days, $this->whatsapp->lastError() ?: 'מטא לא החזירה נתוני עלות.');
        }

        $analytics = (array) ($data['analytics'] ?? []);

        /*
         | A shape we do not recognise, kept apart from a period with nothing in
         | it. Meta's envelope always carries `data` — a list of series, possibly
         | empty. Its absence means the contract moved under us, and that must be
         | reported rather than cached as zero spend: ₪0 is the figure nobody
         | questions, and "the response shape changed" is the one cause that would
         | otherwise go unnoticed for as long as the screen kept opening.
         */
        if (! array_key_exists('data', $analytics)) {
            return $this->fail($days, 'התשובה ממטא אינה בצורה המצופה (חסר data). ייתכן ששונה חוזה ה-API — אין לקרוא את זה כאפס.');
        }

        $parsed = $this->parse($analytics);

        /*
         | COST withheld rather than refused.
         |
         | Meta does not return COST at all for an account that bills through a
         | Solution Partner's credit line — and it says so by answering normally
         | and leaving the figure out, not by failing the request. Defaulting the
         | missing amount to zero would turn exactly the "we cannot know" case
         | this screen exists to preserve into a confident ₪0, and would clear
         | the recorded reason on its way.
         */
        /*
         | A period with no points at all is a period in which nothing was sent.
         | That is a real answer and it caches as zero — treating it as a withheld
         | cost would file the wrong diagnosis AND keep an older, larger figure on
         | screen for an account that has simply gone quiet.
         |
         | Points that carry volume but no cost are the other thing entirely.
         */
        // A series we could not read at all. Same reasoning as the missing
        // envelope above: an unrecognised shape is reported, never cached as zero.
        if ($parsed['malformed']) {
            return $this->fail($days, 'התשובה ממטא אינה בצורה המצופה (סדרה בלי data_points). ייתכן ששונה חוזה ה-API — אין לקרוא את זה כאפס.');
        }

        if ($parsed['has_points'] && ! $parsed['has_cost']) {
            return $this->fail($days, 'מטא החזירה נתונים אך בלי עלות. כך היא עונה לחשבון שמחויב דרך קו אשראי של שותף — ואז הסכום קיים רק בחיוב של השותף.');
        }

        Cache::put($this->key($days), [
            'from' => $start->toIso8601String(),
            // Where Meta's data actually ends, falling back to what we asked for
            // when it reported no buckets at all — there is nothing shorter to
            // honour then, and a quiet period with local traffic is a real gap
            // worth seeing rather than hiding behind a moved boundary.
            'to' => ($parsed['reported_to'] !== null
                ? Carbon::createFromTimestamp($parsed['reported_to'])
                : $end)->toIso8601String(),
            'requested_to' => $end->toIso8601String(),
            'days' => $days,
            'pulled_at' => Carbon::now()->toIso8601String(),
            // From the account, not from the analytics envelope — which never
            // names a currency, however much the amounts look like shekels.
            'currency' => strtoupper(trim((string) ($data['currency'] ?? ''))),
            'by_category' => $parsed['by_category'],
            'total' => $parsed['total'],
            'messages' => $parsed['messages'],
        ], now()->addDays(self::CACHE_DAYS));

        Cache::forget($this->key($days).'.error');

        return ['ok' => true, 'reason' => null];
    }

    /** Cache key for one window. */
    private function key(int $days): string
    {
        return self::CACHE_KEY.'.'.$days;
    }

    /**
     * Record why a window has no fresh figure, and leave the old one standing.
     *
     * The reason is cached beside the figures rather than instead of them: a day
     * Meta is unreachable should show the last good number with its date and the
     * reason it is not newer, not an empty screen.
     *
     * @return array{ok: bool, reason: ?string}
     */
    private function fail(int $days, string $reason): array
    {
        Cache::put($this->key($days).'.error', [
            'reason' => $reason,
            'at' => Carbon::now()->toIso8601String(),
        ], now()->addDays(self::CACHE_DAYS));

        return ['ok' => false, 'reason' => $reason];
    }

    /**
     * Meta's reply, reduced to what the screen asks of it.
     *
     * The nesting is pricing_analytics.data[].data_points[] — a list of series,
     * each with its own points — and NOT data_points on the envelope. Reading it
     * one level too high returns nothing at all, which is indistinguishable on
     * screen from a month in which no messages were sent.
     *
     * Every figure is read defensively: this is an external shape that has
     * changed before, and a key that moved must produce "unknown" rather than a
     * confident zero. `has_cost` carries that distinction out — it is false when
     * no point carried a cost at all, which is how Meta answers an account whose
     * spend it will not disclose.
     *
     * Amounts arrive as a decimal in the account's currency and are turned into
     * integer agorot/cents once, here — the only place a float touches money.
     *
     * @param  array<string, mixed>  $analytics
     *                                           `has_points` keeps "nothing was sent" apart from "the cost was withheld".
     *                                           Both arrive as a response with no cost in it, and they mean opposite things:
     *                                           one is a quiet month worth caching as zero, the other is an account whose
     *                                           spend Meta will not disclose.
     * @return array{by_category: array<string, array{cost: int, messages: int}>, total: int, messages: int, has_cost: bool, has_points: bool, malformed: bool, reported_to: ?int}
     */
    private function parse(array $analytics): array
    {
        $byCategory = [];
        $total = 0;
        $messages = 0;
        $hasCost = false;
        $hasPoints = false;
        $malformed = false;
        $reportedTo = null;

        foreach ((array) ($analytics['data'] ?? []) as $series) {
            if (! is_array($series)) {
                $malformed = true;

                continue;
            }

            /*
             | A series is contractually required to carry `data_points`. Letting
             | its absence fall through to an empty list would read as "nothing
             | was sent" — and would then overwrite a real figure with zero and
             | clear the error on its way, which is the same silent ₪0 by a
             | narrower road.
             */
            if (! is_array($series['data_points'] ?? null)) {
                $malformed = true;

                continue;
            }

            foreach ($series['data_points'] as $point) {
                if (! is_array($point)) {
                    continue;
                }

                $hasPoints = true;

                $category = strtolower(trim((string) ($point['pricing_category'] ?? 'unknown')));
                $category = $category === '' ? 'unknown' : $category;

                // Whether the key is THERE, asked before its value is read: a
                // withheld cost and a genuine zero are the same 0 once cast.
                $costGiven = array_key_exists('cost', $point) && $point['cost'] !== null;
                $hasCost = $hasCost || $costGiven;

                /*
                 | Accumulated in HUNDREDTHS of an agora, and rounded to agorot
                 | only once the whole category is summed.
                 |
                 | DAILY granularity times category means many points, each a
                 | decimal. Rounding every one of them to a whole agora first
                 | throws away the fraction each time: two points of ILS 0.004
                 | become 0 + 0 = 0, where the sum is 0.008 and rounds to 1. The
                 | residues do not cancel, they are simply lost — understating the
                 | cost and flattering the margin.
                 |
                 | Integers throughout, per the money rule: the decimal from Meta
                 | is scaled once on the way in, and nothing downstream is a float.
                 */
                /*
                 | The far edge of the newest bucket Meta actually reported.
                 |
                 | Its analytics populate with a delay, and a request for "up to
                 | now" comes back successful but short: the last hours have no
                 | bucket yet. Comparing local revenue up to now against that is
                 | revenue with its cost missing — an overstated margin, worst in
                 | the 7-day view. So the window closes where Meta's data ends,
                 | not where we asked it to.
                 */
                $pointEnd = (int) ($point['end'] ?? 0);

                if ($pointEnd > 0 && ($reportedTo === null || $pointEnd > $reportedTo)) {
                    $reportedTo = $pointEnd;
                }

                $scaled = $costGiven ? (int) round(((float) $point['cost']) * 10000) : 0;
                $count = (int) ($point['volume'] ?? 0);

                $byCategory[$category]['scaled'] = ($byCategory[$category]['scaled'] ?? 0) + $scaled;
                $byCategory[$category]['messages'] = ($byCategory[$category]['messages'] ?? 0) + $count;

                $messages += $count;
            }
        }

        krsort($byCategory);

        /*
         | Each category rounded once, and the overall total taken as the SUM of
         | those rounded figures rather than rounded separately. Rounding the two
         | independently lets the table disagree with its own total by an agora,
         | and a report that does not add up is worse than one less precise.
         */
        foreach ($byCategory as $key => $row) {
            $byCategory[$key]['cost'] = (int) round(((int) $row['scaled']) / 100);
            unset($byCategory[$key]['scaled']);

            $total += $byCategory[$key]['cost'];
        }

        return [
            'by_category' => $byCategory,
            'total' => $total,
            'messages' => $messages,
            'has_cost' => $hasCost,
            'has_points' => $hasPoints,
            'malformed' => $malformed,
            'reported_to' => $reportedTo,
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
     *     revenue_net: int, billed_messages: int, included_messages: int,
     *     charged_messages: int, break_even_agorot: ?int,
     *     unbilled_cost_agorot: ?int, included_cost_agorot: ?int, estimated_rows: int,
     *     sent_messages: int, unbilled_messages: int,
     *     margin: ?int, comparable: bool, currency: string, days: int
     * }
     */
    public function summary(int $days = 30): array
    {
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;

        // The figure for THIS window. One shared entry would show a 30-day cost
        // under a 7-day revenue, and a refresh for one window would quietly
        // change the period every other screen was reading.
        $cost = Cache::get($this->key($days));
        $error = Cache::get($this->key($days).'.error');

        /*
         | Both sides over the SAME interval, which is the cost's own.
         |
         | Meta's figure ends when the pull ran (04:40), not now. Recomputing the
         | local side "to now" would compare an evening's revenue against a cost
         | that stopped before breakfast — hours of revenue whose cost is missing,
         | and a slice at the start dropped instead. So where a cached figure
         | exists its stored bounds lead, and only without one does the window
         | fall back to the trailing period from this moment.
         */
        $from = is_array($cost) && isset($cost['from'])
            ? Carbon::parse($cost['from'])
            : Carbon::now()->subDays($days);

        $to = is_array($cost) && isset($cost['to'])
            ? Carbon::parse($cost['to'])
            : Carbon::now();

        $revenue = $this->revenue($from, $to);
        $counts = $this->counts($from, $to);

        $currency = is_array($cost) ? (string) ($cost['currency'] ?? '') : '';

        // A margin only where both sides are in the same money. Meta reporting
        // in USD against revenue in shekels is two numbers that must not be
        // subtracted, and an exchange rate we picked would be the one assumption
        // nobody could see on the screen.
        $comparable = is_array($cost) && in_array($currency, ['ILS', 'NIS'], true);

        /*
         | Messages in this window that carry a per-message price.
         |
         | Settled ones outside the plan's allowance, PLUS the ones sent and not
         | yet invoiced. The pending ones belong here because Meta's cost — the
         | numerator — already includes them: leaving them out divides the whole
         | window's spend by a fraction of the messages that produced it, and a
         | window of nothing but pending replies would report no break-even at all
         | while the screen says they will be billed at the next renewal.
         |
         | How many of the pending ones will land inside a plan's remaining
         | allowance is not knowable until that renewal computes it, so this can
         | be a little optimistic. The screen says so rather than implying a
         | precision the figure does not have.
         */
        $charged = max(0, $revenue['messages'] - $revenue['included']) + $revenue['pending'];

        return [
            'cost' => is_array($cost) ? $cost : null,
            'cost_error' => is_array($error) ? $error : null,
            'revenue_net' => $revenue['net'],
            'billed_messages' => $revenue['messages'],
            'included_messages' => $revenue['included'],
            'estimated_rows' => $revenue['estimated'],
            'sent_messages' => $counts['sent'],
            'unbilled_messages' => $counts['unbilled'],
            'pending_messages' => $revenue['pending'],
            'margin' => $comparable ? $revenue['net'] - (int) $cost['total'] : null,
            /*
             | What one CHARGED message has to earn to cover the product's whole
             | messaging spend — the figure the plan's "price per message" is set
             | against.
             |
             | The denominator is deliberately not every message Meta billed us
             | for. Verification codes and system notices are charged to us and
             | are never charged on (SiteAgentUsageMeter excludes them), so
             | dividing by all of them spreads the cost over messages that cannot
             | recover it: 100 billable replies beside 900 verification codes
             | would report a tenth of the true break-even, under a label saying
             | the plan price must beat it.
             |
             | Included-in-plan messages are out of the denominator for the same
             | reason: they earn nothing per message.
             */
            'break_even_agorot' => $comparable && $charged > 0
                ? (int) ceil(((int) $cost['total']) / $charged)
                : null,
            'charged_messages' => $charged,
            /*
             | What the messages nobody pays for actually cost, allocated at full
             | precision and rounded once.
             |
             | Multiplying a rounded per-message rate instead gets this badly
             | wrong on small numbers: 1,281 agorot over 2,000 messages is 0.64 of
             | an agora each, which rounds to 1 and then reports ₪20 for the same
             | 2,000 messages instead of ₪12.81. An average below half an agora
             | rounds to nothing and reports the cost as free.
             */
            'unbilled_cost_agorot' => $this->allocate($cost, $counts['unbilled'], $comparable),
            'included_cost_agorot' => $this->allocate($cost, $revenue['included'], $comparable),
            'comparable' => $comparable,
            'currency' => $currency,
            'days' => $days,
            // The interval both sides were measured over, so the screen can name
            // it rather than implying "the last N days from right now".
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
        ];
    }

    /**
     * What we billed for the messages SENT in this window, before VAT.
     *
     * Keyed on when the messages went out, not on when the invoice was raised,
     * because messages are billed in arrears and the two periods do not line up.
     * A renewal raised this morning can carry a whole month of messages: filter
     * charges by their own date and a 7-day view shows seven days of Meta's cost
     * against a month of revenue, while the messages actually sent in those
     * seven days sit unbilled and uncounted. The margin can come out the wrong
     * sign while each query is individually correct.
     *
     * So the ledger leads. Every usage row already knows when it was sent and
     * which charge settled it; the charge supplies the rate, and only a charge
     * that SUCCEEDED counts — a failed one is not revenue, and counting it would
     * make a dunning problem look like a margin.
     *
     * The rate is the charge's own average over its messages
     * (`net_agorot / count`), which spreads a bundled allowance evenly across
     * them. Which individual messages fell inside the allowance is not recorded
     * anywhere, so an even spread is the honest answer rather than a guess that
     * looks precise; across a whole charge it reconciles exactly.
     *
     * Bounded at both ends, not just the start: the cost it is compared against
     * stopped when the pull ran, and revenue past that point has no cost beside
     * it.
     *
     * @return array{net: int, messages: int, included: int, estimated: int, pending: int}
     */
    private function revenue(Carbon $from, Carbon $to): array
    {
        /*
         | Messages only.
         |
         | The same ledger also holds writing units, told apart by `kind`, and a
         | renewal settles both against the same charge. Counting them here would
         | attribute writing revenue to messages, inflate the window's share of a
         | charge, and report writing units as pending or unbilled messages — all
         | inside a screen whose only subject is what Meta charges for messages.
         */
        $settled = SiteAgentUsage::query()
            ->where('kind', SiteAgentUsage::MESSAGE)
            ->where('sent_at', '>=', $from)
            ->where('sent_at', '<', $to)
            ->whereNotNull('charge_id')
            ->groupBy('charge_id')
            ->selectRaw('charge_id, COUNT(*) as in_window')
            ->pluck('in_window', 'charge_id');

        // Sent, billable, and not yet on any invoice. Revenue that is owed but
        // not yet earned — shown separately so it is neither claimed nor lost.
        /*
         | Pending, and still collectable.
         |
         | Subscription::cancel() clears next_charge_at without settling the usage
         | behind it, so those rows stay billable with no charge for good. Counting
         | them as revenue-bearing understates the break-even rate AND backs the
         | screen's claim that they will be billed at the next renewal — for a
         | subscription that will never renew. A row whose subscription is gone or
         | cancelled is counted as sent, never as owed.
         */
        $pending = SiteAgentUsage::query()
            ->where('kind', SiteAgentUsage::MESSAGE)
            ->where('sent_at', '>=', $from)
            ->where('sent_at', '<', $to)
            ->whereNull('charge_id')
            ->where('billable', true)
            ->whereHas('subscription', fn ($query) => $query
                ->whereNot('status', SubscriptionStatus::Canceled)
                ->whereNotNull('next_charge_at'))
            ->count();

        if ($settled->isEmpty()) {
            return ['net' => 0, 'messages' => 0, 'included' => 0, 'estimated' => 0, 'pending' => $pending];
        }

        $net = 0;
        $messages = 0;
        $included = 0;
        $estimated = 0;

        Charge::query()
            ->whereIn('id', $settled->keys()->all())
            ->where('status', ChargeStatus::Succeeded)
            ->select(['id', 'lines'])
            ->chunkById(200, function ($charges) use ($settled, &$net, &$messages, &$included, &$estimated): void {
                foreach ($charges as $charge) {
                    $inWindow = (int) $settled->get($charge->id, 0);
                    $messages += $inWindow;

                    $line = collect($charge->lines ?? [])->firstWhere('kind', 'messages');

                    // No messages line at all: every message on this charge was
                    // inside the plan's allowance. They earned nothing, and that
                    // is the honest figure — not a gap.
                    if ($line === null) {
                        $included += $inWindow;

                        continue;
                    }

                    $onCharge = max(1, (int) ($line['count'] ?? 0));
                    $share = min(1.0, $inWindow / $onCharge);

                    $included += (int) round(((int) ($line['included'] ?? 0)) * $share);

                    if (isset($line['net_agorot'])) {
                        $net += (int) round(((int) $line['net_agorot']) * $share);

                        continue;
                    }

                    // No net on the line: count it, say so, and leave the figure
                    // out of the total rather than guessing at it.
                    $estimated++;
                }
            });

        return ['net' => $net, 'messages' => $messages, 'included' => $included, 'estimated' => $estimated, 'pending' => $pending];
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
    private function counts(Carbon $from, Carbon $to): array
    {
        // Two plain counts rather than one aggregate with a CASE: a boolean in
        // raw SQL is 0/1 on SQLite and true/false on Postgres, and the tests run
        // on one while production runs on the other.
        // Half-open, like the analytics bucket it is compared against: Meta's
        // `end` is the next bucket's `start`, so a row landing exactly on it has
        // no cost on our side of the comparison.
        $base = fn () => SiteAgentUsage::query()
            ->where('kind', SiteAgentUsage::MESSAGE)
            ->where('sent_at', '>=', $from)
            ->where('sent_at', '<', $to);

        return [
            'sent' => $base()->count(),
            'unbilled' => $base()->where('billable', false)->count(),
        ];
    }

    /**
     * The share of the period's cost belonging to a number of messages.
     *
     * count × total ÷ messages, rounded once at the end. Null where there is no
     * comparable cost to divide, or no messages to divide by — a zero here would
     * read as "these were free".
     *
     * @param  array<string, mixed>|null  $cost
     */
    private function allocate(?array $cost, int $count, bool $comparable): ?int
    {
        $messages = is_array($cost) ? (int) ($cost['messages'] ?? 0) : 0;

        if (! $comparable || $messages <= 0 || $count <= 0) {
            return null;
        }

        return (int) round($count * ((int) $cost['total']) / $messages);
    }

    /**
     * What one message costs us on average, over every message Meta billed.
     *
     * NOT the break-even price — that is `break_even_agorot` on the summary,
     * which divides by the messages that actually earn. This is the blended cost
     * of a message of any kind, and its use is pricing the messages nobody pays
     * for: a trial reply and a verification code cost this much each.
     *
     * Null when the cost is unavailable or in another currency, or when no
     * messages were counted — dividing by zero to show "₪0.00 per message" would
     * read as free. Per window, like the cost it divides.
     */
    public function costPerMessage(int $days = 30): ?int
    {
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;
        $cost = Cache::get($this->key($days));

        if (! is_array($cost) || (int) ($cost['messages'] ?? 0) <= 0) {
            return null;
        }

        if (! in_array(strtoupper((string) ($cost['currency'] ?? '')), ['ILS', 'NIS'], true)) {
            return null;
        }

        return (int) round(((int) $cost['total']) / ((int) $cost['messages']));
    }
}
