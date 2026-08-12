<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\AuditLog;
use App\Models\FailedJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * עבודות שנכשלו — ומה עושים איתן.
 *
 * כל שורה כאן היא משהו שהמערכת יצאה לעשות ולא עשתה: תשובה שלא נשלחה, חשבונית
 * שלא הונפקה, הודעת לקוח שלא נקלטה. אף אחת מהן אינה מתקנת את עצמה עם הזמן.
 *
 * עד עכשיו ההפניה הייתה למסך הטכני של Horizon — שם מחלקה, payload מקודד ו-stack
 * trace באנגלית. זו אינה שפה שמחליטים בה, ולכן בפועל לא החליטו: תשע-עשרה עבודות
 * ישבו שם חודש בזמן שהמערכת דיווחה עליהן בכל בדיקת בריאות.
 *
 * המסך הזה אומר בעברית מה הייתה כל עבודה, על מי היא, מה נשבר, ומה קורה אם לא
 * יעשו איתה כלום — ומאפשר לנסות שוב או למחוק, אחת-אחת או בבת אחת.
 */
class FailedJobs extends Page implements HasTable
{
    use AdminOnly;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?string $navigationLabel = 'עבודות שנכשלו';

    protected static ?string $title = 'עבודות שנכשלו — מה לא בוצע, ומה לעשות';

    // ליד יומן האירועים ויומן פעולות הצוות.
    protected static ?int $navigationSort = 92;

    protected static string $view = 'filament.pages.failed-jobs';

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = FailedJob::query()->count();
        } catch (\Throwable) {
            return null; // טבלה חסרה לא תפיל את התפריט
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
            ->query(FailedJob::query())
            ->defaultSort('failed_at', 'desc')
            ->heading('עבודות שנכשלו')
            ->description('כל שורה היא פעולה שהמערכת יצאה לבצע ולא ביצעה. ניסיון חוזר מריץ אותה מחדש מההתחלה; מחיקה מוותרת עליה לתמיד.')
            ->columns([
                Tables\Columns\TextColumn::make('failed_at')
                    ->label('מתי')->dateTime('d/m/Y H:i')->sortable()
                    ->description(fn (FailedJob $record): string => $record->failed_at?->diffForHumans() ?? ''),
                Tables\Columns\TextColumn::make('job')
                    ->label('מה לא בוצע')
                    ->weight('medium')
                    ->wrap()
                    ->state(fn (FailedJob $record): string => $record->label())
                    ->description(fn (FailedJob $record): ?string => $record->meaning()),
                Tables\Columns\TextColumn::make('error')
                    ->label('מה נשבר')
                    ->wrap()
                    ->state(fn (FailedJob $record): string => $record->shortError()),
                // ההבחנה הזו היא כל ההבדל בין "לחץ נסה שוב" ל"אל תטרח": כשל
                // רשת חולף מצליח בניסיון השני, ושגיאת קוד או נתונים תיכשל שוב
                // בדיוק באותו מקום.
                Tables\Columns\TextColumn::make('kind')
                    ->label('סוג')
                    ->badge()
                    ->state(fn (FailedJob $record): string => $record->looksTransient() ? 'תקלה זמנית' : 'דורש בדיקה')
                    ->color(fn (FailedJob $record): string => $record->looksTransient() ? 'warning' : 'danger'),
                Tables\Columns\TextColumn::make('queue')->label('תור')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('retry')
                    ->label('נסה שוב')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('להריץ את העבודה שוב?')
                    ->modalDescription('העבודה תרוץ מחדש מההתחלה. אם הסיבה לכישלון עדיין קיימת — היא תיכשל שוב ותחזור לרשימה.')
                    ->action(fn (FailedJob $record) => $this->retry([$record->uuid])),
                Tables\Actions\Action::make('details')
                    ->label('פרטים')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->modalHeading('פרטי הכישלון')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('סגירה')
                    ->modalContent(fn (FailedJob $record) => view('filament.pages.partials.failed-job-details', [
                        'job' => $record,
                    ])),
                Tables\Actions\Action::make('forget')
                    ->label('מחיקה')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('למחוק את הרשומה?')
                    ->modalDescription('הפעולה שלא בוצעה לא תבוצע לעולם, והרשומה תיעלם. אם זו הייתה חשבונית או תשובה ללקוח — צריך לטפל בזה ידנית לפני המחיקה.')
                    ->action(fn (FailedJob $record) => $this->forget([$record->uuid])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('retrySelected')
                        ->label('ניסיון חוזר לנבחרים')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => $this->retry($records->pluck('uuid')->all())),
                    Tables\Actions\BulkAction::make('forgetSelected')
                        ->label('מחיקת הנבחרים')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('הפעולות שלא בוצעו לא יבוצעו לעולם.')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => $this->forget($records->pluck('uuid')->all())),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('אין עבודות שנכשלו')
            ->emptyStateDescription('כל מה שהמערכת יצאה לעשות — בוצע.');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryAll')
                ->label('ניסיון חוזר להכול')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (): bool => FailedJob::query()->exists())
                ->requiresConfirmation()
                ->modalHeading('להריץ מחדש את כל מה שנכשל?')
                ->modalDescription('מתאים בעיקר אחרי שתוקנה תקלה משותפת — שירות חיצוני שחזר, מפתח שהוחלף. מה שייכשל שוב יחזור לרשימה.')
                ->action(fn () => $this->retry(FailedJob::query()->pluck('uuid')->all())),
        ];
    }

    /**
     * ניסיון חוזר.
     *
     * דרך הפקודה של Laravel ולא בכתיבה ידנית לתור: היא זו שיודעת להחזיר את
     * העבודה לתור הנכון ולמחוק את שורת הכישלון, ועותק משלנו של הלוגיקה הזו
     * יסטה ממנה בשקט בשדרוג הבא.
     *
     * @param  list<string>  $uuids
     */
    private function retry(array $uuids): void
    {
        if ($uuids === []) {
            return;
        }

        foreach ($uuids as $uuid) {
            Artisan::call('queue:retry', ['id' => [$uuid]]);
        }

        AuditLog::record('updated', 'ניסיון חוזר לעבודות שנכשלו: '.count($uuids));

        Notification::make()
            ->title(count($uuids).' עבודות הוחזרו לתור')
            ->body('הן ירוצו מחדש בדקות הקרובות. מה שייכשל שוב יחזור לרשימה הזו.')
            ->success()->send();
    }

    /** @param  list<string>  $uuids */
    private function forget(array $uuids): void
    {
        if ($uuids === []) {
            return;
        }

        $deleted = FailedJob::query()->whereIn('uuid', $uuids)->delete();

        AuditLog::record('deleted', 'מחיקת עבודות שנכשלו: '.$deleted);

        Notification::make()
            ->title($deleted.' רשומות נמחקו')
            ->body('הפעולות שלא בוצעו לא יבוצעו.')
            ->warning()->send();
    }
}
