<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SiteResource;
use App\Models\Site;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Dashboard: sites that need attention — currently down (open incident) or with
 * a TLS certificate inside the warning window. Auto-hidden when all sites are
 * healthy.
 */
class SitesInTrouble extends BaseWidget
{
    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'אתרים בבעיה';

    protected static function query()
    {
        $warn = (int) config('billing.monitoring.ssl_warn_days', 14);

        return Site::query()
            ->with('customer')
            ->where('monitor_enabled', true)
            ->where(fn ($q) => $q
                ->whereHas('openIncident')
                ->orWhere(fn ($q2) => $q2->whereNotNull('ssl_days_left')->where('ssl_days_left', '<=', $warn)));
    }

    public static function canView(): bool
    {
        return static::query()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static::query())
            ->columns([
                Tables\Columns\TextColumn::make('domain')->label('אתר')->weight('bold'),
                Tables\Columns\TextColumn::make('customer.name')->label('לקוח')->placeholder('—'),
                Tables\Columns\TextColumn::make('state')
                    ->label('מצב')
                    ->badge()
                    ->getStateUsing(fn (Site $r): string => $r->openIncident ? 'לא זמין' : 'SSL עומד לפוג')
                    ->color(fn (Site $r): string => $r->openIncident ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('ssl_days_left')->label('SSL (ימים)')->placeholder('—'),
            ])
            ->recordUrl(fn (Site $record): string => SiteResource::getUrl('view', ['record' => $record]))
            ->paginated([5]);
    }
}
