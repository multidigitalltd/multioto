<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Services\SiteAgent\SiteAgentUsageReport;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * שימוש בבוט ניהול האתר — מי משתמש, כמה שילם, וכמה עלה ה-AI לשרת אותו.
 * לקוחות שעלותם גבוהה מההכנסה מהם מוצגים ראשונים. למנהלים בלבד.
 */
class SiteAgentUsageDashboard extends Page
{
    use AdminOnly;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'שימוש בבוט ניהול האתר';

    protected static ?string $title = 'שימוש בבוט ניהול האתר — הכנסה מול עלות AI';

    protected static ?int $navigationSort = 24;

    protected static ?string $slug = 'site-agent-usage';

    protected static string $view = 'filament.pages.site-agent-usage';

    /** Trailing window (days). Bound to the page's select. */
    public int $windowDays = 30;

    /** The windows the operator can pick from (whitelist — never free input). */
    public const WINDOWS = [7 => '7 ימים', 30 => '30 ימים', 90 => '90 ימים'];

    public function updatedWindowDays(mixed $value): void
    {
        $this->windowDays = array_key_exists((int) $value, self::WINDOWS) ? (int) $value : 30;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getRowsProperty(): Collection
    {
        return app(SiteAgentUsageReport::class)->rows($this->windowDays);
    }

    /** @return array{customers: int, messages: int, writings: int, revenue: int, cost: int, margin: int} */
    public function getTotalsProperty(): array
    {
        $rows = $this->rows;

        return [
            'customers' => $rows->count(),
            'messages' => (int) $rows->sum('messages'),
            'writings' => (int) $rows->sum('writings'),
            'revenue' => (int) $rows->sum('revenue_agorot'),
            'cost' => (int) $rows->sum('ai_cost_agorot'),
            'margin' => (int) $rows->sum('margin_agorot'),
        ];
    }
}
