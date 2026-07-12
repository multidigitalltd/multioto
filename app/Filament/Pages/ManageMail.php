<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Providers\SettingsServiceProvider;
use App\Services\Health\IntegrationHealth;
use App\Services\Mail\PostmarkClient;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * מייל — ניהול מלא של הדואר היוצא: כתובת ושם השולח, כתובת התמיכה (reply-to),
 * מפתחות Postmark (Server + Account) וסנכרון רשימת השולחים המאומתים ישירות
 * מ-Postmark. אפשר לשנות את כתובת השולח מכאן בלי לגעת בקובץ .env.
 *
 * המפתחות נשמרים מוצפנים ולעולם לא מוחזרים לטופס (ריק = לא לשנות). כתובת/שם
 * השולח אינם סוד ולכן מוצגים ומולאים מראש כדי שאפשר יהיה לערוך אותם.
 */
class ManageMail extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'הגדרות';

    protected static ?string $navigationLabel = 'מייל ושולח';

    protected static ?string $title = 'מייל — שולח וחיבור Postmark';

    protected static ?int $navigationSort = 82;

    protected static string $view = 'filament.pages.manage-mail';

    /** Non-secret keys pre-filled from config; secrets are always left blank. */
    private const IDENTITY_KEYS = ['mail.from_address', 'mail.from_name', 'mail.reply_to', 'notifications.team_email', 'notifications.reply_signature', 'notifications.reply_signature_whatsapp'];

    private const SECRET_KEYS = ['postmark.token', 'postmark.account_token'];

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Result of the last "sync senders" run, rendered under the form.
     *
     * @var array{senders: array<int, array{email: string, name: ?string, confirmed: bool}>, domains: array<int, string>}|null
     */
    public ?array $identities = null;

    public function mount(): void
    {
        $this->form->fill([
            'mail.from_address' => config('mail.from.address'),
            'mail.from_name' => config('mail.from.name'),
            'mail.reply_to' => config('billing.email.support_address'),
            'notifications.team_email' => config('billing.notifications.team_email'),
            'notifications.reply_signature' => config('billing.notifications.reply_signature'),
            'notifications.reply_signature_whatsapp' => config('billing.notifications.reply_signature_whatsapp'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('כתובת השולח')
                    ->description('הכתובת והשם שמופיעים ללקוח בכל מייל יוצא. שינוי כאן חל מיד — אין צורך לערוך .env. שימו לב: כתובת השולח חייבת להיות מאומתת ב-Postmark (ראו סנכרון שולחים למטה).')
                    ->schema([
                        TextInput::make('mail.from_address')->label('כתובת שולח')->email()->autocomplete(false)
                            ->placeholder('support@multidigital.co.il'),
                        TextInput::make('mail.from_name')->label('שם שולח')->autocomplete(false)
                            ->placeholder('Multi Digital'),
                        TextInput::make('mail.reply_to')->label('כתובת לתשובות (Reply-To / תמיכה)')->email()->autocomplete(false)
                            ->placeholder('support@multidigital.co.il'),
                        TextInput::make('notifications.team_email')->label('מייל התראות צוות (פניות חדשות)')->email()->autocomplete(false)
                            ->helperText('לכאן יישלחו התראות על פניות חדשות ותגובות. בוואטסאפ ההתראות מגיעות למספר/קבוצת האישורים שהוגדרו ב-WAHA.')
                            ->placeholder('team@multidigital.co.il'),
                    ])->columns(2)
                    ->footerActions([$this->saveAction()]),

                Section::make('חתימת תשובות')
                    ->description('חתימה קבועה שתתווסף אוטומטית לסוף כל תשובה לפנייה. השורה שכתב הנציג נשארת כפי שהיא — החתימה מתווספת רק בשליחה ללקוח. השאירו ריק כדי לא להוסיף חתימה.')
                    ->schema([
                        Textarea::make('notifications.reply_signature')
                            ->label('חתימה למייל')
                            ->rows(4)
                            ->helperText('לדוגמה: שם, תפקיד, טלפון ואתר. תופיע בתחתית כל תשובת מייל ללקוח.')
                            ->placeholder("בברכה,\nצוות Multi Digital\n03-0000000 · multidigital.co.il"),
                        Textarea::make('notifications.reply_signature_whatsapp')
                            ->label('חתימה לוואטסאפ (אופציונלי)')
                            ->rows(3)
                            ->helperText('בדרך כלל קצרה יותר מהמייל, או ריקה.')
                            ->placeholder('— צוות Multi Digital'),
                    ])->columns(2)
                    ->footerActions([$this->saveAction()]),

                Section::make('חיבור Postmark')
                    ->description($this->postmarkDescription())
                    ->schema([
                        TextInput::make('postmark.token')->label('Server Token (שליחה)')
                            ->password()->revealable()->autocomplete('new-password')
                            ->helperText('משמש לשליחת המיילים בפועל. מ-Postmark → Servers → API Tokens.'),
                        TextInput::make('postmark.account_token')->label('Account Token (סנכרון שולחים — אופציונלי)')
                            ->password()->revealable()->autocomplete('new-password')
                            ->helperText('רק לשליפת רשימת השולחים המאומתים. מ-Postmark → Account → API Tokens.'),
                    ])->columns(2)
                    ->footerActions([$this->saveAction(), $this->syncAction(), $this->testEmailAction()]),
            ])
            ->statePath('data');
    }

    /**
     * Persist all mail settings. Non-secret identity fields are written as-is
     * (blank clears back to .env); secrets are only written when filled. When a
     * server token exists we also flip the default mailer to postmark so mail
     * actually routes through it without editing .env.
     */
    public function save(): void
    {
        foreach (self::IDENTITY_KEYS as $key) {
            $value = data_get($this->data, $key);

            if (filled($value)) {
                Setting::put($key, (string) $value);
            } else {
                Setting::forget($key); // fall back to .env / config default
            }
        }

        foreach (self::SECRET_KEYS as $key) {
            $value = data_get($this->data, $key);

            if (filled($value)) {
                Setting::put($key, (string) $value);
            }
        }

        // If Postmark is (now or already) configured, make it the active mailer.
        if (filled(data_get($this->data, 'postmark.token')) || filled(Setting::map()['postmark.token'] ?? null)) {
            Setting::put('mail.mailer', 'postmark');
        }

        // Never echo secrets back to the browser.
        foreach (self::SECRET_KEYS as $key) {
            data_set($this->data, $key, null);
        }

        // Overlay the just-saved values onto config so the health check below sees them.
        (new SettingsServiceProvider(app()))->boot();

        $result = app(IntegrationHealth::class)->check('email');

        $notification = Notification::make()->body($result->message);

        if ($result->ok) {
            $notification->title('הגדרות המייל נשמרו — החיבור ל-Postmark תקין ✓')->success();
        } elseif ($result->configured) {
            $notification->title('הגדרות המייל נשמרו, אך בדיקת החיבור נכשלה')->danger();
        } else {
            $notification->title('הגדרות המייל נשמרו')->warning();
        }

        $notification->persistent()->send();
    }

    /**
     * Pull the verified sender signatures + domains from Postmark's Account API
     * so the operator can see exactly which from-addresses will be accepted.
     * Save the Account Token first.
     */
    public function syncSenders(): void
    {
        // Overlay whatever is currently typed/saved so the client reads it.
        (new SettingsServiceProvider(app()))->boot();

        try {
            $this->identities = app(PostmarkClient::class)->verifiedIdentities();

            $confirmed = collect($this->identities['senders'])->where('confirmed', true)->count();
            $domains = count($this->identities['domains']);

            Notification::make()
                ->title('סנכרון הושלם')
                ->body("נמצאו {$confirmed} כתובות שולח מאומתות ו-{$domains} דומיינים מאומתים. הרשימה מוצגת למטה.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $this->identities = null;
            Notification::make()
                ->title('סנכרון השולחים נכשל')
                ->body(Str::limit(trim($e->getMessage()) ?: class_basename($e), 150))
                ->danger()
                ->send();
        }
    }

    protected function saveAction(): FormAction
    {
        return FormAction::make('save_mail')
            ->label('שמירה')
            ->icon('heroicon-o-check')
            ->action(fn () => $this->save());
    }

    protected function syncAction(): FormAction
    {
        return FormAction::make('sync_senders')
            ->label('סנכרן שולחים מאומתים')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->action(fn () => $this->syncSenders());
    }

    /**
     * Send a real test email through the configured mailer so the operator can
     * confirm delivery before pointing production traffic at it.
     */
    protected function testEmailAction(): FormAction
    {
        return FormAction::make('test_email')
            ->label('שלח מייל בדיקה')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->form([
                TextInput::make('to')->label('לכתובת')->email()->required(),
            ])
            ->action(function (array $data): void {
                try {
                    Mail::raw(
                        'זהו מייל בדיקה ממערכת מולטי דיגיטל. אם קיבלתם אותו — המייל היוצא מוגדר כראוי.',
                        fn ($message) => $message->to($data['to'])->subject('מייל בדיקה — מולטי דיגיטל'),
                    );

                    Notification::make()->title("מייל בדיקה נשלח אל {$data['to']}")->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('שליחת מייל הבדיקה נכשלה')
                        ->body(Str::limit($e->getMessage(), 150))
                        ->danger()
                        ->send();
                }
            });
    }

    protected function postmarkDescription(): string
    {
        $stored = Setting::map();
        $hasToken = filled($stored['postmark.token'] ?? null) || filled(config('services.postmark.token'));

        $base = 'Server Token נדרש לשליחה. Account Token (אופציונלי) מאפשר לסנכרן את רשימת השולחים המאומתים. השאירו ריק כדי לא לשנות מפתח קיים.';

        return $hasToken ? '✓ Server Token שמור במערכת. '.$base : $base;
    }
}
