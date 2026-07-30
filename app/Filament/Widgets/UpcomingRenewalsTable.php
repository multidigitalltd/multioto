<?php

namespace App\Filament\Widgets;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\CustomerResource;
use App\Models\Subscription;
use App\Support\Money;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money still ahead of us: subscriptions due to renew, soonest first.
 *
 * The column that matters is "אופן גבייה". A renewal with a saved card charges
 * itself on the day; one without a card does nothing at all when the date
 * arrives, and is only collected if a person notices. Showing both in one list
 * without saying which is which is how the second kind gets forgotten.
 */
class UpcomingRenewalsTable extends BaseWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->canAccessModule('finance') ?? false;
    }

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    /** Renewable status + a future charge date. */
    private static function baseQuery(): Builder
    {
        return Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue])
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '>=', now()->startOfDay());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('חידושים צפויים — מה ייגבה מעצמו ומה לא')
            ->description('מנויים לפי תאריך החיוב הבא. "גבייה אוטומטית" נגבית מהכרטיס השמור ביום החיוב; "גבייה ידנית" לא תיגבה מעצמה.')
            ->query(self::baseQuery()->with(['customer', 'plan', 'site']))
            ->defaultSort('next_charge_at', 'asc')
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
                Tables\Columns\TextColumn::make('method')
                    ->label('אופן גבייה')
                    ->badge()
                    ->state(fn (Subscription $r): string => $r->token_id !== null
                        ? 'גבייה אוטומטית'
                        : 'גבייה ידנית — אין כרטיס')
                    ->color(fn (Subscription $r): string => $r->token_id !== null ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('next_charge_at')
                    ->label('חיוב הבא')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('days_left')
                    ->label('בעוד (ימים)')
                    ->state(fn (Subscription $r): int => max(0, (int) now()->startOfDay()
                        ->diffInDays($r->next_charge_at->copy()->startOfDay()))),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')->badge()->toggleable(),
            ])
            ->filters([
                // The horizons the old forecast screen showed as separate squares.
                Tables\Filters\SelectFilter::make('horizon')
                    ->label('טווח')
                    ->options(['7' => '7 ימים', '30' => '30 יום', '90' => '90 יום'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('next_charge_at', '<=', now()->addDays((int) $data['value']))
                        : $query),
                Tables\Filters\Filter::make('manual_only')
                    ->label('רק מה שלא ייגבה לבד')
                    ->query(fn (Builder $query): Builder => $query->whereNull('token_id')),
            ])
            ->emptyStateHeading('אין חידושים צפויים')
            ->emptyStateDescription('לא נמצאו מנויים עם תאריך חיוב עתידי.');
    }
}
