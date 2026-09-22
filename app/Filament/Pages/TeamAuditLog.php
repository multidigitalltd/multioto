<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\AuditLog;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * יומן פעולות הצוות — מי עשה מה ומתי. רשומה לכל יצירה/עדכון/מחיקה של ישויות
 * מרכזיות ולכל התחברות/התנתקות, משויכת למשתמש שביצע אותה. לצפייה בלבד (append-only)
 * ולמנהלים בלבד.
 */
class TeamAuditLog extends Page implements HasTable
{
    use AdminOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?string $navigationLabel = 'יומן פעולות צוות';

    protected static ?string $title = 'יומן פעולות צוות — מי עשה מה ומתי';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.audit-log';

    /** Hebrew labels + colours for each audited event. */
    private const EVENTS = [
        'created' => ['נוצר', 'success'],
        'updated' => ['עודכן', 'warning'],
        'deleted' => ['נמחק', 'danger'],
        'login' => ['התחברות', 'gray'],
        'logout' => ['התנתקות', 'gray'],
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditLog::query()->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('מתי')->dateTime('d/m/Y H:i:s')->sortable(),
                Tables\Columns\TextColumn::make('user_name')
                    ->label('מי')->weight('bold')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('event')
                    ->label('פעולה')->badge()
                    ->formatStateUsing(fn (string $state): string => self::EVENTS[$state][0] ?? $state)
                    ->color(fn (string $state): string => self::EVENTS[$state][1] ?? 'gray'),
                Tables\Columns\TextColumn::make('description')
                    ->label('פירוט')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('changes')
                    ->label('שדות שהשתנו')
                    ->state(fn (AuditLog $r): string => is_array($r->changes) ? implode(', ', array_keys($r->changes)) : '—')
                    ->limit(60)->toggleable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('כתובת IP')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                // העמודה מראה את שמות השדות בלבד, וזה מספיק כשהפירוט הוא "מה
                // השתנה בשדה". זה לא מספיק כשהרשומה עצמה היא הראיה היחידה
                // שנשארה — מחיקה מרובה שומרת כאן את כל מה שנמחק, והתיאור לידה
                // נחתך ב-480 תווים. בלי המסך הזה מה שנשמר אינו ניתן לקריאה.
                Tables\Actions\Action::make('payload')
                    ->label('פירוט מלא')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->visible(fn (AuditLog $record): bool => is_array($record->changes) && $record->changes !== [])
                    ->modalHeading('מה נשמר ברשומה')
                    ->modalDescription('הנתונים כפי שנשמרו ברגע הפעולה. ערכים רגישים מוחלפים ב-[hidden] עוד לפני השמירה.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('סגירה')
                    ->modalContent(fn (AuditLog $record): HtmlString => new HtmlString(
                        '<pre dir="ltr" style="white-space:pre-wrap;word-break:break-word;font-size:.8rem;line-height:1.6;max-height:60vh;overflow:auto">'
                        .e(json_encode(
                            $record->changes,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                        ))
                        .'</pre>'
                    )),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('סוג פעולה')
                    ->options(collect(self::EVENTS)->map(fn (array $e): string => $e[0])->all()),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('משתמש')
                    ->relationship('user', 'name')->searchable(),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label('מתאריך'),
                        DatePicker::make('until')->label('עד תאריך'),
                    ])
                    ->query(fn (Builder $q, array $data): Builder => $q
                        ->when($data['from'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '<=', $d))),
            ])
            ->emptyStateHeading('עדיין אין פעולות ביומן');
    }
}
