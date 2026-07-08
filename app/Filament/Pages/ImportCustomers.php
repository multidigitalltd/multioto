<?php

namespace App\Filament\Pages;

use App\Jobs\SendCardCaptureLinkJob;
use App\Services\Import\CustomerImporter;
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
 * ייבוא לקוחות מקובץ CSV — מסך אחד שקולט אקסל/CSV ויוצר לקוחות + אתרים + מנויים
 * בכמות. כל מנוי נוצר ללא כרטיס (Trialing) ומופעל כשהלקוח מזין כרטיס דרך הקישור.
 *
 * The heavy per-row work is bounded (validated, one transaction per row) and
 * card-capture invites are queued, never sent inline.
 */
class ImportCustomers extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = -9;

    protected static ?string $navigationLabel = 'ייבוא לקוחות';

    protected static ?string $title = 'ייבוא לקוחות מקובץ';

    protected static string $view = 'filament.pages.import-customers';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(['skip_duplicates' => true, 'send_card_link' => false]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('העלאת הקובץ')
                    ->description('קובץ CSV עם כותרות בשורה הראשונה. אפשר לייצא מאקסל או מווקומרס. הורידו קובץ לדוגמה מהכפתור למעלה.')
                    ->schema([
                        Placeholder::make('columns')
                            ->label('עמודות נתמכות')
                            ->content(new HtmlString(
                                '<div style="line-height:1.9">'.
                                '<strong>חובה:</strong> שם · <strong>מומלץ:</strong> אימייל, טלפון, דומיין, תוכנית<br>'.
                                '<strong>אופציונלי:</strong> ח.פ / עוסק, סוג עסק, פטור ממע״מ, מחיר (₪)<br>'.
                                '<span style="color:#64748b">התוכנית מזוהה לפי השם. אם אין עמודת "תוכנית" — יילקח המסלול הפעיל הראשון.</span>'.
                                '</div>'
                            )),
                        FileUpload::make('file')
                            ->label('קובץ CSV')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                            ->maxSize(4096)
                            ->storeFiles(false)
                            ->required(),
                    ]),

                Section::make('אפשרויות')
                    ->schema([
                        Toggle::make('skip_duplicates')
                            ->label('דלג על לקוחות שכבר קיימים')
                            ->helperText('זיהוי לפי אימייל או טלפון זהים')
                            ->default(true),
                        Toggle::make('send_card_link')
                            ->label('שלח לכל לקוח מיובא קישור להזנת כרטיס')
                            ->helperText('שימו לב: שולח הודעה לכל לקוח בקובץ. מומלץ להשאיר כבוי בייבוא ראשוני')
                            ->default(false),
                    ])->columns(2),
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
                    // BOM so Excel opens Hebrew correctly.
                    echo "\u{FEFF}";
                    echo "שם,אימייל,טלפון,ח.פ / עוסק,סוג עסק,פטור ממע\"מ,דומיין,תוכנית,מחיר\n";
                    echo "ישראל ישראלי,israel@example.co.il,0501234567,514999999,עוסק מורשה,לא,example.co.il,אחזקה עסקית,\n";
                    echo "חברת דוגמה בע\"מ,office@demo.co.il,0507654321,515888888,חברה,לא,demo.co.il,אחזקה פרימיום,450\n";
                }, 'customers-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])),
        ];
    }

    public function import(CustomerImporter $importer): void
    {
        $data = $this->form->getState();

        $rows = $this->parseCsv($this->uploadedFilePath($data['file'] ?? null));

        if ($rows === null) {
            Notification::make()->title('לא ניתן לקרוא את הקובץ')->danger()->send();

            return;
        }

        $result = $importer->import($rows, (bool) ($data['skip_duplicates'] ?? true));

        if ($data['send_card_link'] ?? false) {
            foreach ($result->subscriptionIds as $subscriptionId) {
                SendCardCaptureLinkJob::dispatch($subscriptionId);
            }
        }

        $this->form->fill(['skip_duplicates' => true, 'send_card_link' => false]);

        $body = "יובאו {$result->importedCount()} לקוחות.";
        if ($result->skippedCount() > 0) {
            $lines = collect($result->skipped)
                ->take(8)
                ->map(fn ($s) => "שורה {$s['line']}: {$s['reason']}")
                ->implode("\n");
            $body .= "\nדולגו {$result->skippedCount()}:\n".$lines;
        }

        Notification::make()
            ->title('הייבוא הושלם')
            ->body($body)
            ->success()
            ->persistent()
            ->send();
    }

    /**
     * Resolve the real filesystem path of the (not-yet-stored) uploaded CSV.
     */
    private function uploadedFilePath(mixed $fileState): ?string
    {
        $file = is_array($fileState) ? reset($fileState) : $fileState;

        if ($file && method_exists($file, 'getRealPath')) {
            $path = $file->getRealPath();

            return is_string($path) && is_readable($path) ? $path : null;
        }

        return null;
    }

    /**
     * Parse a CSV into associative rows keyed by their header. Returns null if
     * the file can't be opened or has no header row.
     *
     * @return iterable<int, array<string, string>>|null
     */
    private function parseCsv(?string $path): ?iterable
    {
        if ($path === null || ! ($handle = fopen($path, 'r'))) {
            return null;
        }

        $headers = fgetcsv($handle);
        if ($headers === false || $headers === null) {
            fclose($handle);

            return null;
        }

        // Strip a UTF-8 BOM from the first header cell (Excel adds it).
        $headers[0] = ltrim((string) $headers[0], "\u{FEFF}");
        $count = count($headers);

        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if ($cells === [null] || count(array_filter($cells, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // blank line
            }

            $cells = array_pad(array_slice($cells, 0, $count), $count, '');
            $rows[] = array_combine($headers, $cells);
        }

        fclose($handle);

        return $rows;
    }
}
