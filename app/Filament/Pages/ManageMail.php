<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Concerns\PersistsSettings;
use App\Models\Setting;
use App\Services\Health\IntegrationHealth;
use App\Services\Mail\PostmarkClient;
use App\Support\EmailList;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
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
    use AdminOnly;
    use InteractsWithForms;
    use PersistsSettings;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'הגדרות';

    protected static ?string $navigationLabel = 'מייל ושולח';

    protected static ?string $title = 'מייל — שולח וחיבור Postmark';

    protected static ?int $navigationSort = 82;

    protected static string $view = 'filament.pages.manage-mail';

    /** Non-secret keys pre-filled from config; secrets are always left blank. */
    private const IDENTITY_KEYS = ['mail.from_address', 'mail.from_name', 'mail.reply_to', 'notifications.team_email', 'notifications.reply_signature', 'notifications.reply_signature_whatsapp', 'branding.logo_path', 'branding.email_footer'];

    private const SECRET_KEYS = ['postmark.token', 'postmark.account_token', 'email.webhook_secret'];

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
        // Fields use nested state (mail.* → data['mail'][*]); build the fill
        // array nested via data_set so values actually reach the fields.
        $values = [];
        data_set($values, 'mail.from_address', config('mail.from.address'));
        data_set($values, 'mail.from_name', config('mail.from.name'));
        data_set($values, 'mail.reply_to', config('billing.email.support_address'));
        data_set($values, 'notifications.team_email', config('billing.notifications.team_email'));
        data_set($values, 'notifications.reply_signature', config('billing.notifications.reply_signature'));
        data_set($values, 'notifications.reply_signature_whatsapp', config('billing.notifications.reply_signature_whatsapp'));
        data_set($values, 'branding.logo_path', config('billing.branding.logo_path'));
        data_set($values, 'branding.email_footer', config('billing.branding.email_footer'));

        $this->form->fill($values);
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
                        TextInput::make('notifications.team_email')->label('מיילים להתראות צוות (אפשר כמה, מופרדים בפסיק)')->autocomplete(false)
                            // Accept several addresses, comma/;-separated — validate each part.
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                if (blank($value)) {
                                    return; // empty clears back to the default — allowed
                                }
                                if (($bad = EmailList::invalid($value)) !== []) {
                                    $fail('כתובות לא תקינות: '.implode(', ', $bad));
                                } elseif (EmailList::parse($value) === []) {
                                    // Non-blank but no valid address (e.g. only separators)
                                    // — would silently disable alerts. Reject it.
                                    $fail('יש להזין לפחות כתובת מייל תקינה אחת, או להשאיר ריק.');
                                }
                            })
                            ->helperText('לכאן יישלחו התראות על פניות חדשות ותגובות. אפשר כמה כתובות מופרדות בפסיק. בוואטסאפ ההתראות מגיעות למספר/קבוצת האישורים שהוגדרו ב-WAHA.')
                            ->placeholder('team@multidigital.co.il, riki@m-d.co.il'),
                    ])->columns(2)
                    ->footerActions([$this->saveAction()]),

                Section::make('לוגו וכותרת תחתונה למיילים')
                    ->description('הלוגו מופיע בראש כל מייל ללקוח (במקום שם המערכת), וגם בטופס ההרשמה, עמוד התודה וכרטיס הלקוח החתום. הכותרת התחתונה מופיעה בתחתית כל מייל (במקום "כל הזכויות שמורות").')
                    ->schema([
                        FileUpload::make('branding.logo_path')
                            ->label('קובץ לוגו')
                            ->image()
                            // Raster only: an SVG is scriptable and the logo is served
                            // inline from a public URL and embedded in emails, so an
                            // SVG logo would be a stored-XSS vector.
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])
                            ->disk('public')
                            ->directory('branding')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->imageEditor()
                            ->helperText('PNG / JPG / WEBP / GIF עד 2MB. החלפת הקובץ מעדכנת את הלוגו בכל המקומות, כולל ראש המיילים.'),
                        Textarea::make('branding.email_footer')
                            ->label('כותרת תחתונה למיילים (Footer)')
                            ->rows(3)
                            ->helperText('מופיעה בתחתית כל מייל ללקוח. השאירו ריק לברירת מחדל עם שם העסק והשנה.')
                            ->placeholder("Multi Digital · multidigital.co.il · 03-0000000\nרח׳ הדוגמה 1, תל אביב"),
                    ])
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

                Section::make('קליטת מיילים נכנסים (Inbound)')
                    ->description('כדי שמייל שנשלח לכתובת התמיכה יפתח פנייה אוטומטית, Postmark צריך לדחוף (Inbound Webhook) את ההודעות לכתובת שלמטה. הגדירו סוד, שמרו, והדביקו את הכתובת המלאה ב-Postmark.')
                    ->schema([
                        TextInput::make('email.webhook_secret')
                            ->label('סוד ה-Webhook הנכנס')
                            ->password()->revealable()->autocomplete('new-password')
                            ->helperText('מחרוזת סודית כלשהי (למשל 32 תווים אקראיים). חייבת להיות זהה למה שמופיע בכתובת שתדביקו ב-Postmark. אם ריק — כל המיילים הנכנסים נדחים.'),
                        Placeholder::make('inbound_url')
                            ->label('כתובת ה-Webhook להדבקה ב-Postmark')
                            ->content(fn (): string => $this->inboundWebhookUrl()),
                    ])->columns(1)
                    ->footerActions([$this->saveAction()]),
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
        // Read the DEHYDRATED form state, not the raw component state: this stores
        // any uploaded logo to the disk and returns its path string. Reading
        // $this->data directly would hand back the file component's array state
        // and blow up on the (string) cast ("Array to string conversion").
        $data = $this->form->getState();

        foreach (self::IDENTITY_KEYS as $key) {
            $value = data_get($data, $key);

            if (filled($value)) {
                Setting::put($key, (string) $value);
            } else {
                Setting::forget($key); // fall back to .env / config default
            }
        }

        foreach (self::SECRET_KEYS as $key) {
            $value = data_get($data, $key);

            if (filled($value)) {
                Setting::put($key, (string) $value);
            }
        }

        // If Postmark is (now or already) configured, make it the active mailer.
        if (filled(data_get($data, 'postmark.token')) || filled(Setting::map()['postmark.token'] ?? null)) {
            Setting::put('mail.mailer', 'postmark');
        }

        // Never echo secrets back to the browser.
        foreach (self::SECRET_KEYS as $key) {
            data_set($this->data, $key, null);
        }

        // Overlay the just-saved values onto config so the health check below sees them.
        $this->refreshConfig();

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
        $this->refreshConfig();

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

    /**
     * The exact inbound-webhook URL to paste into Postmark, with the configured
     * secret already embedded. Shown only in the team-only admin panel.
     */
    protected function inboundWebhookUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/').'/webhooks/email';
        $secret = (string) config('billing.email.webhook_secret');

        return $secret === ''
            ? $base.'?secret=…  (הגדירו סוד למעלה, שמרו, והכתובת המלאה תופיע כאן)'
            // URL-encode: a secret with reserved characters (& # + space) would
            // otherwise reach Postmark as a different value and 403 forever.
            : $base.'?secret='.rawurlencode($secret);
    }
}
