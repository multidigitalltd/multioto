<?php

namespace App\Filament\Pages;

use App\Enums\BackupStatus;
use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Concerns\PersistsSettings;
use App\Jobs\DrillBackupJob;
use App\Jobs\ImportBackupsJob;
use App\Jobs\RestoreBackupJob;
use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\Setting;
use App\Services\Backup\BackupDrill;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\RestoreClaim;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * גיבוי ושחזור — עותק לילי של כל נתוני המערכת ליעד אחסון חיצוני, ואפשרות
 * לשחזר ממנו.
 *
 * הגיבוי נשמר מחוץ לשרת בכוונה: גיבוי ששוכן על אותה מכונה שהוא אמור להגן
 * עליה אינו גיבוי. הארכיון מכיל פרטי לקוחות, ולכן היעד חייב להיות פרטי.
 *
 * שחזור מחליף כל שורה בבסיס הנתונים, ולכן הוא דורש הקלדת מילת אישור ונחסם
 * כשמבנה בסיס הנתונים השתנה מאז הגיבוי.
 */
class ManageBackups extends Page implements HasForms, HasTable
{
    use AdminOnly;
    use InteractsWithForms;
    use InteractsWithTable;
    use PersistsSettings;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';

    protected static ?string $cluster = Settings::class;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected static ?string $navigationLabel = 'גיבוי ושחזור';

    protected static ?string $title = 'גיבוי ושחזור — עותק חיצוני של כל הנתונים';

    protected static ?int $navigationSort = 89;

    protected static string $view = 'filament.pages.manage-backups';

    /** Setting key => config path. */
    private const KEYS = [
        'backup.enabled' => 'backup.enabled',
        'backup.disk' => 'backup.disk',
        'backup.path' => 'backup.path',
        'backup.daily_at' => 'backup.daily_at',
        'backup.retention_days' => 'backup.retention_days',
    ];

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'backup' => [
                'enabled' => (bool) config('backup.enabled'),
                'disk' => (string) config('backup.disk'),
                'path' => (string) config('backup.path'),
                'daily_at' => (string) config('backup.daily_at'),
                'retention_days' => (int) config('backup.retention_days'),
            ],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('הגדרות הגיבוי')
                    ->description('הגיבוי נשמר ביעד אחסון חיצוני. היעד חייב להיות פרטי — הארכיון מכיל פרטי לקוחות, חיובים וחשבוניות.')
                    ->schema([
                        Toggle::make('backup.enabled')
                            ->label('גיבוי אוטומטי יומי פעיל')
                            ->helperText('כשהוא כבוי הגיבוי הלילי אינו רץ. "גבה עכשיו" ימשיך לעבוד — לחיצה מפורשת אינה מבוטלת בשקט.'),
                        TextInput::make('backup.disk')
                            ->label('יעד אחסון (disk)')
                            ->required()
                            ->helperText('שם ה-disk מתוך config/filesystems.php. ברירת המחדל s3. יעד ציבורי אינו מתקבל.')
                            ->rule(fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                                $disks = (array) config('filesystems.disks', []);

                                if (! array_key_exists((string) $value, $disks)) {
                                    $fail('לא קיים יעד אחסון בשם הזה.');

                                    return;
                                }

                                // A public disk is served over the web. The
                                // archive holds every customer record, and its
                                // name is predictable — that is a data leak,
                                // not a configuration preference.
                                if (($disks[(string) $value]['visibility'] ?? null) === 'public') {
                                    $fail('היעד הזה ציבורי — הגיבוי מכיל פרטי לקוחות וחייב יעד פרטי.');

                                    return;
                                }

                                // A disk we back up sits on this server, so it
                                // is not disaster recovery — and it would end
                                // up archiving its own previous archives.
                                if (array_key_exists((string) $value, (array) config('backup.files', []))) {
                                    $fail('היעד הזה הוא אחד מהמקורות שמגובים — צריך יעד חיצוני, מחוץ לשרת.');

                                    return;
                                }

                                // A plain folder on this machine is not a
                                // backup destination: losing the server would
                                // take the data and every recovery point.
                                if (($disks[(string) $value]['driver'] ?? null) === 'local'
                                    && ! (bool) config('backup.allow_local_destination', false)) {
                                    $fail('היעד הזה נמצא על אותו שרת — צריך יעד חיצוני (S3 או תואם). '
                                        .'אם זו תיקייה המחוברת לאחסון חיצוני, הפעילו BACKUP_ALLOW_LOCAL_DESTINATION.');
                                }
                            }),
                        TextInput::make('backup.path')
                            ->label('תיקייה ביעד')
                            ->helperText('לדוגמה multioto-backups.'),
                        TextInput::make('backup.daily_at')
                            ->label('שעת הגיבוי היומי')
                            ->required()
                            ->rule('date_format:H:i')
                            ->helperText('בפורמט 24 שעות, למשל 03:30.'),
                        TextInput::make('backup.retention_days')
                            ->label('שמירת גיבויים (ימים)')
                            ->numeric()->minValue(1)->maxValue(3650)->required()
                            ->helperText('גיבויים ישנים יותר נמחקים — אך תמיד נשמרים לפחות '
                                .(int) config('backup.keep_at_least').' גיבויים אחרונים.'),
                    ])->columns(2)
                    ->footerActions([$this->saveAction()]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $values = $this->form->getState();

        foreach (self::KEYS as $settingKey => $_) {
            $value = data_get($values, $settingKey);

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $value = is_string($value) ? trim($value) : (string) $value;

            $value === '' ? Setting::forget($settingKey) : Setting::put($settingKey, $value);
        }

        $this->refreshConfig();

        Notification::make()->title('ההגדרות נשמרו')->success()->send();
    }

    protected function saveAction(): Action
    {
        return Action::make('save_backup_settings')
            ->label('שמירה')
            ->icon('heroicon-o-check')
            ->action(fn () => $this->save());
    }

    /**
     * Shown at the top of the screen when no backup has completed for too long.
     *
     * The nightly alert comes from the scheduler, and a scheduler that has
     * stopped cannot report itself — so the same question is asked here, where
     * nothing but a person opening the page is required.
     */
    public function staleWarning(): ?string
    {
        return app(BackupRunner::class)->staleWarning();
    }

    /**
     * Did a restore die while it was replacing uploaded files?
     *
     * The rows roll themselves back; the files are left as the archive wrote
     * them, with the originals staged and a journal saying where. Nobody is
     * running to finish that, so the screen is where it gets said.
     */
    public function interruptedFiles(): bool
    {
        return app(BackupRestorer::class)->hasInterruptedFiles();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('הגיבויים שנשמרו')
            ->description('מהחדש לישן. "אוטומטי" הוא הגיבוי הלילי; השאר נלקחו בלחיצה.')
            ->query(Backup::query()->with('user'))
            ->defaultSort('id', 'desc')
            ->poll('15s')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('מתי')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('סטטוס')->badge(),
                Tables\Columns\TextColumn::make('source')
                    ->label('מקור')
                    ->state(fn (Backup $r): string => $r->isAutomatic() ? 'אוטומטי' : ($r->user?->name ?? 'ידני')),
                Tables\Columns\TextColumn::make('size_bytes')
                    ->label('גודל')
                    ->state(fn (Backup $r): string => $this->humanSize($r->size_bytes)),
                Tables\Columns\TextColumn::make('contents')
                    ->label('תוכן')
                    ->state(fn (Backup $r): string => $r->status === BackupStatus::Completed
                        ? number_format($r->rowCount()).' שורות · '.number_format($r->fileCount()).' קבצים'
                        : '—'),
                // A backup that left files out is not a plain success: the rows
                // pointing at them ARE backed up, so a restore would recreate
                // references to files no archive ever held.
                Tables\Columns\TextColumn::make('skipped')
                    ->label('קבצים שלא גובו')->badge()->color('warning')
                    ->state(fn (Backup $r): ?string => ($n = count((array) ($r->manifest['skipped_files'] ?? []))) > 0
                        ? $n.' קבצים' : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('error')
                    ->label('שגיאה')->wrap()->placeholder('—')->toggleable(),
                // The restore's own outcome. Without it a failed restore reads
                // as a healthy backup row — even when the database was already
                // replaced and the files never came back.
                Tables\Columns\TextColumn::make('restore_status')
                    ->label('שחזור')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('restore_error')
                    ->label('שגיאת שחזור')->wrap()->placeholder('—')
                    ->visible(fn (): bool => Backup::query()->whereNotNull('restore_error')->exists()),
                Tables\Columns\TextColumn::make('restored_at')
                    ->label('שוחזר')->dateTime('d/m/Y H:i')->placeholder('—')->toggleable(),

                // A backup nobody has opened is a hope. This says when somebody
                // last read this one through, and what they found.
                Tables\Columns\TextColumn::make('drilled_at')
                    ->label('נבדק')->dateTime('d/m/Y H:i')->placeholder('—')
                    ->description(fn (Backup $record): ?string => match (true) {
                        $record->drilled_at === null => null,
                        ($record->drill_report['problems'] ?? []) === [] => 'עבר',
                        default => 'נמצאו '.count($record->drill_report['problems']).' בעיות',
                    })
                    ->color(fn (Backup $record): ?string => match (true) {
                        $record->drilled_at === null => null,
                        ($record->drill_report['problems'] ?? []) === [] => 'success',
                        default => 'danger',
                    })
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('runNow')
                    ->label('גבה עכשיו')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->requiresConfirmation()
                    ->modalHeading('לבצע גיבוי עכשיו?')
                    ->modalDescription('הגיבוי רץ ברקע ויופיע ברשימה כשיסתיים.')
                    ->action(function (): void {
                        try {
                            RunBackupJob::dispatch(auth()->id());
                        } catch (\Throwable $e) {
                            // Thrown before the runner exists, so nothing else
                            // will record this run: without a row here the
                            // request leaves no trace at all in the history.
                            app(BackupRunner::class)->recordUnstarted(
                                auth()->id(),
                                'לא ניתן היה להעביר את הגיבוי לתור: '.mb_substr($e->getMessage(), 0, 300),
                            );

                            Notification::make()->title('הגיבוי לא התחיל — התור אינו זמין.')->danger()->send();

                            return;
                        }

                        Notification::make()->title('הגיבוי התחיל — יופיע ברשימה בסיום.')->success()->send();
                    }),

                // Reading an archive is the only thing that proves it IS one.
                // Everything else on this screen reports on the write.
                Tables\Actions\Action::make('drill')
                    ->label('בדוק שחזור')
                    ->icon('heroicon-o-shield-check')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('לבדוק את הגיבוי האחרון?')
                    ->modalDescription('הגיבוי האחרון יורד מהיעד ונקרא במלואו — שום דבר לא משוחזר ושום דבר לא נמחק. התוצאה תופיע בעמודה "נבדק".')
                    ->action(function (): void {
                        // Said here rather than left to the job: a screen that
                        // reports "the check started" when there is nothing to
                        // check is how somebody comes to believe an archive was
                        // examined.
                        if (! app(BackupDrill::class)->latest()) {
                            Notification::make()->title('אין עדיין גיבוי שהושלם — אין מה לבדוק.')->warning()->send();

                            return;
                        }

                        try {
                            DrillBackupJob::dispatch(manual: true);
                        } catch (\Throwable $e) {
                            Notification::make()->title('הבדיקה לא התחילה — התור אינו זמין.')->danger()->send();

                            return;
                        }

                        Notification::make()->title('הבדיקה התחילה — התוצאה תופיע ברשימה.')->success()->send();
                    }),

                // The list lives in the database, and the database is exactly
                // what a disaster takes. After a rebuild the bucket still holds
                // the archives and this screen would show nothing — this is how
                // they are found again.
                Tables\Actions\Action::make('import')
                    ->label('חפש גיבויים ביעד')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('לסרוק את יעד האחסון?')
                    ->modalDescription('כל ארכיון שנמצא ביעד ואינו ברשימה יתווסף אליה. שימושי אחרי התקנה מחדש — רשימת הגיבויים עצמה אינה נשמרת בתוך הגיבוי.')
                    ->action(function (): void {
                        // In the background: the scan reads every unknown
                        // archive to get its manifest, and after a rebuild that
                        // can be a whole bucket — far past the time limit a web
                        // request is given.
                        try {
                            ImportBackupsJob::dispatch();
                        } catch (\Throwable) {
                            Notification::make()->title('הסריקה לא התחילה — התור אינו זמין.')->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('הסריקה התחילה — הגיבויים שיימצאו יופיעו ברשימה.')
                            ->body('סריקה של ארכיונים גדולים עשויה לקחת כמה דקות.')
                            ->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label('הורדה')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    // The check runs while the table is being drawn, so a
                    // destination that cannot answer must not take the whole
                    // screen down with it — the operator may have come here to
                    // fix exactly that.
                    ->visible(fn (Backup $r): bool => $r->status === BackupStatus::Completed
                        && rescue(fn (): bool => $r->existsOnDisk(), false, report: false))
                    // Streamed through the panel behind admin auth — never a
                    // public link to a file full of customer details.
                    ->action(fn (Backup $r) => Storage::disk($r->disk)->download($r->path)),

                Tables\Actions\Action::make('restore')
                    ->label('שחזור')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (Backup $r): bool => $r->status === BackupStatus::Completed)
                    ->disabled(fn (Backup $r): bool => app(BackupRestorer::class)->blockedReason($r) !== null)
                    ->tooltip(fn (Backup $r): ?string => app(BackupRestorer::class)->blockedReason($r))
                    ->modalHeading('שחזור מגיבוי — פעולה בלתי הפיכה')
                    ->modalDescription('כל הנתונים הנוכחיים יימחקו ויוחלפו בנתוני הגיבוי: לקוחות, מנויים, חיובים ופניות, וכן הקבצים שהיו בגיבוי. קבצים שהועלו אחרי הגיבוי יישארו על הדיסק (בלי רשומה שמצביעה עליהם). מומלץ לבצע גיבוי עכשיו לפני השחזור.')
                    ->form([
                        TextInput::make('confirm')
                            ->label('להמשך, הקלידו: '.config('backup.restore_confirmation'))
                            ->required()
                            ->rule(fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                                if (trim((string) $value) !== (string) config('backup.restore_confirmation')) {
                                    $fail('מילת האישור אינה נכונה.');
                                }
                            }),
                    ])
                    ->action(function (Backup $record): void {
                        if (($reason = app(BackupRestorer::class)->blockedReason($record)) !== null) {
                            Notification::make()->title($reason)->danger()->send();

                            return;
                        }

                        // Claimed atomically, and by the same rule the console
                        // command uses: two admins clicking at once, or one
                        // clicking twice during queue latency, would otherwise
                        // enqueue the same restore twice — and the second would
                        // finish after the first and put the same old snapshot
                        // back, wiping everything accepted in between. Also
                        // marks the row busy before dispatch, so it cannot be
                        // deleted while the job waits in the queue.
                        $attempt = app(RestoreClaim::class)->take($record);

                        if ($attempt === null) {
                            Notification::make()->title('שחזור מהגיבוי הזה כבר רץ.')->warning()->send();

                            return;
                        }

                        try {
                            RestoreBackupJob::dispatch($record->id, $attempt);
                        } catch (\Throwable $e) {
                            // NOT marked failed: a queue can accept the payload
                            // and still fail to say so, and "failed" would let
                            // somebody start a second restore on top of a first
                            // one that is already running. The claim is left
                            // standing and expires on its own — the one outcome
                            // that is safe whichever way the dispatch went.
                            $record->update([
                                'restore_error' => 'לא ברור אם השחזור הועבר לתור: '.mb_substr($e->getMessage(), 0, 300)
                                    .' — אם לא יתחיל, אפשר יהיה לנסות שוב בעוד '
                                    .max(1, (int) config('backup.restore_claim_minutes', 30)).' דקות.',
                            ]);

                            Notification::make()
                                ->title('לא ברור אם השחזור הועבר לתור — אין להתחיל שחזור נוסף כרגע.')
                                ->danger()->persistent()->send();

                            return;
                        }

                        Notification::make()
                            ->title('השחזור הועבר לתור — ייתכן שתידרשו להתחבר מחדש בסיומו.')
                            // Said plainly, because the recommended procedure is
                            // to stop the worker before restoring — and with it
                            // stopped nothing here will run the job. The command
                            // takes over this very claim and does the work in
                            // the foreground.
                            ->body("אם עובד התור מושבת (כפי שמומלץ לפני שחזור), הריצו בשרת: php artisan backup:restore {$record->id}")
                            ->warning()->persistent()->send();
                    }),

                // The list of payments and documents a restore left without a
                // row. Kept on this row because the backup table is the one a
                // restore does not replace — and useless if nobody can read it.
                Tables\Actions\Action::make('reconcile')
                    ->label('רשימת ההתאמות')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('warning')
                    // Also shown for a scan that ran out of room before it
                    // finished: an empty list there means "not checked", and
                    // hiding it would read as "nothing to do".
                    ->visible(fn (Backup $r): bool => filled($r->restore_report['items'] ?? null)
                        || ($r->restore_report['truncated'] ?? false))
                    ->modalHeading('רשומות שאינן בגיבוי — לבדוק מול קארדקום ולינט')
                    ->modalDescription(fn (Backup $r): string => 'אלה חיובים ומסמכים שהיו במערכת לפני השחזור ואינם בגיבוי. '
                        .'קארדקום ולינט עדיין מכירים אותם, ולכן צריך להשוות מולם לפני שממשיכים לגבות. '
                        .'סה"כ: '.(int) ($r->restore_report['count'] ?? 0)
                        .(($r->restore_report['truncated'] ?? false)
                            ? ' — הבדיקה נעצרה בתקרה ולא הגיעה לרשומות האחרונות, שהן דווקא החשודות ביותר.'
                            : ''))
                    ->modalContent(fn (Backup $r) => view('filament.pages.partials.restore-report', [
                        'items' => (array) ($r->restore_report['items'] ?? []),
                        'truncated' => (bool) ($r->restore_report['truncated'] ?? false),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('סגירה'),

                Tables\Actions\Action::make('delete')
                    ->label('מחיקה')->icon('heroicon-o-trash')->color('gray')
                    // Not while something is using it: deleting mid-backup
                    // leaves an archive with no history row, and deleting
                    // mid-restore removes the record of a restore that is
                    // still replacing production data.
                    ->hidden(fn (Backup $r): bool => $r->status === BackupStatus::Running
                        || ($r->restore_status === BackupStatus::Running && ! $r->restoreClaimExpired()))
                    ->requiresConfirmation()
                    ->modalHeading('למחוק את הגיבוי?')
                    ->modalDescription('הארכיון יימחק מיעד האחסון ולא ניתן יהיה לשחזר ממנו.')
                    ->action(function (Backup $record): void {
                        // Hiding the button is not enough: the confirmation
                        // dialog can sit open while somebody else starts a
                        // restore from this very archive.
                        match (app(BackupRunner::class)->deleteRecord($record->id)) {
                            'ok' => Notification::make()->title('הגיבוי נמחק')->success()->send(),
                            'gone' => Notification::make()->title('הגיבוי כבר נמחק.')->warning()->send(),
                            'busy' => Notification::make()
                                ->title('לא ניתן למחוק — גיבוי או שחזור פועלים על הרשומה הזו כרגע.')
                                ->warning()->send(),
                            'journal' => Notification::make()
                                ->title('לא ניתן למחוק — שחזור שנקטע תלוי ברשומה הזו.')
                                ->body('הריצו php artisan backup:recover-files, ואחריו אפשר יהיה למחוק.')
                                ->warning()->persistent()->send(),
                            default => Notification::make()
                                ->title('לא ניתן היה למחוק את קובץ הגיבוי מהיעד — הרשומה נשמרה.')
                                ->danger()->send(),
                        };
                    }),
            ])
            ->emptyStateHeading('עדיין אין גיבויים')
            ->emptyStateDescription('הגיבוי הלילי ירוץ בשעה שנקבעה, או אפשר ללחוץ "גבה עכשיו". אחרי התקנה מחדש — "חפש גיבויים ביעד" יאתר ארכיונים קיימים.');
    }

    private function humanSize(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return '—';
    }
}
