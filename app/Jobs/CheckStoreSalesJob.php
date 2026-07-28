<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SystemLog;
use App\Services\Agent\McpClient;
use App\Services\Notifications\TeamNotifier;
use App\Services\Security\SalesPulse;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Silent-failure watch for a WooCommerce store: a shop can answer 200, look
 * perfectly healthy to the uptime monitor, and still be losing every sale —
 * a broken checkout, a plugin conflict on the cart, an expired gateway
 * certificate. This job reads the store's own sales pulse and alerts when
 * today breaks sharply with the store's normal rhythm.
 *
 * Deliberately conservative: it needs a real baseline (a shop that never sells
 * is never "silent"), and it re-alerts at most once per cooldown window so a
 * long outage does not spam the team every morning.
 */
class CheckStoreSalesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public function __construct(public int $siteId) {}

    public function failed(?\Throwable $e): void
    {
        SystemLog::record('error', 'monitoring',
            "בדיקת דופק המכירות לאתר #{$this->siteId} נכשלה בשגיאה לא צפויה: ".($e?->getMessage() ?: 'שגיאה לא ידועה'),
            ['site_id' => $this->siteId]);
    }

    public function handle(McpClient $mcp, SalesPulse $pulse, TeamNotifier $team): void
    {
        $site = Site::with('customer')->find($this->siteId);

        if (! $site || ! $site->mcp_enabled || blank($site->mcp_endpoint)) {
            return;
        }

        $tools = collect((array) data_get($site->mcp_capabilities, 'tools', []))->pluck('name');

        if (! $tools->contains('wc_order_stats_get')) {
            return; // Not a store, or the site runs an older agent plugin.
        }

        try {
            $text = $mcp->textContent($mcp->callTool($site, 'wc_order_stats_get'));
            $stats = json_decode($text, true);
        } catch (\Throwable $e) {
            SystemLog::record('warning', 'monitoring',
                "לא ניתן לקרוא את דופק המכירות באתר {$site->domain}: ".$e->getMessage(),
                ['site_id' => $site->id]);

            return;
        }

        if (! is_array($stats) || ! isset($stats['daily'])) {
            SystemLog::record('warning', 'monitoring',
                "דופק המכירות באתר {$site->domain} הוחזר בפורמט לא מוכר — הבדיקה דולגה.",
                ['site_id' => $site->id]);

            return;
        }

        $verdict = $pulse->evaluate($stats);

        $state = (array) $site->store_pulse;
        $state['checked_at'] = now()->toIso8601String();
        $state['orders_24h'] = $verdict['orders'];
        $state['paid_24h'] = $verdict['paid'];
        $state['baseline_orders'] = $verdict['baseline_orders'];
        $state['baseline_paid'] = $verdict['baseline_paid'];
        $state['status'] = $verdict['kind'] ?? 'ok';

        if ($verdict['kind'] !== null && $this->mayAlert($state, $verdict['kind'])) {
            $this->alert($team, $site, $verdict);
            $state['last_alert_at'] = now()->toIso8601String();
            $state['last_alert_kind'] = $verdict['kind'];
        }

        if ($verdict['kind'] === null) {
            // Recovered — the next real failure alerts immediately.
            unset($state['last_alert_at'], $state['last_alert_kind']);
        }

        $site->update(['store_pulse' => $state]);
    }

    /**
     * One alert per cooldown window per failure kind — a shop that stays broken
     * for a week must not produce a week of identical alarms, but a NEW kind of
     * failure (payments on top of silence) is always worth saying out loud.
     *
     * @param  array<string, mixed>  $state
     */
    private function mayAlert(array $state, string $kind): bool
    {
        if (($state['last_alert_kind'] ?? null) !== $kind) {
            return true;
        }

        $last = $state['last_alert_at'] ?? null;

        if (! is_string($last)) {
            return true;
        }

        $hours = max(1, (int) config('billing.monitoring.store_pulse.cooldown_hours', 24));

        try {
            return Carbon::parse($last)->addHours($hours)->isPast();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array{kind: ?string, orders: int, paid: int, baseline_orders: float, baseline_paid: float, days: int}  $verdict
     */
    private function alert(TeamNotifier $team, Site $site, array $verdict): void
    {
        $who = $site->customer ? " ({$site->customer->name})" : '';

        if ($verdict['kind'] === 'store_silent') {
            $title = "🛒 החנות {$site->domain} הפסיקה לקבל הזמנות";
            $body = "לא נוצרה אף הזמנה ב-24 השעות האחרונות בחנות {$site->domain}{$who}, "
                ."בעוד שבממוצע נכנסות {$verdict['baseline_orders']} הזמנות ביום.\n"
                .'האתר עצמו עונה כרגיל — לכן הניטור הרגיל לא מתריע. בדקו את תהליך הרכישה: עגלה, צ\'קאאוט, ושיטות המשלוח/תשלום.';
            $detail = "הזמנות ב-24ש: 0 · ממוצע יומי: {$verdict['baseline_orders']} (על פני {$verdict['days']} ימים)";
        } else {
            $title = "💳 בחנות {$site->domain} אף הזמנה לא שולמה";
            $body = "בחנות {$site->domain}{$who} נוצרו {$verdict['orders']} הזמנות ב-24 השעות האחרונות — ואף אחת מהן לא שולמה, "
                ."בעוד שבדרך כלל משולמות {$verdict['baseline_paid']} ביום.\n"
                .'זהו הסימן הקלאסי לתקלה בסליקה. בדקו את חיבור ספק התשלומים ואת יומן השגיאות.';
            $detail = "הזמנות ב-24ש: {$verdict['orders']} · מתוכן שולמו: 0 · ממוצע תשלומים יומי: {$verdict['baseline_paid']}";
        }

        $team->alert($title, $body, rtrim((string) config('app.url'), '/')."/admin/sites/{$site->id}");

        SiteEvent::record($site->id, (string) $verdict['kind'], 'critical', $title, $detail);
    }
}
