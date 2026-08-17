<?php

namespace App\Services\Licensing;

use App\Enums\ChargeStatus;
use App\Models\Charge;
use App\Models\License;
use App\Models\LicenseSite;
use App\Models\PluginOrder;
use App\Models\PluginProduct;
use Illuminate\Database\Eloquent\Builder;
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
     * Renewal collection in the window, measured on attempts rather than on
     * licences — see the class note for why that distinction is the whole point.
     *
     * `rate` is null when nothing was attempted. A renewal rate of 0% and no
     * renewals due are opposite situations and must not print the same.
     *
     * @return array{attempted: int, succeeded: int, failed: int, open: int, rate: ?float, lostAgorot: int}
     */
    public function renewals(): array
    {
        $start = now()->subDays(self::WINDOW_DAYS);

        $charges = Charge::query()
            ->whereIn('subscription_id', License::query()->whereNotNull('subscription_id')->select('subscription_id'))
            ->where('created_at', '>=', $start)
            ->get(['status', 'total_agorot']);

        $succeeded = $charges->where('status', ChargeStatus::Succeeded);
        $failed = $charges->where('status', ChargeStatus::Failed);
        $settled = $succeeded->count() + $failed->count();

        return [
            'attempted' => $charges->count(),
            'succeeded' => $succeeded->count(),
            'failed' => $failed->count(),
            // Still in flight — counted apart so they neither flatter the rate
            // nor count against it.
            'open' => $charges->where('status', ChargeStatus::Pending)->count(),
            'rate' => $settled > 0 ? round($succeeded->count() / $settled * 100) : null,
            'lostAgorot' => (int) $failed->sum('total_agorot'),
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
