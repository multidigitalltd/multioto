<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Concerns\PersistsSettings;
use App\Models\Setting;
use App\Services\SiteAgent\InboundChannelHealth;
use App\Services\SiteAgent\SiteAgentProduct;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * בוט ניהול אתר — הגדרות המוצר.
 *
 * Until this screen existed the product could only be switched on by editing
 * .env and redeploying, which had two consequences worth stating plainly. The
 * obvious one is that turning it on needed a developer. The one that actually
 * hurt is that nobody could SEE it was off: the subscriber list and the journal
 * looked exactly the same whether the number was configured or not, so a
 * customer could be sold a subscription, bound to a site, and left waiting for
 * a verification code that Meta had refused — with every screen in the panel
 * reporting that everything was fine.
 *
 * So the page leads with what is missing, and only then offers the fields.
 *
 * Secrets are write-only, as everywhere else in the settings: never rendered
 * back, and a blank field means "leave unchanged" rather than "erase". The
 * template NAMES are not secret and are shown, because the commonest mistake
 * here is a name that does not match the one Meta approved — and a field that
 * will not show its own value cannot be compared with anything.
 */
class ManageSiteAgent extends Page implements HasForms
{
    use AdminOnly;
    use InteractsWithForms;
    use PersistsSettings;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $cluster = Settings::class;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected static ?string $navigationLabel = 'בוט ניהול אתר';

    protected static ?string $title = 'בוט ניהול אתר — הגדרות המוצר';

    protected static ?int $navigationSort = 85;

    protected static string $view = 'filament.pages.manage-site-agent';

    /**
     * Setting key => whether a blank field erases the override.
     *
     * Secrets are false: the form never shows them, so a blank field is the
     * normal state of a page somebody opened to change something else, and
     * treating that as "erase" would silently disconnect the number.
     */
    private const SECRETS = ['siteagent.token', 'siteagent.app_secret', 'siteagent.verify_token'];

    /** Plain text fields — shown, and cleared when emptied. */
    private const TEXT = [
        'siteagent.phone_number_id',
        'siteagent.template_language',
        'siteagent.template_verification',
        'siteagent.template_paused',
        'siteagent.template_resumed',
        'siteagent.binding_ttl_minutes',
    ];

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        // Nested state (siteagent.* → data['siteagent'][*]), so the fill array
        // must be nested too — a flat key never reaches the field.
        $this->form->fill([
            'siteagent' => [
                'enabled' => (bool) config('siteagent.enabled'),
                'phone_number_id' => config('siteagent.whatsapp.phone_number_id'),
                'template_language' => config('siteagent.whatsapp.templates.language'),
                'template_verification' => config('siteagent.whatsapp.templates.verification'),
                'template_verification_copy_button' => (bool) config('siteagent.whatsapp.templates.verification_copy_button'),
                'template_paused' => config('siteagent.whatsapp.templates.service_paused'),
                'template_resumed' => config('siteagent.whatsapp.templates.service_resumed'),
                'binding_ttl_minutes' => config('siteagent.binding.verification_ttl_minutes'),
            ],
        ]);
    }

    /** What the product still needs, for the summary at the top of the page. */
    public function missing(): array
    {
        return app(SiteAgentProduct::class)->missing();
    }

    /** The address Meta has to deliver to. Read off the route, never retyped. */
    public function webhookUrl(): string
    {
        return route('webhooks.site-agent');
    }

    /**
     * Is anything actually ARRIVING from Meta — and if so, are we refusing it?
     *
     * Every other field on this screen can be filled in correctly and the
     * product can still be silent, because a message has to survive two steps
     * nobody here can see: Meta deciding to deliver it at all, and our own
     * signature check deciding to accept it. Both failures look identical from
     * the outside — the customer writes, and nothing happens.
     *
     * The distinction is the whole value of this block, because the two have
     * completely different fixes:
     *
     *  - **Nothing arrived, ever** → the problem is at Meta: the app is not
     *    published, the `messages` field is not subscribed, or the callback URL
     *    was never verified. Nothing in this panel will change that.
     *  - **Something arrived and was refused** → the app secret here does not
     *    match the one in the Meta app. One field, on this screen.
     *
     * The app secret is the one value nothing else ever exercises: the verify
     * token is proven by Meta's handshake, the number and token by the first
     * send, and the secret only by a real inbound delivery. So it is also the
     * one most likely to be wrong while everything looks right.
     *
     * Read through the same service the hourly watch uses, not re-derived here:
     * a screen that answers "ok" while the alert says "danger" is a screen
     * nobody checks a second time.
     *
     * @return array{accepted: ?Carbon, rejected: ?Carbon, everCarried: bool, verdict: string}
     */
    public function inboundHealth(): array
    {
        return app(InboundChannelHealth::class)->read();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('הפעלה')
                    ->description('כשכבוי — המספר עונה שהשירות אינו זמין. לקוחות שמשלמים עליו לא יוכלו להשתמש בו, ולכן זה מתג שמכבים בכוונה ולא בטעות.')
                    ->schema([
                        Toggle::make('siteagent.enabled')
                            ->label('השירות פעיל')
                            ->helperText('אין לזה השפעה על האתרים עצמם — רק על היכולת לנהל אותם מהוואטסאפ.'),
                    ]),

                Section::make('מספר הוואטסאפ (Meta Cloud API)')
                    ->description('המספר הייעודי של המוצר, מחשבון ה-WhatsApp Business של מטא. לא ה-WAHA של תיבת התמיכה — מוצר בתשלום צריך מספר שלא נופל כשטלפון מאבד את החיבור.')
                    ->schema([
                        TextInput::make('siteagent.phone_number_id')
                            ->label('מזהה המספר (Phone number ID)')
                            ->live(onBlur: true)
                            ->autocomplete(false),
                        $this->secretInput('siteagent.token', 'טוקן קבוע (Permanent token)')
                            ->helperText('של משתמש המערכת שמחזיק במספר.'),
                        $this->secretInput('siteagent.app_secret', 'סוד האפליקציה (App secret)')
                            ->helperText('משמש לאימות החתימה של כל הודעה נכנסת. בלעדיו כל ההודעות נדחות.'),
                        $this->secretInput('siteagent.verify_token', 'טוקן אימות ה-Webhook (Verify token)')
                            ->helperText('מחרוזת שאתם בוחרים, ומזינים גם אצל מטא. היא נשלחת חזרה פעם אחת כשמחברים את הכתובת.'),
                        Placeholder::make('webhook_url')
                            ->label('כתובת ה-Webhook')
                            ->content(fn (): HtmlString => new HtmlString(
                                '<code style="user-select:all;word-break:break-all;font-size:.8rem">'.e($this->webhookUrl()).'</code>'
                            ))
                            ->helperText('הדביקו אצל מטא: Webhooks ← WhatsApp Business Account, והירשמו לשדה messages.')
                            ->columnSpanFull(),
                    ])->columns(2),

                Section::make('תבניות מאושרות')
                    ->description('מטא מתירה טקסט חופשי רק בתוך 24 שעות מהודעה של הלקוח. כל מה שאנחנו מתחילים — קוד אימות, הודעה על מנוי שנפסק — חייב לצאת בתבנית מאושרת, אחרת פשוט יידחה. שמות התבניות כאן חייבים להיות זהים לאלה שאושרו בחשבון.')
                    ->schema([
                        TextInput::make('siteagent.template_language')
                            ->label('שפת התבניות')
                            ->placeholder('he')
                            ->live(onBlur: true)
                            ->autocomplete(false)
                            ->helperText('קוד השפה שבו אושרו התבניות.'),
                        TextInput::make('siteagent.template_verification')
                            ->label('תבנית קוד האימות')
                            ->live(onBlur: true)
                            ->autocomplete(false)
                            ->helperText('קטגוריית Authentication. הגוף שלה נכתב על ידי מטא והמשתנה בו הוא מיקומי — {{1}}, הקוד בן שש הספרות — ולא משתנה בשם. בלעדיה לקוח חדש לא יקבל קוד ולא יוכל להתחיל.'),
                        Toggle::make('siteagent.template_verification_copy_button')
                            ->label('לתבנית האימות יש כפתור העתקת קוד')
                            ->helperText('תבניות אימות של מטא מגיעות בדרך כלל עם כפתור "העתק קוד", שדורש את הקוד גם עליו. כבו אם התבנית אושרה בלי כפתור — שליחת רכיב שאינו קיים בתבנית נדחית.')
                            ->columnSpanFull(),
                        TextInput::make('siteagent.template_paused')
                            ->label('תבנית "המנוי מושהה"')
                            ->live(onBlur: true)
                            ->autocomplete(false)
                            ->helperText('קטגוריית Utility. פרמטר אחד — הדומיין (domain). הקישור לאזור האישי נכתב כטקסט קבוע בגוף התבנית.'),
                        TextInput::make('siteagent.template_resumed')
                            ->label('תבנית "המנוי חזר"')
                            ->live(onBlur: true)
                            ->autocomplete(false)
                            ->helperText('קטגוריית Utility. פרמטר אחד — הדומיין (domain).'),
                        TextInput::make('siteagent.binding_ttl_minutes')
                            ->label('תוקף קוד האימות (דקות)')
                            ->numeric()
                            // שלם, כי מי שסופר את הדקות עושה (int) על הערך:
                            // 10.5 היה נשמר, מוצג כ-10.5, ופועל כ-10 — בדיוק
                            // הפער בין מה שכתוב למה שקורה שהשדה הזה קיים כדי
                            // לסגור.
                            ->rule('integer')
                            ->minValue(1)
                            // 90 ולא יותר, כי זה הגבול של מטא ל-
                            // code_expiration_minutes בתבנית Authentication.
                            // ערך גבוה ממנו הוא ערך שאי אפשר לכתוב בתבנית, כלומר
                            // פער שההסבר כאן דורש לסגור ואי אפשר לסגור אותו.
                            ->maxValue(90)
                            ->placeholder('30')
                            ->live(onBlur: true)
                            ->autocomplete(false)
                            // כאן כי זה לא ערך טכני אלא משפט שהלקוח קורא: תבנית
                            // האימות של מטא כותבת את המספר הזה בכותרת התחתונה
                            // ("התוקף יפוג בעוד X דקות"), ומי שכתב אותו יושב
                            // במסך הזה. פער בין השניים משקר ללקוח לשני הכיוונים.
                            ->helperText('חייב להתאים לתוקף שכתוב בתבנית האימות עצמה אצל מטא (עד 90 דקות — זה הגבול שלה). אם התבנית אומרת ללקוח 10 דקות והערך כאן הוא 30, מי שממתין רבע שעה חושב שהקוד פג ומבקש חדש — והחדש מבטל את הישן שעוד עבד.'),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        // Validated state, not the raw component data: the field rules are the
        // only thing standing between a replayed Livewire request and a code
        // lifetime of minus one, or of a year. Reading $this->data straight
        // through would mean every rule on this form is decoration.
        $state = $this->form->getState();

        Setting::put('siteagent.enabled', data_get($state, 'siteagent.enabled') ? '1' : '0');
        Setting::put(
            'siteagent.template_verification_copy_button',
            data_get($state, 'siteagent.template_verification_copy_button') ? '1' : '0',
        );

        foreach (self::TEXT as $key) {
            $value = data_get($state, $key);

            if (filled($value)) {
                Setting::put($key, trim((string) $value));
            } else {
                Setting::forget($key);
            }
        }

        // Only overwrite a secret when a new one was actually typed. A blank
        // field is how this page looks every time it is opened.
        $rejected = false;

        foreach (self::SECRETS as $key) {
            $value = data_get($state, $key);

            // A "secret" equal to the operator's own panel password is a
            // browser-autofill artefact, not a credential. The integrations
            // screen carries this same guard because the case has actually
            // happened; here it is worse than a broken save — the token field
            // is sent to Meta as a bearer token, so storing it would hand a
            // third party the password to this panel.
            if (filled($value) && is_string($value)
                && (auth()->user()?->enteredOwnPassword($value) ?? false)) {
                $rejected = true;
            } elseif (filled($value)) {
                Setting::put($key, trim((string) $value));
            }

            data_set($this->data, $key, null);
        }

        // Re-apply the overlay so the form — and the readiness summary above it
        // — show exactly what was stored, rather than what was typed.
        $this->refreshConfig();
        $this->mount();

        if ($rejected) {
            Notification::make()
                ->title('שדה לא נשמר — זוהה מילוי אוטומטי של הדפדפן')
                ->body('הערך שהוזן זהה לסיסמת הכניסה שלך לפאנל, כנראה מילוי אוטומטי. נקו את השדה, הדביקו את הערך האמיתי ושמרו שוב. שאר השדות נשמרו.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('הגדרות בוט ניהול האתר נשמרו')
            ->success()
            ->send();
    }

    /** Is there a stored value behind this blank secret field? */
    protected function keyStored(string $key): bool
    {
        return filled(Setting::map()[$key] ?? null);
    }

    /**
     * A masked input that is NOT type=password.
     *
     * Same reasoning as the integrations screen: ->revealable() broke Livewire's
     * wire:model sync, and browsers autofill the operator's saved panel password
     * into password inputs on this domain — a value that never syncs, so the
     * save silently persists nothing.
     */
    protected function secretInput(string $key, string $label): TextInput
    {
        return TextInput::make($key)
            ->label($label)
            // Debounced rather than onBlur: the value syncs moments after a
            // paste, without depending on a blur event firing before the save.
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
            ->hint(fn (): ?string => $this->keyStored($key) ? 'שמור במערכת ✓' : null)
            ->hintColor('success')
            ->placeholder(fn (): ?string => $this->keyStored($key) ? '•••••••• שמור — ריק = ללא שינוי' : null);
    }
}
