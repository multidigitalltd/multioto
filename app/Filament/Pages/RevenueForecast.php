<?php

namespace App\Filament\Pages;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\CustomerResource;
use App\Filament\Widgets\RevenueForecastStats;
use App\Models\Subscription;
use App\Support\Money;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * חיזוי תזרים — ההכנסות הצפויות מחידושי מנויים בתקופה הקרובה, לפי תאריך החיוב
 * הבא של כל מנוי. משלים את "חיזוי גבייה" (שמסתכל אחורה על חוב פתוח); כאן מסתכלים
 * קדימה על מה שצפוי להיכנס.
 */
class RevenueForecast extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'חיזוי תזרים';

    protected static ?string $title = 'חיזוי תזרים — חידושים צפויים';

    protected static ?int $navigationSort = 23;

    protected static string $view = 'filament.pages.revenue-forecast';

    /** Upcoming renewals: renewable status + a future charge date. */
    private static function baseQuery(): Builder
    {
        return Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue])
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '>=', now()->startOfDay());
    }

    protected function getHeaderWidgets(): array
    {
        return [RevenueForecastStats::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(self::baseQuery()->with(['customer', 'plan', 'site']))
            ->defaultSort('next_charge_at', 'asc') // soonest renewal first
            ->poll('60s')
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('לקוח')->weight('bold')->searchable()
                    ->url(fn (Subscription $r): ?string => $r->customer
                        ? CustomerResource::getUrl('view', ['record' => $r->customer_id]) : null),
                Tables\Columns\TextColumn::make('plan_name')
                    ->label('מנוי')
                    ->state(fn (Subscription $r): string => $r->planName()),
                Tables\Columns\TextColumn::make('site.domain')
                    ->label('אתר')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('סכום צפוי')
                    ->state(fn (Subscription $r): string => Money::ils($r->totalChargeAgorot())),
                Tables\Columns\TextColumn::make('next_charge_at')
                    ->label('חיוב הבא')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('days_left')
                    ->label('בעוד (ימים)')
                    ->state(fn (Subscription $r): int => max(0, (int) ceil(now()->startOfDay()->diffInDays($r->next_charge_at, false)))),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')->badge(),
            ])
            ->filters([
                Tables\Filters\Filter::make('next_30')
                    ->label('רק 30 הימים הקרובים')
                    ->query(fn (Builder $query): Builder => $query->where('next_charge_at', '<=', now()->addDays(30))),
            ])
            ->emptyStateHeading('אין חידושים צפויים')
            ->emptyStateDescription('לא נמצאו מנויים עם תאריך חיוב עתידי.');
    }
}
