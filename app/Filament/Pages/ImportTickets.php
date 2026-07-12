<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ParsesCsvUpload;
use App\Services\Import\TicketImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * ייבוא כרטיסים מהמערכת הישנה — קולט CSV ויוצר את הפניות ההיסטוריות תוך שמירת
 * מספרי הכרטיסים המקוריים, וממשיך את הספירה מהמספר הגבוה ביותר שיובא. הכרטיסים
 * משויכים ללקוח לפי כתובת המייל.
 */
class ImportTickets extends Page implements HasForms
{
    use InteractsWithForms;
    use ParsesCsvUpload;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'תמיכה';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'ייבוא כרטיסים';

    protected static ?string $title = 'ייבוא כרטיסים מהמערכת הישנה';

    protected static string $view = 'filament.pages.import-tickets';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(['skip_duplicates' => true]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('העלאת הקובץ')
                    ->description('קובץ CSV עם כותרות בשורה הראשונה. מספרי הכרטיסים המקוריים נשמרים, וכרטיסים חדשים ימשיכו את הספירה מהמספר הגבוה ביותר.')
                    ->schema([
                        Placeholder::make('columns')
                            ->label('עמודות נתמכות')
                            ->content(new HtmlString(
                                '<div style="line-height:1.9">'.
                                '<strong>חובה:</strong> ID (מספר הכרטיס) · <strong>מומלץ:</strong> נושא, כתובת דוא״ל, Status, עדיפות, Date Closed, תוכן<br>'.
                                '<span style="color:#64748b">כרטיס משויך ללקוח לפי כתובת המייל אם קיים לקוח כזה. אם יש עמודת "תוכן"/גוף הפנייה — היא נשמרת כהודעה הראשונה בכרטיס; אחרת נשמרת כותרת הפנייה. לא נשלחות הודעות ללקוחות בייבוא.</span>'.
                                '</div>'
                            )),
                        FileUpload::make('file')
                            ->label('קובץ CSV')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                            ->maxSize(8192)
                            ->storeFiles(false)
                            ->required(),
                    ]),

                Section::make('אפשרויות')
                    ->schema([
                        Toggle::make('skip_duplicates')
                            ->label('דלג על כרטיסים שכבר קיימים (לפי מספר)')
                            ->helperText('מומלץ להשאיר דלוק — כך אפשר לייבא שוב את אותו קובץ בלי ליצור כפילויות')
                            ->default(true),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')
                ->label('הורדת קובץ לדוגמה')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => response()->streamDownload(function () {
                    echo "\u{FEFF}"; // BOM so Excel opens Hebrew correctly.
                    echo "ID,נושא,\"כתובת דוא\"\"ל\",Status,עדיפות,\"Date Closed\",תוכן\n";
                    echo "1366,\"אתר שארפן\",info@example.co.il,\"טופל / הושלם\",\"רגיל / לטיפול בהקדם\",\"2023-08-27 18:51:08\",\"תוכן הפנייה אם קיים בייצוא\"\n";
                }, 'tickets-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])),

            Action::make('deleteImported')
                ->label('מחק ייבוא קודם')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('מחיקת כל הכרטיסים שיובאו')
                ->modalDescription('פעולה זו מוחקת את כל הכרטיסים שיובאו מהמערכת הישנה (וההודעות שלהם) — כדי לאפשר ייבוא נקי מחדש. כרטיסים שנוצרו במערכת עצמה לא ייפגעו. לא נשלחות הודעות ללקוחות.')
                ->modalSubmitActionLabel('כן, מחק את המיובאים')
                ->action(function (TicketImporter $importer) {
                    $deleted = $importer->deleteImported();

                    Notification::make()
                        ->title('הייבוא הקודם נמחק')
                        ->body("נמחקו {$deleted} כרטיסים שיובאו. אפשר לייבא מחדש.")
                        ->success()
                        ->send();
                }),
        ];
    }

    public function import(TicketImporter $importer): void
    {
        $data = $this->form->getState();

        $rows = $this->parseCsv($this->uploadedFilePath($data['file'] ?? null));

        if ($rows === null) {
            Notification::make()->title('לא ניתן לקרוא את הקובץ')->danger()->send();

            return;
        }

        $result = $importer->import($rows, (bool) ($data['skip_duplicates'] ?? true));

        $this->form->fill(['skip_duplicates' => true]);

        $body = "יובאו {$result->importedCount()} כרטיסים ({$result->matched} שויכו ללקוח).";
        if ($result->maxId) {
            $body .= "\nהמספר הבא לכרטיס חדש: ".($result->maxId + 1).'.';
        }
        if ($result->skippedCount() > 0) {
            $lines = collect($result->skipped)->take(8)
                ->map(fn ($s) => "שורה {$s['line']}: {$s['reason']}")
                ->implode("\n");
            $body .= "\nדולגו {$result->skippedCount()}:\n".$lines;
        }

        Notification::make()->title('הייבוא הושלם')->body($body)->success()->persistent()->send();
    }
}
