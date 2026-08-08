<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SiteResource;
use App\Models\SiteEvent;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * חיווי בפאנל הראשי לממצאי האתרים שעוד לא טופלו: מנהל חדש, שינוי DNS, תוסף
 * שהותקן או הוסר, השחתה, פגיעות.
 *
 * הממצאים האלה נשלחו תמיד במייל ובקבוצת הניהול — וזו בדיוק הייתה הבעיה: התראה
 * שנקראה בטלפון בין הודעות אחרות אינה משאירה שום סימן שמישהו בדק אותה. כאן הן
 * יושבות מול העיניים עד שמסמנים "טופל", בדיוק כמו המשימות הפתוחות.
 *
 * הווידג'ט נשאר על המסך גם כשאין ממצאים, ואומר זאת במפורש. ווידג'ט שנעלם משאיר
 * את השאלה "אין ממצאים, או שהבדיקה מפסיקה לרוץ?" בלי תשובה.
 */
class SiteAlerts extends BaseWidget
{
    /**
     * מתחת ל"אתרים בבעיה" (50-) ומעל מעקב ה-SLA (45-).
     *
     * אתר שנפל עכשיו קודם לממצא שממתין לבדיקה: הראשון הוא לקוח בלי אתר ברגע
     * זה, השני הוא משהו שצריך לבדוק היום.
     */
    protected static ?int $sort = -47;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'התראות אבטחה מהאתרים';

    /** מוסתר ממי שאין לו מודול הניהול — אלה ממצאים על אתרי לקוחות. */
    public static function canView(): bool
    {
        return auth()->user()?->canAccessModule('management') ?? false;
    }

    /** מספר הממצאים שממתינים — לשימוש גם בתג הניווט של האתרים. */
    public static function pendingCount(): int
    {
        try {
            return SiteEvent::query()->pendingReview()->count();
        } catch (\Throwable) {
            return 0; // טבלה חסרה בזמן מיגרציה לא תפיל את הפאנל
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SiteEvent::query()
                    ->pendingReview()
                    ->with('site.customer')
                    // הקריטי לפני האזהרה, ובתוך כל רמה — החדש קודם.
                    ->orderByRaw("case when severity = 'critical' then 0 else 1 end")
                    ->orderByDesc('detected_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('detected_at')->label('זוהה')
                    ->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')->label('ממצא')->badge()
                    ->formatStateUsing(fn (SiteEvent $record): string => $record->label())
                    ->color(fn (SiteEvent $record): string => $record->severityColor()),
                Tables\Columns\TextColumn::make('site.domain')->label('אתר')->weight('medium')->placeholder('—'),
                Tables\Columns\TextColumn::make('site.customer.name')->label('לקוח')->placeholder('—'),
                Tables\Columns\TextColumn::make('title')->label('פירוט')->wrap()
                    ->description(fn (SiteEvent $record): ?string => Str::limit($record->detail, 160)),
            ])
            ->actions([
                Tables\Actions\Action::make('acknowledge')
                    ->label('טופל')->icon('heroicon-o-check')->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('לסמן שהממצא טופל?')
                    ->modalDescription('הממצא יירד מהחיווי ויישאר ביומן הממצאים של האתר, עם השם והשעה של הסימון.')
                    ->action(function (SiteEvent $record): void {
                        $record->acknowledge(auth()->user());

                        Notification::make()->title('הממצא סומן כטופל')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('acknowledgeSelected')
                    ->label('סימון הנבחרים כטופלו')->icon('heroicon-o-check')->color('success')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $user = auth()->user();
                        $records->each(fn (SiteEvent $record) => $record->acknowledge($user));

                        Notification::make()->title("{$records->count()} ממצאים סומנו כטופלו")->success()->send();
                    }),
            ])
            ->recordUrl(fn (SiteEvent $record): ?string => $record->site
                ? SiteResource::getUrl('view', ['record' => $record->site])
                : null)
            ->paginated([5, 10, 25])
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading('אין ממצאים חדשים מהאתרים')
            ->emptyStateDescription('כל מה שזוהה — שינוי DNS, מנהל או תוסף חדש, השחתה — יופיע כאן עד שיסומן כטופל.');
    }
}
