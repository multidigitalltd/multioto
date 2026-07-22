<?php

namespace App\Filament\Pages;

use App\Enums\ChargeStatus;
use App\Filament\Resources\CustomerResource;
use App\Filament\Widgets\CollectionForecastStats;
use App\Models\Charge;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * חיזוי גבייה — כל הכסף הפתוח (דרישות תשלום שטרם שולמו) לפי גיל החוב, כדי לראות
 * כמה צפוי להיגבות ומה כבר "תקוע". החלוקה לטווחי גיל (0–30 / 31–60 / 61–90 / 90+
 * ימים) מוצגת בכותרת, והטבלה מפרטת כל דרישה מהישנה לחדשה.
 */
class CollectionForecast extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'כספים';

    protected static ?string $navigationLabel = 'חיזוי גבייה';

    protected static ?string $title = 'חיזוי גבייה (Aging)';

    protected static ?int $navigationSort = 22;

    protected static string $view = 'filament.pages.collections';

    /** Age buckets in days: [label, min-inclusive, max-inclusive|null]. */
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

    /**
     * The aging breakdown as stat squares at the TOP of the page (not on the
     * navigation badge — the numbers show only when you open the page).
     */
    protected function getHeaderWidgets(): array
    {
        return [CollectionForecastStats::class];
    }

    /**
     * Age of the DEBT in days — from the immutable creation date, not
     * demand_sent_at (which the reminder flows overwrite on every nudge, so it
     * tracks last contact, not the age of the unpaid demand).
     */
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
            ->query(self::baseQuery()->with(['customer', 'subscription.customer']))
            ->defaultSort('created_at', 'asc') // oldest debt first (immutable date)
            ->poll('30s')
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('לקוח')->weight('bold')
                    ->getStateUsing(fn (Charge $r): ?string => $r->subscription?->customer?->name ?? $r->customer?->name),
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
                    ->getStateUsing(fn (Charge $r): int => self::ageDays($r)),
                Tables\Columns\TextColumn::make('bucket')
                    ->label('טווח')->badge()
                    ->getStateUsing(fn (Charge $r): string => self::bucketFor(self::ageDays($r))[0])
                    ->color(fn (Charge $r): string => self::bucketFor(self::ageDays($r))[1]),
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
