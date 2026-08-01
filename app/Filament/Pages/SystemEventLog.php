<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\SystemLog;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * יומן אירועים — אירועים תפעוליים אחרונים של המערכת (בעיקר כשלי סוכן ה-AI), כדי
 * לאבחן תקלות מהפאנל בלי גישה ל-storage/logs. הרשומות נשמרות לתקופה
 * (billing.system.log_retention_days) ונמחקות אוטומטית. לצפייה בלבד, למנהלים בלבד.
 * ממוקם בקבוצת "ניהול" לצד יומן פעולות הצוות.
 */
class SystemEventLog extends Page implements HasTable
{
    use AdminOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?string $navigationLabel = 'יומן אירועים';

    protected static ?string $title = 'יומן אירועים — אירועים תפעוליים אחרונים';

    /** The stored context as readable text — same shape in the column and the modal. */
    private static function pretty(mixed $context): string
    {
        return (string) json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }

    // Right after "יומן פעולות צוות" (90), so the two logs sit together.
    protected static ?int $navigationSort = 91;

    protected static string $view = 'filament.pages.system-event-log';

    private const LEVELS = ['info' => 'מידע', 'warning' => 'אזהרה', 'error' => 'שגיאה'];

    private const SOURCES = ['ai' => 'סוכן AI', 'billing' => 'חיוב', 'monitoring' => 'ניטור', 'system' => 'מערכת'];

    /** Red badge with the number of errors logged in the last 24 hours. */
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = SystemLog::query()->where('level', 'error')->where('created_at', '>=', now()->subDay())->count();
        } catch (\Throwable) {
            return null; // never let the log table break the nav
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SystemLog::query())
            ->defaultSort('created_at', 'desc')
            ->heading('יומן אירועים')
            ->description('אירועים תפעוליים אחרונים — בעיקר כשלי סוכן AI. נשמר לתקופה מוגבלת ונמחק אוטומטית.')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('מתי')->dateTime('d/m/Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('level')->label('רמה')->badge()
                    ->formatStateUsing(fn (string $state): string => self::LEVELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'error' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('source')->label('מקור')->badge()
                    ->formatStateUsing(fn (string $state): string => self::SOURCES[$state] ?? $state),
                Tables\Columns\TextColumn::make('message')->label('הודעה')->wrap()->limit(160),
                Tables\Columns\TextColumn::make('context')->label('פרטים')
                    ->formatStateUsing(fn ($state): string => filled($state) ? self::pretty($state) : '—')
                    ->wrap()->limit(200)->toggleable(),
            ])
            ->actions([
                // The column is truncated, and a report that lists which
                // charges are missing an invoice is worthless at 200
                // characters — the ids are exactly what is cut off. This opens
                // the whole entry.
                Tables\Actions\Action::make('context')
                    ->label('פרטים מלאים')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->visible(fn (SystemLog $record): bool => filled($record->context))
                    ->modalHeading(fn (SystemLog $record): string => $record->message)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('סגירה')
                    ->modalContent(fn (SystemLog $record): HtmlString => new HtmlString(
                        '<pre class="overflow-x-auto whitespace-pre-wrap break-words text-xs leading-relaxed text-gray-700 dark:text-gray-200">'
                        .e(self::pretty($record->context))
                        .'</pre>',
                    )),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')->label('רמה')->options(self::LEVELS),
                Tables\Filters\SelectFilter::make('source')->label('מקור')->options(self::SOURCES),
            ])
            ->emptyStateHeading('אין רשומות ביומן')
            ->emptyStateDescription('אירועים תפעוליים (כמו כשלי סוכן AI) יופיעו כאן.');
    }
}
