<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Services\Billing\ProfitabilityReport;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * רווחיות לקוחות — כמה כל לקוח מכניס מול כמה עבודה הוא צורך בחלון הנבחר.
 * העומס מוערך בדקות (פניות, הודעות נכנסות, תקלות) לפי משקלים מתצורה ומתורגם
 * לעלות; לקוחות עם רווח נמוך/שלילי מוצגים ראשונים. למנהלים בלבד — נתון עסקי רגיש.
 */
class CustomerProfitability extends Page
{
    use AdminOnly;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'רווחיות לקוחות';

    protected static ?string $title = 'רווחיות לקוחות — הכנסה מול עומס טיפול';

    protected static ?int $navigationSort = 23;

    protected static string $view = 'filament.pages.customer-profitability';

    /** Trailing window (days) the report covers. Bound to the page's select. */
    public int $windowDays = 90;

    /** The windows the operator can pick from (whitelist — never free input). */
    public const WINDOWS = [30 => '30 ימים', 90 => '90 ימים', 365 => 'שנה'];

    /** Re-render with a new window; out-of-list values fall back to 90. */
    public function updatedWindowDays(mixed $value): void
    {
        $this->windowDays = array_key_exists((int) $value, self::WINDOWS) ? (int) $value : 90;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getRowsProperty(): Collection
    {
        return app(ProfitabilityReport::class)->rows($this->windowDays);
    }

    /** @return array{revenue: int, cost: int, profit: int} */
    public function getTotalsProperty(): array
    {
        $rows = $this->rows;

        return [
            'revenue' => (int) $rows->sum('revenue_agorot'),
            'cost' => (int) $rows->sum('cost_agorot'),
            'profit' => (int) $rows->sum('profit_agorot'),
        ];
    }
}
