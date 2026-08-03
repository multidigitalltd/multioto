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
use App\Providers\SettingsServiceProvider;
use App\Services\Backup\BackupDrill;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\RestoreClaim;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
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
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        'backup.s3.key' => 'filesystems.disks.backups.key',
        'backup.s3.secret' => 'filesystems.disks.backups.secret',
        'backup.s3.region' => 'filesystems.disks.backups.region',
        'backup.s3.bucket' => 'filesystems.disks.backups.bucket',
        'backup.s3.endpoint' => 'filesystems.disks.backups.endpoint',
        'backup.s3.path_style' => 'filesystems.disks.backups.use_path_style_endpoint',
    ];

    /**
     * Write-only: never rendered back into the form, and a blank field means
     * "leave it alone" rather than "delete it".
     *
     * An operator who opens this screen to change the retention window must not
     * lose the destination's credentials by pressing save with the secret field
     * empty — which is exactly what it looks like every time the page loads.
     */
    private const SECRETS = ['backup.s3.key', 'backup.s3.secret'];

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->currentState());
    }

    /**
     * The settings as they are in force right now.
     *
     * Read back from config rather than from what was typed, so that clearing
     * a field and saving shows what the blank fell back to — the destination
     * the nightly job will actually use — instead of leaving the screen
     * describing a configuration that exists nowhere.
     *
     * @return array<string, mixed>
     */
    private function currentState(): array
    {
        return [
            'backup' => [
                'enabled' => (bool) config('backup.enabled'),
                'disk' => (string) config('backup.disk'),
                'path' => (string) config('backup.path'),
                'daily_at' => (string) config('backup.daily_at'),
                'retention_days' => (int) config('backup.retention_days'),
                // The credentials themselves stay blank — see SECRETS.
                's3' => [
                    'key' => '',
                    'secret' => '',
                    'region' => (string) config('filesystems.disks.backups.region'),
                    'bucket' => (string) config('filesystems.disks.backups.bucket'),
                    'endpoint' => (string) config('filesystems.disks.backups.endpoint'),
                    'path_style' => (bool) config('filesystems.disks.backups.use_path_style_endpoint'),
                ],
            ],
        ];
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
                        Select::make('backup.disk')
                            ->label('יעד אחסון')
                            ->required()
                            ->native(false)
                            ->options(fn (): array => $this->diskOptions())
                            ->helperText('"S3 / Cloudflare R2" משתמש בפרטי החיבור שבמקטע הבא. יעד ציבורי או יעד שנמצא על השרת הזה אינו מתקבל.')
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

                Section::make('חיבור ליעד — S3 / Cloudflare R2')
                    ->description('פרטי הגישה לדלי שאליו נכתבים הגיבויים. הם מגדירים את היעד בשם "backups" בלבד — '
                        .'ולא את אחסון הקבצים של המערכת, כדי שהגדרה כאן לא תוכל להזיז את הקבצים הקיימים. '
                        .'הדלי חייב להיות פרטי: הארכיון מכיל פרטי לקוחות, חיובים וחשבוניות.')
                    ->collapsed(fn (): bool => (string) config('backup.disk') !== 'backups')
                    ->schema([
                        $this->secretInput('backup.s3.key', 'Access Key ID'),
                        $this->secretInput('backup.s3.secret', 'Secret Access Key'),
                        TextInput::make('backup.s3.bucket')
                            ->label('שם הדלי (Bucket)')
                            ->live(onBlur: true)
                            ->autocomplete(false),
                        TextInput::make('backup.s3.endpoint')
                            ->label('כתובת השירות (Endpoint)')
                            ->live(onBlur: true)
                            ->autocomplete(false)
                            ->url()
                            ->helperText('ל-Cloudflare R2: https://<ACCOUNT_ID>.r2.cloudflarestorage.com — מתוך R2 ← Use S3 API. '
                                .'ל-AWS השאירו ריק.'),
                        TextInput::make('backup.s3.region')
                            ->label('אזור (Region)')
                            ->live(onBlur: true)
                            ->autocomplete(false)
                            ->helperText('ל-R2 אין אזורים — auto. ל-AWS למשל eu-central-1.'),
                        Toggle::make('backup.s3.path_style')
                            ->label('כתובות בסגנון נתיב (path-style)')
                            ->helperText('נדרש ברוב השירותים תואמי S3, ובכללם R2. ל-AWS עצמה כבו.'),
                    ])->columns(2)
                    ->footerActions([$this->saveAction(), $this->testAction(), $this->forgetCredentialsAction()]),
            ])
            ->statePath('data');
    }

    /**
     * Delete the stored access key and secret.
     *
     * A blank field means "unchanged" — it has to, since these fields are blank
     * on every load — which leaves no way at all to say "no explicit
     * credentials". That is a real destination: an instance role, or anything
     * else in the provider chain, where a key left behind in the database wins
     * silently and the nightly run fails on a permission nobody can account
     * for. Shown only when there is something stored to remove.
     */
    protected function forgetCredentialsAction(): Action
    {
        return Action::make('forgetCredentials')
            ->label('נקה מפתחות שמורים')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('למחוק את מפתחות הגישה השמורים?')
            ->modalDescription('מפתח הגישה והסוד יימחקו מההגדרות, והיעד ישתמש בהרשאות של השרת עצמו או במה שמוגדר בקובץ הסביבה. אם אין כאלה — הגיבוי הלילי ייכשל.')
            ->modalSubmitActionLabel('מחק')
            ->visible(fn (): bool => array_intersect_key(Setting::map(), array_flip(self::SECRETS)) !== [])
            ->action(fn () => $this->forgetCredentials());
    }

    public function forgetCredentials(): void
    {
        foreach (self::SECRETS as $key) {
            Setting::forget($key);
        }

        $this->refreshConfig();

        // The adapter this request already built still holds the old key.
        Storage::forgetDisk('backups');

        $this->form->fill($this->currentState());

        Notification::make()->title('המפתחות השמורים נמחקו')->success()->send();
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

            // A blank secret means "leave it alone". The field is blank every
            // time this page loads, so treating it as a delete would have an
            // operator changing the retention window lose the credentials of
            // the destination — and find out on the next nightly run.
            if ($value === '' && in_array($settingKey, self::SECRETS, true)) {
                continue;
            }

            $value === '' ? Setting::forget($settingKey) : Setting::put($settingKey, $value);
        }

        $this->refreshConfig();

        // The disk was already resolved with the old credentials and is cached
        // for the rest of this request — including by the test button beside
        // this one, which would otherwise report on the settings just replaced.
        Storage::forgetDisk('backups');

        // Refilled from the refreshed config, which does two things at once:
        // the secrets go blank again — the screen never echoes a stored one
        // back, so a filled field always means "this is a new value" — and a
        // field the operator cleared now shows what it fell back to rather
        // than an emptiness that describes no destination at all.
        $this->form->fill($this->currentState());

        Notification::make()->title('ההגדרות נשמרו')->success()->send();
    }

    /**
     * The disks a backup may be written to, as a picker rather than a name to
     * remember. The rule below still refuses a public or on-server disk — the
     * list is convenience, not the guard.
     *
     * @return array<string, string>
     */
    protected function diskOptions(): array
    {
        $labels = [
            'backups' => 'S3 / Cloudflare R2 — יעד גיבוי ייעודי (מוגדר למטה)',
            's3' => 's3 — אחסון S3 של המערכת',
        ];

        return collect(array_keys((array) config('filesystems.disks', [])))
            ->mapWithKeys(fn (string $disk): array => [$disk => $labels[$disk] ?? $disk])
            ->all();
    }

    /**
     * A write-only credential field.
     *
     * The value is never rendered back, so without the hint an operator cannot
     * tell a saved key from an empty one — which reads as "the save did
     * nothing". Masked with CSS on a plain text input rather than type=password
     * for the reason given in ManageIntegrations: browsers autofill the panel
     * password into password inputs on this domain.
     */
    protected function secretInput(string $key, string $label): TextInput
    {
        return TextInput::make($key)
            ->label($label)
            ->live(debounce: 500)
            ->autocomplete('off')
            ->extraInputAttributes([
                'style' => '-webkit-text-security: disc',
                'spellcheck' => 'false',
                'autocapitalize' => 'off',
                'data-1p-ignore' => 'true',
                'data-lpignore' => 'true',
                'data-bwignore' => 'true',
                'data-form-type' => 'other',
            ])
            ->hint(fn (): ?string => filled(Setting::map()[$key] ?? null) ? 'שמור במערכת ✓' : null)
            ->hintColor('success')
            ->placeholder(fn (): ?string => filled(Setting::map()[$key] ?? null)
                ? '•••••••• שמור — ריק = ללא שינוי'
                : null);
    }

    protected function testAction(): Action
    {
        return Action::make('test_backup_destination')
            ->label('בדוק חיבור')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->action(fn () => $this->testDestination());
    }

    /**
     * Write a small file to the destination, read it back, and remove it.
     *
     * Reaching the bucket is not the question — writing to it is. A token with
     * read-only permissions, a bucket in another account and a wrong endpoint
     * all look fine until the first nightly run, which reports its failure to a
     * log nobody is reading at 03:30.
     *
     * What is tested is what is ON SCREEN, not what was last saved: an operator
     * types new credentials and presses this, and a green light earned by the
     * old bucket would be worse than no button at all.
     */
    public function testDestination(): void
    {
        $values = $this->form->getState();

        // A cleared folder falls back to the config-file default, the same
        // reading save() gives it — not to the bucket root. Permissions on
        // these buckets are usually scoped to the prefix, so probing the root
        // would fail on a destination that works, or pass on one that does not
        // cover the prefix the nightly run actually writes to.
        $folder = trim((string) (filled(data_get($values, 'backup.path'))
            ? data_get($values, 'backup.path')
            : SettingsServiceProvider::pristine('backup.path')), '/');

        $probe = ($folder === '' ? '' : $folder.'/').'.multioto-connection-test-'.Str::random(12);
        $content = 'multioto';

        try {
            $storage = $this->destinationDisk($values);

            if ($storage->put($probe, $content) === false) {
                Notification::make()
                    ->title('הכתיבה ליעד נכשלה — בדקו את ההרשאות של המפתח ואת שם הדלי.')
                    ->danger()->send();

                return;
            }

            $read = $storage->get($probe);
            $removed = $storage->delete($probe);

            if ($read !== $content) {
                Notification::make()
                    ->title('הקובץ נכתב אך חזר שונה — היעד אינו מתאים לגיבוי.')
                    ->danger()->send();

                return;
            }

            // The disks are configured with throw => false, so a refused delete
            // comes back as false rather than as an exception. It matters twice
            // over: the test file stays behind, and the same missing permission
            // means old archives will never be pruned either.
            if ($removed === false) {
                Notification::make()
                    ->title('הכתיבה והקריאה עבדו, אך מחיקת קובץ הבדיקה נכשלה')
                    ->body("למפתח אין הרשאת מחיקה — גיבויים ישנים לא יימחקו, וקובץ הבדיקה נשאר ביעד: {$probe}")
                    ->danger()->send();

                return;
            }
        } catch (\Throwable $e) {
            // Trimmed, and the credentials are never part of it: what comes
            // back is the provider's message about the endpoint or the bucket.
            Notification::make()
                ->title('החיבור ליעד נכשל')
                ->body(mb_substr($e->getMessage(), 0, 300))
                ->danger()->send();

            return;
        }

        Notification::make()
            ->title('החיבור ליעד תקין — נכתב קובץ בדיקה, נקרא ונמחק.')
            ->success()->send();
    }

    /**
     * The destination as the form currently describes it.
     *
     * Built ad hoc only when the screen holds something the saved
     * configuration does not. Otherwise the configured disk is used, which is
     * the same object the nightly job will use — testing a copy of it would
     * prove slightly less.
     *
     * @param  array<string, mixed>  $values
     */
    private function destinationDisk(array $values): Filesystem
    {
        $disk = (string) data_get($values, 'backup.disk');

        // The fields below describe an S3 destination. A "backups" disk pointed
        // at something else entirely — a mounted volume, a local folder — is
        // not what they describe, and standing in an S3 client for it would
        // test a destination that does not exist.
        if ($disk !== 'backups' || (string) config('filesystems.disks.backups.driver') !== 's3') {
            return Storage::disk($disk);
        }

        $typed = $this->destinationConfig($values);
        $saved = (array) config('filesystems.disks.backups');

        // Compared through one shape, because an unset value reads as null from
        // config and as an empty string from a form field — and a difference
        // that is only a spelling would send every test to an ad-hoc copy of
        // the disk instead of the disk itself.
        $shape = fn (array $config): array => [
            (string) ($config['key'] ?? ''),
            (string) ($config['secret'] ?? ''),
            (string) ($config['region'] ?? ''),
            (string) ($config['bucket'] ?? ''),
            (string) ($config['endpoint'] ?? ''),
            (bool) ($config['use_path_style_endpoint'] ?? false),
        ];

        return $shape($typed) === $shape($saved) ? Storage::disk($disk) : Storage::build($typed);
    }

    /**
     * The S3 settings the form is showing, with the write-only fields falling
     * back to what is stored: a blank secret means "unchanged", here as well as
     * on save.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function destinationConfig(array $values): array
    {
        $stored = Setting::map();

        // Blank means "unchanged" for a write-only field and "fall back to the
        // config-file default" for the rest — the same reading save() gives
        // them, so the test cannot describe a destination that saving would not
        // produce. config() is the wrong source for that second case: it is
        // holding the stored value that clearing the field is about to forget,
        // so a cleared bucket would be tested against the very destination the
        // operator is moving away from.
        $value = fn (string $key): ?string => match (true) {
            filled(data_get($values, $key)) => (string) data_get($values, $key),
            in_array($key, self::SECRETS, true) => $stored[$key] ?? config(self::KEYS[$key]),
            default => SettingsServiceProvider::pristine(self::KEYS[$key]),
        };

        return [
            'driver' => 's3',
            'key' => $value('backup.s3.key'),
            'secret' => $value('backup.s3.secret'),
            'region' => (string) $value('backup.s3.region'),
            'bucket' => (string) $value('backup.s3.bucket'),
            // Empty means AWS itself; an empty STRING is a broken endpoint.
            'endpoint' => $value('backup.s3.endpoint') ?: null,
            'use_path_style_endpoint' => (bool) data_get($values, 'backup.s3.path_style'),
            'throw' => false,
            'report' => false,
        ];
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
                            'orphan' => Notification::make()
                                ->title('הרשומה נמחקה')
                                ->body('לא ניתן היה להגיע ליעד האחסון. הריצה נכשלה לפני שנכתב ארכיון שלם, '
                                    .'אך אם נשאר שם קובץ חלקי — יש למחוק אותו ידנית.')
                                ->warning()->send(),
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
