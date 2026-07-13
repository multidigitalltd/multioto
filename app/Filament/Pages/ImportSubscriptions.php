<?php

namespace App\Filament\Pages;

use App\Services\Import\WooSubscriptionImporter;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * ייבוא מנויים מקובץ ייצוא WooCommerce (WXR / XML) — מסך חד-פעמי למיגרציה. עוטף
 * את WooSubscriptionImporter: לכל מנוי נוצר לקוח (לפי אימייל) ומנוי חופשי חודשי
 * עם המחיר לפני מע״מ ותאריך החיוב הבא לפי החידוש הקיים. אין הודעות ללקוחות.
 */
class ImportSubscriptions extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationGroup = 'ניהול';

    protected static ?int $navigationSort = -8;

    protected static ?string $navigationLabel = 'ייבוא מנויים';

    protected static ?string $title = 'ייבוא מנויים מ-WooCommerce';

    protected static string $view = 'filament.pages.import-subscriptions';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(['force' => false]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('העלאת קובץ הייצוא')
                    ->description('קובץ ייצוא של וורדפרס/ווקומרס (WXR / XML) עם המנויים. מבוטלים לא ייובאו; מנויים בהמתנה ייובאו ויסומנו כחוב פתוח.')
                    ->schema([
                        Placeholder::make('info')
                            ->label('מה נשמר')
                            ->content(new HtmlString(
                                '<div style="line-height:1.9">לכל מנוי: לקוח (התאמה/יצירה לפי אימייל) + מנוי חופשי חודשי, מחיר לפני מע״מ, ותאריך החיוב הבא לפי החידוש הקיים.<br>'.
                                '<span style="color:#64748b">המנויים נכנסים כ"ממתין לכרטיס" — לא נגבים עד שמוזן כרטיס, ואז רק בתאריך החידוש. לא נשלחות הודעות ללקוחות בייבוא.</span></div>'
                            )),
                        FileUpload::make('file')
                            ->label('קובץ XML')
                            // No MIME allow-list: WordPress WXR files sniff
                            // inconsistently across browsers/servers and would be
                            // rejected. The importer validates the content and
                            // reports a clear error if the file isn't valid XML.
                            ->maxSize(102400)
                            ->storeFiles(false)
                            ->helperText('קובץ ייצוא WordPress/WooCommerce בפורמט XML (WXR).'),
                        Textarea::make('xml')
                            ->label('או — הדביקו כאן את תוכן ה-XML')
                            ->rows(6)
                            ->helperText('אם העלאת הקובץ נכשלת (מגבלת שרת), פִּתחו את קובץ ה-XML בעורך טקסט, העתיקו הכול (Ctrl+A, Ctrl+C) והדביקו כאן. עוקף את מגבלת ההעלאה.'),
                        Toggle::make('force')
                            ->label('הוסף מנוי גם ללקוח שכבר קיים לו מנוי')
                            ->helperText('בדרך כלל להשאיר כבוי — כך אפשר להריץ שוב את אותו קובץ בלי כפילויות')
                            ->default(false),
                    ]),
            ])
            ->statePath('data');
    }

    public function import(WooSubscriptionImporter $importer): void
    {
        $data = $this->form->getState();
        $force = (bool) ($data['force'] ?? false);

        // Prefer an uploaded file; fall back to pasted XML content (which isn't
        // subject to the server's file-upload size limit).
        $path = $this->uploadedFilePath($data['file'] ?? null);
        $pasted = trim((string) ($data['xml'] ?? ''));

        if ($path !== null) {
            $result = $importer->import($path, $force);
        } elseif ($pasted !== '') {
            $result = $importer->importString($pasted, $force);
        } else {
            Notification::make()->title('העלו קובץ XML או הדביקו את תוכנו')->danger()->send();

            return;
        }

        $this->form->fill(['force' => false]);

        // Nothing imported (unreadable/empty XML, or every row skipped) must read
        // as a failure with the real reason — never a green "done" toast.
        if ($result->created === 0) {
            $reason = $result->skipped[0] ?? 'לא נמצאו מנויים בקובץ. ודאו שזהו קובץ ייצוא WXR/XML של WooCommerce.';

            Notification::make()->title('לא יובאו מנויים')->body($reason)->danger()->persistent()->send();

            return;
        }

        $body = "נוצרו {$result->created} מנויים (לקוחות חדשים: {$result->customersCreated}, קיימים: {$result->customersMatched}).";
        if ($result->debtors !== []) {
            $body .= "\nבחוב (בהמתנה): ".implode(' · ', array_slice($result->debtors, 0, 10));
        }
        if ($result->skipped !== []) {
            $lines = implode("\n", array_slice($result->skipped, 0, 6));
            $body .= "\nדולגו ".count($result->skipped).":\n".$lines;
        }

        Notification::make()->title('הייבוא הושלם')->body($body)->success()->persistent()->send();
    }

    /** Resolve the real filesystem path of the (not-yet-stored) uploaded file. */
    private function uploadedFilePath(mixed $fileState): ?string
    {
        $file = is_array($fileState) ? reset($fileState) : $fileState;

        if ($file && method_exists($file, 'getRealPath')) {
            $path = $file->getRealPath();

            return is_string($path) && is_readable($path) ? $path : null;
        }

        return null;
    }
}
