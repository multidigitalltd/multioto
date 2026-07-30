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
use Illuminate\Database\Query\Builder as QueryBuilder;

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

    /** Why a renewal will or will not collect itself, in the customer's words. */
    private static function method(Subscription $subscription): string
    {
        if ($subscription->collectsAutomatically()) {
            return 'גבייה אוטומטית';
        }

        return $subscription->token_id === null
            ? 'גבייה ידנית — אין כרטיס'
            : 'גבייה ידנית — בתקופת ניסיון';
    }

    /**
     * VAT-inclusive total of the rows a summary covers. The amount is computed
     * per subscription (override / plan price / customer VAT exemption), so it
     * cannot be summed in SQL — the ids come from the filtered query and the
     * models do the arithmetic.
     */
    private static function sumOf(QueryBuilder $query): int
    {
        $ids = $query->pluck('subscriptions.id');

        return (int) Subscription::query()
            ->whereKey($ids)
            ->with(['plan', 'customer'])
            ->get()
            ->sum(fn (Subscription $s): int => $s->totalChargeAgorot());
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
                    ->state(fn (Subscription $r): string => Money::ils($r->totalChargeAgorot()))
                    // The total for whatever horizon is filtered — this is where
                    // the old forecast screen's 7/30/60/90 squares now live.
                    ->summarize(Tables\Columns\Summarizers\Summarizer::make()
                        ->label('סה״כ בטווח המוצג')
                        ->using(fn (QueryBuilder $query): string => Money::ils(self::sumOf($query)))),
                Tables\Columns\TextColumn::make('method')
                    ->label('אופן גבייה')
                    ->badge()
                    ->state(fn (Subscription $r): string => self::method($r))
                    ->color(fn (Subscription $r): string => $r->collectsAutomatically() ? 'success' : 'danger'),
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
                    ->options(['7' => '7 ימים', '30' => '30 יום', '60' => '60 יום', '90' => '90 יום'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('next_charge_at', '<=', now()->addDays((int) $data['value']))
                        : $query),
                Tables\Filters\Filter::make('manual_only')
                    ->label('רק מה שלא ייגבה לבד')
                    // Mirrors collectsAutomatically(): no card, OR a card the
                    // scheduler will not act on because of the status.
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $q) => $q->whereNull('token_id')
                            ->orWhereNotIn('status', Subscription::AUTO_CHARGE_STATUSES)
                    )),
            ])
            ->emptyStateHeading('אין חידושים צפויים')
            ->emptyStateDescription('לא נמצאו מנויים עם תאריך חיוב עתידי.');
    }
}
