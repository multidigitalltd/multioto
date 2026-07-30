<?php

namespace App\Filament\Widgets;

use App\Enums\ChargeStatus;
use App\Filament\Resources\CustomerResource;
use App\Models\Charge;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money already asked for and not yet paid: proforma invoices (חשבוניות עסקה)
 * that went out and are still open, oldest debt first.
 *
 * Nothing here collects itself — every row is waiting on a person, which is why
 * it sits beside the renewals rather than inside them. The age comes from the
 * immutable creation date, not from the last reminder: reminders overwrite
 * demand_sent_at, so reading it would make an old debt look new every nudge.
 */
class OpenDemandsTable extends BaseWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->canAccessModule('finance') ?? false;
    }

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '30s';

    /** Age buckets in days: [label, min-inclusive, max-inclusive|null, color]. */
    private const BUCKETS = [
        ['0–30 ימים', 0, 30, 'gray'],
        ['31–60 ימים', 31, 60, 'warning'],
        ['61–90 ימים', 61, 90, 'danger'],
        ['מעל 90 ימים', 91, null, 'danger'],
    ];

    /** Every payment demand still awaiting payment. */
    private static function baseQuery(): Builder
    {
        return Charge::query()
            ->where('status', ChargeStatus::Pending)
            ->whereNotNull('demand_sent_at');
    }

    private static function ageDays(Charge $charge): int
    {
        return $charge->created_at
            ? (int) $charge->created_at->startOfDay()->diffInDays(now()->startOfDay())
            : 0;
    }

    /** The bucket [label, color] a given age falls into. */
    private static function bucketFor(int $age): array
    {
        foreach (self::BUCKETS as [$label, $min, $max, $color]) {
            if ($age >= $min && ($max === null || $age <= $max)) {
                return [$label, $color];
            }
        }

        return ['—', 'gray'];
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('חשבוניות עסקה פתוחות — כסף שממתין לגבייה ידנית')
            ->description('דרישות תשלום שנשלחו וטרם שולמו, מהחוב הישן לחדש. אף אחת מהן לא תיגבה מעצמה.')
            ->query(self::baseQuery()->with(['customer', 'subscription.customer']))
            ->defaultSort('created_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('לקוח')->weight('bold')
                    ->state(fn (Charge $r): ?string => $r->subscription?->customer?->name ?? $r->customer?->name),
                Tables\Columns\TextColumn::make('description')
                    ->label('עבור')->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('total_agorot')
                    ->label('סכום')->money('ILS', divideBy: 100)
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('סה״כ')->money('ILS', divideBy: 100)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('נוצרה')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('demand_sent_at')
                    ->label('פנייה אחרונה')->date('d/m/Y')->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('age')
                    ->label('גיל החוב (ימים)')
                    ->state(fn (Charge $r): int => self::ageDays($r)),
                Tables\Columns\TextColumn::make('bucket')
                    ->label('טווח')->badge()
                    ->state(fn (Charge $r): string => self::bucketFor(self::ageDays($r))[0])
                    ->color(fn (Charge $r): string => self::bucketFor(self::ageDays($r))[1]),
            ])
            ->filters([
                // The age breakdown the old collection screen showed as squares.
                Tables\Filters\SelectFilter::make('bucket')
                    ->label('טווח גיל')
                    ->options(collect(self::BUCKETS)->mapWithKeys(
                        fn (array $b, int $i): array => [$i => $b[0]]
                    )->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $bucket = self::BUCKETS[$data['value']] ?? null;

                        if ($bucket === null) {
                            return $query;
                        }

                        // Older debt = earlier created_at, so the day bounds invert.
                        [, $min, $max] = $bucket;
                        $query->where('created_at', '<=', now()->startOfDay()->subDays($min)->endOfDay());

                        return $max === null
                            ? $query
                            : $query->where('created_at', '>=', now()->startOfDay()->subDays($max));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('viewCustomer')
                    ->label('לכרטיס הלקוח')->icon('heroicon-o-user')->color('gray')
                    ->url(fn (Charge $r): ?string => ($c = $r->subscription?->customer ?? $r->customer)
                        ? CustomerResource::getUrl('view', ['record' => $c]) : null),
            ])
            ->emptyStateHeading('אין חוב פתוח')
            ->emptyStateDescription('כל דרישות התשלום שולמו — אין כסף פתוח לגבייה.');
    }
}
