<?php

namespace App\Filament\Pages;

use App\Models\SystemLog;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * מערכת ועדכונים — יומן פנימי של אירועים תפעוליים חשובים (בעיקר כשלים של סוכן
 * ה-AI), כדי לאבחן תקלות ישירות מהפאנל בלי גישה ל-storage/logs. הרשומות
 * נשמרות לתקופה מוגדרת (billing.system.log_retention_days) ונמחקות אוטומטית.
 */
class SystemLogs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'הגדרות';

    protected static ?string $navigationLabel = 'מערכת ועדכונים';

    protected static ?string $title = 'מערכת ועדכונים — יומן אירועים';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.collections';

    private const LEVELS = ['info' => 'מידע', 'warning' => 'אזהרה', 'error' => 'שגיאה'];

    private const SOURCES = ['ai' => 'סוכן AI', 'billing' => 'חיוב', 'monitoring' => 'ניטור', 'system' => 'מערכת'];

    /** Red badge with the number of errors in the last 24 hours. */
    public static function getNavigationBadge(): ?string
    {
        $count = SystemLog::query()->where('level', 'error')->where('created_at', '>=', now()->subDay())->count();

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
                    ->formatStateUsing(fn ($state): string => filled($state) ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—')
                    ->wrap()->limit(200)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')->label('רמה')->options(self::LEVELS),
                Tables\Filters\SelectFilter::make('source')->label('מקור')->options(self::SOURCES),
            ])
            ->emptyStateHeading('אין רשומות ביומן')
            ->emptyStateDescription('אירועים תפעוליים (כמו כשלי סוכן AI) יופיעו כאן.');
    }
}
