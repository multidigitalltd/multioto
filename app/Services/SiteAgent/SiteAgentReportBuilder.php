<?php

namespace App\Services\SiteAgent;

use App\Models\Site;
use App\Models\SiteAgentReportSchedule;
use App\Services\Agent\McpClient;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A daily, weekly or monthly report on a customer's site, as a WhatsApp message.
 *
 * Built from the site's own numbers, not written by the model: a report is
 * read as fact and acted on, so every figure in it is a figure the shop
 * returned — and it costs no AI call, which matters for something sent every
 * morning to every customer who asked for one.
 *
 * Each section stands alone. A site without a shop simply has no sales
 * section; a section whose read fails says so in one line, and the rest of the
 * report still arrives.
 *
 * The periods are closed, whole days in the local calendar: a daily report is
 * about yesterday, a weekly one about the seven days that ended yesterday, a
 * monthly one about the previous calendar month. A report about "today so far"
 * sent at 08:00 would be a report about nothing.
 */
class SiteAgentReportBuilder
{
    public function __construct(private McpClient $mcp, private SiteAgentToolbox $toolbox) {}

    /**
     * @param  list<string>  $sections
     * @return array{text: string, summary: string, title: string}
     */
    public function build(Site $site, string $frequency, array $sections, ?CarbonInterface $now = null): array
    {
        [$from, $to, $title] = $this->period($frequency, $now ?? now());
        $range = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];

        $blocks = [];
        $summary = [];

        foreach (array_values(array_intersect(SiteAgentReportSchedule::SECTIONS, $sections)) as $section) {
            $part = match ($section) {
                'sales' => $this->sales($site, $range),
                'orders' => $this->orders($site),
                'leads' => $this->leads($site, $range),
                'subscriptions' => $this->subscriptions($site),
            };

            if ($part === null) {
                continue; // Not something this site has.
            }

            $blocks[] = $part['text'];

            if ($part['summary'] !== null) {
                $summary[] = $part['summary'];
            }
        }

        $heading = "📊 *{$title}* — {$site->domain}";

        return [
            'title' => $title,
            'text' => implode("\n\n", [$heading, ...($blocks !== [] ? $blocks : ['אין באתר הזה נתונים מהסוג שביקשתם.'])]),
            'summary' => $summary !== [] ? implode(' · ', $summary) : 'הדוח מוכן',
        ];
    }

    /**
     * The closed period a report of this frequency covers.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    public function period(string $frequency, CarbonInterface $now): array
    {
        $today = CarbonImmutable::instance($now)->setTimezone(config('app.timezone'))->startOfDay();
        $yesterday = $today->subDay();

        return match ($frequency) {
            SiteAgentReportSchedule::WEEKLY => [$yesterday->subDays(6), $yesterday,
                'דוח שבועי '.$yesterday->subDays(6)->format('d/m').'–'.$yesterday->format('d/m')],
            SiteAgentReportSchedule::MONTHLY => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay(),
                'דוח חודשי '.$today->subMonthNoOverflow()->format('m/Y')],
            default => [$yesterday, $yesterday, 'דוח יומי '.$yesterday->format('d/m/Y')],
        };
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{text: string, summary: string|null}|null
     */
    private function sales(Site $site, array $range): ?array
    {
        if (! $this->toolbox->siteHas($site, 'wc_sales_report')) {
            return null;
        }

        $report = $this->read($site, 'wc_sales_report', $range);

        if ($report === null) {
            return ['text' => '🛒 מכירות: לא הצלחתי לקרוא מהחנות.', 'summary' => null];
        }

        $orders = (int) ($report['paid_orders'] ?? 0);
        $net = $this->shekels((string) ($report['net_sales'] ?? '0'));
        $lines = ["🛒 *מכירות:* {$orders} הזמנות · {$net}"];

        if ((string) ($report['refunded'] ?? '0') !== '0.00' && (string) ($report['refunded'] ?? '0') !== '0') {
            $lines[] = 'החזרים: '.$this->shekels((string) $report['refunded']);
        }

        if ($orders > 0) {
            $lines[] = 'ממוצע להזמנה: '.$this->shekels((string) ($report['average_order'] ?? '0'));
        }

        $before = $this->agorot((string) data_get($report, 'previous_period.gross_sales', '0'));
        $now = $this->agorot((string) ($report['gross_sales'] ?? '0'));

        if ($before > 0) {
            $change = intdiv(($now - $before) * 100, $before);
            $lines[] = 'לעומת התקופה הקודמת: '.($change >= 0 ? '+' : '').$change.'%';
        }

        $top = array_slice((array) ($report['top_products'] ?? []), 0, 3);

        if ($top !== []) {
            $lines[] = 'מובילים: '.implode(', ', array_map(fn (array $product): string => ($product['name'] ?? '').' ('.($product['quantity'] ?? 0).')', $top));
        }

        return ['text' => implode("\n", $lines), 'summary' => "{$orders} הזמנות · {$net}"];
    }

    /** @return array{text: string, summary: string|null}|null */
    private function orders(Site $site): ?array
    {
        if (! $this->toolbox->siteHas($site, 'wc_order_list')) {
            return null;
        }

        $processing = $this->read($site, 'wc_order_list', ['status' => 'processing', 'limit' => 50]);
        $onHold = $this->read($site, 'wc_order_list', ['status' => 'on-hold', 'limit' => 50]);

        if ($processing === null) {
            return ['text' => '📦 הזמנות לטיפול: לא הצלחתי לקרוא מהחנות.', 'summary' => null];
        }

        $count = $this->counted((int) ($processing['count'] ?? 0));
        $held = $onHold !== null ? $this->counted((int) ($onHold['count'] ?? 0)) : null;

        return [
            'text' => "📦 *ממתינות לטיפול עכשיו:* {$count} בטיפול".($held !== null && $held !== '0' ? " · {$held} בהמתנה" : ''),
            'summary' => $count !== '0' ? "{$count} לטיפול" : null,
        ];
    }

    /**
     * @param  array{from: string, to: string}  $range
     * @return array{text: string, summary: string|null}|null
     */
    private function leads(Site $site, array $range): ?array
    {
        if (! $this->toolbox->siteHas($site, 'wp_lead_list')) {
            return null;
        }

        $leads = $this->read($site, 'wp_lead_list', [...$range, 'limit' => 50]);

        if ($leads === null) {
            return ['text' => '📥 לידים: לא הצלחתי לקרוא מהטפסים.', 'summary' => null];
        }

        if (((array) ($leads['sources'] ?? [])) === []) {
            return ['text' => '📥 לידים: לא נמצא באתר תוסף טפסים ששומר פניות.', 'summary' => null];
        }

        $count = $this->counted((int) ($leads['count'] ?? 0));
        $lines = ["📥 *לידים:* {$count}"];

        foreach (array_slice((array) ($leads['leads'] ?? []), 0, 5) as $lead) {
            $fields = array_slice(array_values((array) ($lead['fields'] ?? [])), 0, 2);
            $lines[] = '• '.trim(($lead['date'] ?? '').' — '.implode(', ', $fields), ' —');
        }

        return ['text' => implode("\n", $lines), 'summary' => "{$count} לידים"];
    }

    /** @return array{text: string, summary: string|null}|null */
    private function subscriptions(Site $site): ?array
    {
        if (! $this->toolbox->siteHas($site, 'wcs_subscription_list')) {
            return null;
        }

        $active = $this->read($site, 'wcs_subscription_list', ['status' => 'active', 'limit' => 50]);
        $onHold = $this->read($site, 'wcs_subscription_list', ['status' => 'on-hold', 'limit' => 50]);

        if ($active === null) {
            return ['text' => '🔁 מנויים: לא הצלחתי לקרוא מהחנות.', 'summary' => null];
        }

        $held = $onHold !== null ? $this->counted((int) ($onHold['count'] ?? 0)) : '0';

        return [
            'text' => '🔁 *מנויים פעילים:* '.$this->counted((int) ($active['count'] ?? 0)).($held !== '0' ? " · {$held} מושהים" : ''),
            'summary' => null,
        ];
    }

    /**
     * A plugin read, decoded — or null when it failed.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function read(Site $site, string $tool, array $arguments): ?array
    {
        try {
            $decoded = json_decode($this->mcp->textContent($this->mcp->callTool($site, $tool, $arguments)), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** The lists stop at 50; "50+" is the honest way to say so. */
    private function counted(int $count): string
    {
        return $count >= 50 ? '50+' : (string) $count;
    }

    /** "1234.5" from the shop as ₪1,234.50 — parsed as text, never through a float. */
    private function shekels(string $decimal): string
    {
        return Money::ils($this->agorot($decimal));
    }

    private function agorot(string $decimal): int
    {
        $decimal = trim($decimal);
        $negative = str_starts_with($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-'), 2), 2, '');
        $value = (int) preg_replace('/\D/', '', $whole) * 100 + (int) str_pad(substr(preg_replace('/\D/', '', $fraction), 0, 2), 2, '0');

        return $negative ? -$value : $value;
    }
}
