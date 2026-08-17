<?php

namespace App\Services\Licensing;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Models\License;
use App\Models\LicenseSite;
use App\Models\PluginOrder;
use App\Models\PluginProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the plugin business is actually doing.
 *
 * Selling licences produced rows and no answers: how many are live, how much
 * came in, and — the only one that costs money to not know — how many renewals
 * were attempted and did not collect.
 *
 * Two rules run through everything here:
 *
 *  · **A rate needs a denominator that does not move.** "Renewal rate" measured
 *    from licence expiry dates is always 100%: a renewed licence has its expiry
 *    pushed forward and leaves the window it was counted in. So renewals are
 *    measured on CHARGES, which are written once for a success and once for a
 *    failure and never move afterwards.
 *
 *  · **Nothing measured and nothing happened are different answers.** Every
 *    figure that can be empty for two different reasons reports null rather
 *    than zero, and the screen says which it is.
 */
class LicenseMetrics
{
    /** How far back the money and renewal figures look. */
    public const WINDOW_DAYS = 90;

    /** A shop that has not checked in for this long is not running the plugin. */
    public const SITE_STALE_DAYS = 30;

    /** Expiries this close need a human when nothing renews them automatically. */
    public const SOON_DAYS = 30;

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $total = License::query()->count();

        $revoked = License::query()->where('status', License::REVOKED)->count();
        $expired = License::query()
            ->where('status', '!=', License::REVOKED)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', today())
            ->count();

        return [
            'total' => $total,
            'active' => $total - $revoked - $expired,
            'expired' => $expired,
            'revoked' => $revoked,
            'perpetual' => License::query()
                ->where('status', '!=', License::REVOKED)
                ->where('includes_updates', false)
                ->count(),
            'sites' => LicenseSite::query()->count(),
            // The difference between these two is the honest answer to "how many
            // shops actually run this": a row created a year ago by an install
            // that has since been deleted looks identical to a live one.
            'sitesLive' => LicenseSite::query()
                ->where('last_seen_at', '>=', now()->subDays(self::SITE_STALE_DAYS))
                ->count(),
            'productsSellable' => PluginProduct::query()
                ->whereHas('plans', fn (Builder $q) => $q->where('is_active', true))
                ->count(),
        ];
    }

    /**
     * Money collected from plugins in the window, and in the one before it.
     *
     * The previous window is here so a number has a direction. A standalone
     * "₪4,200 this quarter" cannot be acted on; the same figure against ₪7,900
     * can.
     *
     * @return array{days: int, agorot: int, count: int, previousAgorot: int}
     */
    public function revenue(): array
    {
        $now = now();
        $start = $now->copy()->subDays(self::WINDOW_DAYS);
        $previousStart = $start->copy()->subDays(self::WINDOW_DAYS);

        $window = $this->pluginCharges()
            ->where('status', ChargeStatus::Succeeded)
            ->whereRaw('COALESCE(charges.charged_at, charges.created_at) >= ?', [$start])
            ->get(['total_agorot']);

        return [
            'days' => self::WINDOW_DAYS,
            'agorot' => (int) $window->sum('total_agorot'),
            'count' => $window->count(),
            'previousAgorot' => (int) $this->pluginCharges()
                ->where('status', ChargeStatus::Succeeded)
                ->whereRaw('COALESCE(charges.charged_at, charges.created_at) >= ?', [$previousStart])
                ->whereRaw('COALESCE(charges.charged_at, charges.created_at) < ?', [$start])
                ->sum('total_agorot'),
        ];
    }

    /**
     * Renewal collection in the window, measured on BILLED PERIODS rather than
     * on licences or on individual charges.
     *
     * Three things are deliberately not counted, and each of them would move the
     * number in a flattering direction if it were:
     *
     *  · **Expiry dates**, for the reason in the class note — they move.
     *
     *  · **Retries.** A period that failed three times and then collected is one
     *    renewal that succeeded, not four renewals at a 25% rate with triple the
     *    money lost. Every attempt for one `(subscription, period_start)` folds
     *    into a single outcome, and a period that ever succeeded counts as
     *    collected however many attempts it took.
     *
     *  · **The first collection.** A licence subscription is created with its
     *    first charge due immediately, so that charge is the purchase — the
     *    moment somebody decided to buy, not the moment they decided to stay.
     *    Counting it would report a renewal rate that rises with new sales.
     *
     * `rate` is null when no renewal came due. A renewal rate of 0% and no
     * renewals due are opposite situations and must not print the same.
     *
     * @return array{attempted: int, succeeded: int, failed: int, open: int, rate: ?float, lostAgorot: int}
     */
    public function renewals(): array
    {
        $empty = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'open' => 0, 'rate' => null, 'lostAgorot' => 0];

        $subscriptionIds = License::query()->whereNotNull('subscription_id')
            ->distinct()->pluck('subscription_id');

        if ($subscriptionIds->isEmpty()) {
            return $empty;
        }

        // The purchase period, per subscription — over all time, because a
        // subscription sold two years ago has its first period far outside the
        // window and must still be recognised as the purchase.
        $purchasePeriod = Charge::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->selectRaw('subscription_id, MIN(period_start) as first_period')
            ->groupBy('subscription_id')
            ->pluck('first_period', 'subscription_id')
            // Normalised to a plain date: the drivers disagree on whether a date
            // column comes back with a time on it, and comparing the two shapes
            // as strings would silently never match — which would look exactly
            // like the exclusion being off.
            ->map(fn (string $period): string => Carbon::parse($period)->toDateString());

        /*
         * Attempts are read from slightly before the window: a period whose
         * first attempt landed just before it may have collected on a retry
         * inside it, and judging that period on the retry alone would call a
         * collected renewal a failure.
         */
        $attempts = Charge::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS + 60))
            ->get(['subscription_id', 'period_start', 'status', 'total_agorot', 'created_at']);

        $start = now()->subDays(self::WINDOW_DAYS);
        $key = fn (Charge $charge): string => $charge->subscription_id.'|'.$charge->period_start->toDateString();

        $periods = $attempts
            ->groupBy($key)
            // Only periods this window is actually about, and never the purchase.
            ->filter(fn (Collection $group): bool => $group->contains(fn (Charge $c): bool => $c->created_at >= $start)
                && $group->first()->period_start->toDateString()
                    !== (string) $purchasePeriod->get($group->first()->subscription_id));

        $succeeded = $periods->filter(fn (Collection $g): bool => $g->contains('status', ChargeStatus::Succeeded));
        $open = $periods->reject(fn (Collection $g): bool => $g->contains('status', ChargeStatus::Succeeded))
            ->filter(fn (Collection $g): bool => $g->contains('status', ChargeStatus::Pending));
        $failed = $periods
            ->reject(fn (Collection $g): bool => $g->contains('status', ChargeStatus::Succeeded)
                || $g->contains('status', ChargeStatus::Pending))
            ->filter(fn (Collection $g): bool => $g->contains('status', ChargeStatus::Failed));

        $settled = $succeeded->count() + $failed->count();

        return [
            'attempted' => $periods->count(),
            'succeeded' => $succeeded->count(),
            'failed' => $failed->count(),
            // Still in flight — apart, so they neither flatter the rate nor
            // count against it.
            'open' => $open->count(),
            'rate' => $settled > 0 ? round($succeeded->count() / $settled * 100) : null,
            // Once per period, not once per attempt: three failed tries at the
            // same renewal are one renewal's worth of money not collected.
            'lostAgorot' => (int) $failed->sum(fn (Collection $g): int => (int) $g->max('total_agorot')),
        ];
    }

    /**
     * Licences whose updates ran out and were not renewed — the actual churn
     * list, with the reason beside each one.
     *
     * A one-off purchase reaching its end is not churn; a renewing subscription
     * that dunning gave up on is. Both land here, and the screen separates them,
     * because a list that mixes them is a list nobody works through.
     *
     * @return Collection<int, License>
     */
    public function lapsed(): Collection
    {
        return License::query()
            ->with(['customer:id,name,email', 'product:id,name', 'subscription:id,status'])
            ->where('status', '!=', License::REVOKED)
            ->where('includes_updates', true)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', today())
            ->whereDate('expires_at', '>=', today()->subDays(self::WINDOW_DAYS))
            ->orderByDesc('expires_at')
            ->limit(100)
            ->get();
    }

    /**
     * Expiring soon with nothing set up to renew them: the only list here that
     * is worth acting on BEFORE it becomes a problem.
     *
     * @return Collection<int, License>
     */
    public function expiringSoon(): Collection
    {
        return License::query()
            ->with(['customer:id,name,email', 'product:id,name'])
            ->where('status', '!=', License::REVOKED)
            ->whereNull('subscription_id')
            ->where('includes_updates', true)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', today())
            ->whereDate('expires_at', '<=', today()->addDays(self::SOON_DAYS))
            ->orderBy('expires_at')
            ->limit(100)
            ->get();
    }

    /**
     * Orders that took money and produced no licence.
     *
     * This should always be empty, and that is exactly why it is on the screen:
     * a fulfilment that silently failed is invisible until the buyer writes in,
     * and by then they have paid and received nothing.
     *
     * @return Collection<int, PluginOrder>
     */
    public function paidButUnfulfilled(): Collection
    {
        return PluginOrder::query()
            ->with(['product:id,name', 'charge:id,status,total_agorot'])
            ->whereNull('license_id')
            ->whereIn('charge_id', Charge::query()->where('status', ChargeStatus::Succeeded)->select('id'))
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Per product: what is live and what it brought in. Sorted by money, since
     * that is the question the table is opened to answer.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byProduct(): Collection
    {
        $start = now()->subDays(self::WINDOW_DAYS);

        return PluginProduct::query()
            ->with(['licenses' => fn ($q) => $q->select('id', 'plugin_product_id', 'status', 'expires_at')])
            ->get(['id', 'name'])
            ->map(function (PluginProduct $product) use ($start): array {
                $licenses = $product->licenses;

                return [
                    'name' => $product->name,
                    'active' => $licenses
                        ->reject(fn (License $l): bool => $l->isRevoked() || $l->hasExpired())
                        ->count(),
                    'total' => $licenses->count(),
                    'agorot' => (int) $this->pluginCharges()
                        ->where('status', ChargeStatus::Succeeded)
                        ->whereRaw('COALESCE(charges.charged_at, charges.created_at) >= ?', [$start])
                        ->where(fn (Builder $q) => $q
                            ->whereIn('charges.id', PluginOrder::query()
                                ->where('plugin_product_id', $product->id)
                                ->whereNotNull('charge_id')->select('charge_id'))
                            ->orWhereIn('charges.subscription_id', License::query()
                                ->where('plugin_product_id', $product->id)
                                ->whereNotNull('subscription_id')->select('subscription_id')))
                        ->sum('total_agorot'),
                ];
            })
            ->sortByDesc('agorot')
            ->values();
    }

    /**
     * Every charge that is a plugin charge: a self-service order, or a renewal
     * of a licence subscription. Both, because either one alone under-reports
     * the business by roughly half.
     *
     * @return Builder<Charge>
     */
    private function pluginCharges(): Builder
    {
        return Charge::query()->where(fn (Builder $q) => $q
            ->whereIn('charges.id', PluginOrder::query()->whereNotNull('charge_id')->select('charge_id'))
            ->orWhereIn('charges.subscription_id', License::query()->whereNotNull('subscription_id')->select('subscription_id')));
    }
}
