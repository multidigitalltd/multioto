<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Concerns\PersistsSettings;
use App\Filament\Resources\SiteAgentMessageResource;
use App\Models\Setting;
use App\Services\Ai\GeminiContextCache;
use App\Services\SiteAgent\InboundChannelHealth;
use App\Services\SiteAgent\SiteAgentAssistant;
use App\Services\SiteAgent\SiteAgentPermissions;
use App\Services\SiteAgent\SiteAgentProduct;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
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
use Throwable;

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

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('evaluation')
                ->label('בדיקות הבוט')
                ->icon('heroicon-o-beaker')
                ->url(SiteAgentEvaluation::getUrl()),
        ];
    }

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
        'siteagent.waba_id',
        'siteagent.template_language',
        'siteagent.template_verification',
        'siteagent.template_paused',
        'siteagent.template_resumed',
        'siteagent.template_card_link',
        'siteagent.template_report',
        'siteagent.binding_ttl_minutes',
        'siteagent.instructions',
        'siteagent.persona',
        'siteagent.style',
        'siteagent.work_rules',
        'siteagent.history_messages',
        'siteagent.history_hours',
        'siteagent.history_chars',
        'siteagent.max_turns',
        'siteagent.budget_seconds',
        'siteagent.tool_result_chars',
        'siteagent.cache_ttl_minutes',
        'siteagent.transcript_days',
        'siteagent.failure_alert_email',
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
                'waba_id' => config('siteagent.whatsapp.waba_id'),
                'template_language' => config('siteagent.whatsapp.templates.language'),
                'template_verification' => config('siteagent.whatsapp.templates.verification'),
                'template_verification_copy_button' => (bool) config('siteagent.whatsapp.templates.verification_copy_button'),
                'template_paused' => config('siteagent.whatsapp.templates.service_paused'),
                'template_resumed' => config('siteagent.whatsapp.templates.service_resumed'),
                'template_card_link' => config('siteagent.whatsapp.templates.card_link'),
                'template_report' => config('siteagent.whatsapp.templates.report_ready'),
                'binding_ttl_minutes' => config('siteagent.binding.verification_ttl_minutes'),
                'instructions' => config('siteagent.assistant.instructions'),
                'persona' => config('siteagent.assistant.persona'),
                'style' => config('siteagent.assistant.style'),
                'work_rules' => config('siteagent.assistant.work_rules'),
                'history_messages' => config('siteagent.assistant.history_messages'),
                'history_hours' => config('siteagent.assistant.history_hours'),
                'history_chars' => config('siteagent.assistant.history_chars'),
                'max_turns' => config('siteagent.assistant.max_turns'),
                'budget_seconds' => config('siteagent.assistant.budget_seconds'),
                'tool_result_chars' => config('siteagent.assistant.tool_result_chars'),
                'cache_enabled' => (bool) config('siteagent.assistant.cache.enabled', true),
                'cache_ttl_minutes' => config('siteagent.assistant.cache.ttl_minutes'),
                'transcript_days' => config('siteagent.assistant.transcript_days'),
                'failure_alert_email' => config('siteagent.alerts.failure_email'),
                'allowed' => array_values(array_diff(array_keys(SiteAgentPermissions::GROUPS), app(SiteAgentPermissions::class)->disabled())),
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
                        TextInput::make('siteagent.waba_id')
                            ->label('מזהה חשבון WhatsApp Business (WABA ID)')
                            ->live(onBlur: true)
                            ->autocomplete(false)
                            // ספרות בלבד: הערך מוכנס לנתיב של כתובת ב-Graph API.
                            ->rule('regex:/^\d*$/')
                            ->helperText('לא נדרש לשליחה — נדרש למסך "עלות הודעות הבוט", כי מטא מדווחת הוצאה לפי חשבון ולא לפי מספר. בהגדרות העסק במטא, תחת WhatsApp accounts.'),
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
                        TextInput::make('siteagent.template_card_link')
                            ->label('תבנית "קישור לתשלום"')
                            ->live(onBlur: true)
                            ->autocomplete(false)
                            ->helperText('קטגוריית Utility. שני פרמטרים — customer_name ו-link. הקישור הוא פרמטר ולא טקסט קבוע, כי הוא אינו זהה לכולם: למספר של בעל העסק נשלח דף תשלום, ולמספר שהסוכן נמסר אליו (עובד, סוכנות) נשלח קישור לאזור האישי. בלי התבנית הזאת לא נשלחת הודעת תשלום בוואטסאפ ללקוחות הבוט — רק מייל, והפער מדווח.')
                            ->columnSpanFull(),
                        TextInput::make('siteagent.template_report')
                            ->label('תבנית "הדוח מוכן"')
                            ->live(onBlur: true)
                            ->autocomplete(false)
                            ->helperText('קטגוריית Utility. שלושה פרמטרים: title, domain, summary. נשלחת לדוח קבוע כשבעל האתר לא כתב לבוט ב־24 השעות האחרונות; הגוף צריך להזמין אותו להשיב "דוח" לקבלת הדוח המלא. למשל: "{{title}} של {{domain}}: {{summary}}. השיבו דוח לקבלת הדוח המלא." ריק = דוח כזה לא נשלח.'),
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

                Section::make('מה הבוט רשאי לעשות')
                    ->description('מה שמסומן — הבוט יכול להציע, ומבצע רק אחרי "כן" של בעל האתר. מה שלא מסומן — הבוט לא יציע, יאמר שזה כבוי בחשבון, והצעה שכבר ממתינה תיחסם ב"כן". קריאה ושאלות (הזמנות, לידים, דוחות) תמיד מותרות.')
                    ->schema([
                        CheckboxList::make('siteagent.allowed')
                            ->label('הרשאות')
                            ->options(SiteAgentPermissions::options())
                            ->columns(2)
                            ->bulkToggleable(),
                    ]),

                Section::make('זהות הסוכן וסגנון השיחה')
                    ->description('ההנחיות חלות על בוט ניהול האתר בכל האתרים. הן מכוונות את אופן השיחה והעבודה; הרשאות האתר והצגת השינוי לאישור "כן" נשארות בתוקף.')
                    ->schema([
                        Textarea::make('siteagent.persona')
                            ->label('זהות ותפקיד')
                            ->rule('string')
                            ->rows(5)
                            ->maxLength(SiteAgentAssistant::INSTRUCTIONS_MAX_CHARS)
                            ->placeholder('למשל: אתה עוזר אישי לבעלי אתרים. הסבר בשפה פשוטה ועזור להפוך את הבקשה שלהם לשינוי ברור.')
                            ->helperText('איך הסוכן מציג את תפקידו ולמי הוא עוזר. ריק = התנהגות ברירת המחדל.'),
                        Textarea::make('siteagent.style')
                            ->label('סגנון התשובות')
                            ->rule('string')
                            ->rows(5)
                            ->maxLength(SiteAgentAssistant::INSTRUCTIONS_MAX_CHARS)
                            ->placeholder("למשל:\n• כתוב תשובות קצרות וטבעיות.\n• פנה בלשון רבים.\n• שאל שאלת הבהרה אחת בכל פעם.")
                            ->helperText('טון, אורך התשובות, שפה וצורת הפנייה. ריק = התנהגות ברירת המחדל.'),
                    ]),

                Section::make('הנחיות עבודה עם האתר')
                    ->description('אפשר לקבוע איך לברר פרטים, לקרוא מידע ולהציג הצעות. הסוכן ממשיך להשתמש רק בכלים ובהרשאות הזמינים לאתר, ואינו רשאי לבצע שינוי לפני אישור.')
                    ->schema([
                        Textarea::make('siteagent.work_rules')
                            ->label('כללי עבודה')
                            ->rule('string')
                            ->rows(7)
                            ->maxLength(SiteAgentAssistant::INSTRUCTIONS_MAX_CHARS)
                            ->placeholder("למשל:\n• לפני הצעת מבצע, ברר מתי הוא אמור להסתיים.\n• כשיש כמה עמודים מתאימים, הצג אפשרויות לבחירה.\n• הצג את הטקסט הקיים ואת הטקסט המוצע.")
                            ->helperText('העדפות עבודה קבועות. הן אינן מרחיבות הרשאות או מבטלות אישור ושחזור.'),
                        Textarea::make('siteagent.instructions')
                            ->label('הנחיות נוספות')
                            ->rule('string')
                            ->rows(8)
                            ->maxLength(SiteAgentAssistant::INSTRUCTIONS_MAX_CHARS)
                            ->placeholder("למשל:\n• פתח כל תשובה על מכירות בסכום הכולל, ורק אחר כך פירוט.\n• כשמבקשים \"מבצע\" בלי אחוז — שאל כמה אחוז, אל תציע 10%.\n• פנה בלשון רבים.")
                            ->helperText(fn (): HtmlString => new HtmlString(
                                'כדי לראות מה עבד ומה לא — <a class="underline" href="'.e(SiteAgentMessageResource::getUrl()).'">שיחות הבוט</a>: מה בעלי האתרים כתבו ומה הבוט ענה.'
                            ))
                            ->columnSpanFull(),
                    ]),

                Section::make('זיכרון השיחה')
                    ->description('כמה מהשיחה האחרונה לצרף לבקשה כדי להבין המשכים ותיקונים. ההקשר נשמר בנפרד לכל לקוח ואתר; הרחבתו עשויה להוסיף עלות וזמן תגובה.')
                    ->schema([
                        $this->boundedInteger('history_messages', 'מספר הודעות קודמות', 0, 80, 40)
                            ->helperText('עד 80 הודעות. 0 = ללא היסטוריית שיחה. פרטי האישור הנוכחי עדיין נשמרים.'),
                        $this->boundedInteger('history_hours', 'טווח ההיסטוריה (שעות)', 1, 2160, 168)
                            ->helperText('ברירת המחדל היא שבוע. ניתן לקרוא רק הודעות שעדיין נשמרות לפי תקופת השמירה.'),
                        $this->boundedInteger('history_chars', 'תקרת תווים בהקשר השיחה', 0, 48000, 24000)
                            ->helperText('מגביל את ההיסטוריה ותוצאות הפעולות האחרונות. 0 = ללא היסטוריית שיחה.'),
                        TextInput::make('siteagent.transcript_days')
                            ->label('כמה ימים לשמור את השיחות')
                            ->numeric()
                            ->rule('integer')
                            ->minValue(1)
                            ->maxValue(90)
                            ->placeholder('7')
                            ->live(onBlur: true)
                            ->helperText('השיחות מכילות פרטים של לקוחות הקצה (שמות, טלפונים, הזמנות), ולכן נמחקות אחרי התקופה הזו. 30 יום מספיקים בדרך כלל כדי ללמוד מהן; עד 90.'),
                    ])->columns(2),

                Section::make('התראות על בקשות שלא הובנו')
                    ->description('כשנשלחת לבעל האתר תשובה שהבקשה לא הובנה או שלא הוכנה הצעה מאומתת לביצוע, נשלחת התראה באימייל. היא כוללת את פרטי בעל האתר והאתר, מועד האירוע, עד 40 הודעות אחרונות שנשמרו מאותה שיחה וקישור לשיחה במערכת.')
                    ->schema([
                        TextInput::make('siteagent.failure_alert_email')
                            ->label('כתובת אימייל לקבלת ההתראות')
                            ->email()
                            ->rule('string')
                            ->maxLength(254)
                            ->mutateStateForValidationUsing(fn (mixed $state): mixed => is_string($state) ? trim($state) : $state)
                            ->autocomplete('email')
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->helperText('כתובת אחת, לפי בחירתך. ריק = ברירת המחדל של המערכת; ללא כתובת מוגדרת ההתראות נשלחות למשתמשים עם תפקיד מנהל במערכת, ולא לכלל הצוות או ללקוחות.'),
                    ]),

                Section::make('גבולות העבודה לכל הודעה')
                    ->description('הגבולות מונעים מבקשה אחת להחזיק את השיחה זמן רב. ערכים גבוהים מאפשרים בירור מורכב יותר ועשויים להגדיל את עלות השימוש ואת ההמתנה.')
                    ->schema([
                        $this->boundedInteger('max_turns', 'סבבי עבודה מול ה-AI', 2, 10, 6)
                            ->helperText('סבב כולל תשובת מודל ויכול לכלול בקשות לקריאת מידע מהאתר.'),
                        $this->boundedInteger('budget_seconds', 'חלון זמן לקריאות כלים (שניות)', 30, 240, 240)
                            ->helperText('בין 30 ל־240 שניות. בסיום החלון לא מתחילות קריאות כלים נוספות; קריאה שכבר התחילה והתשובה המסכמת עשויות להסתיים מאוחר יותר.'),
                        $this->boundedInteger('tool_result_chars', 'אורך תשובות כלי קריאה רגילים (תווים)', 1000, 12000, 6000)
                            ->helperText('מגביל את המידע מקריאות רגילות שנשלח למודל. לקריאות מובנות כמו שדות ACF, מבצעי קטגוריה ו־LearnDash יש גבולות נפרדים כדי לשמור על שלמות הנתונים.'),
                    ])->columns(2),

                Section::make('מטמון ההנחיות וקטלוג הכלים')
                    ->description('ב־Gemini ניתן לשמור אצל הספק את ההנחיות הקבועות ואת קטלוג הכלים לשימוש חוזר. נתוני האתר ותשובות לקריאות חיות נבדקים מחדש לפי הבקשה.')
                    ->schema([
                        Toggle::make('siteagent.cache_enabled')
                            ->label('הפעלת מטמון קבוע בין בקשות')
                            ->rule('boolean')
                            ->helperText('המטמון מתחדש בזמן שימוש ונבנה מחדש כשההנחיות, הכלים או ההרשאות משתנים. זמינותו תלויה בספק ובמודל.'),
                        $this->boundedInteger('cache_ttl_minutes', 'תוקף המטמון אצל הספק (דקות)', 15, 1440, 60)
                            ->helperText('תוקף מתחדש, בין 15 דקות ליום. Gemini גובה גם על אחסון המטמון; תוקף ארוך יותר משאיר אותו זמין בין שיחות ומגדיל את עלות האחסון.'),
                        Placeholder::make('assistant_cache_provider')
                            ->label('ספק ומודל שמורים')
                            ->content(fn (): string => (string) config('billing.ai.provider').' · '.(string) config('billing.ai.model'))
                            ->helperText('הספק והמודל נבחרים בהגדרות ה־AI.'),
                        Placeholder::make('assistant_cache_status')
                            ->label('מצב המטמון בשימוש האחרון')
                            ->content(fn (): string => $this->assistantCacheStatus())
                            ->helperText('החיווי מתייחס לקריאה האחרונה בהגדרות השמורות, ואינו מעיד שכבר נוצר מטמון לכל האתרים.'),
                        Actions::make([
                            Action::make('rebuildAssistantCache')
                                ->label('בנייה מחדש בשימוש הבא')
                                ->icon('heroicon-o-arrow-path')
                                ->action(fn () => $this->rebuildAssistantCache()),
                        ])->columnSpanFull(),
                        Placeholder::make('assistant_cache_rebuild_help')
                            ->label('לאחר בקשת בנייה מחדש')
                            ->content('המטמון החדש ייבנה בפנייה הבאה לבוט. עותקים קודמים אצל הספק יפוגו לפי התוקף שלהם; עלות האחסון שלהם נמשכת עד אז. שינויים בטופס נכנסים לתוקף רק לאחר שמירה.')
                            ->columnSpanFull(),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    /** Present enum-only metadata; never expose provider resource names or errors. */
    public function assistantCacheStatus(): string
    {
        abort_unless(static::canAccess(), 403);

        $status = app(GeminiContextCache::class)->status();

        return match ($status['state'] ?? 'idle') {
            'disabled' => 'המטמון כבוי בהגדרות השמורות.',
            'unsupported_provider' => 'המטמון המפורש זמין כרגע עבור Gemini בלבד. הסוכן ממשיך לפעול עם הספק שנבחר.',
            'active' => 'קיים מטמון תקף שנמצא או חודש בפנייה האחרונה. תוקפו מתחדש בזמן שימוש.',
            'fallback' => match ($status['reason'] ?? null) {
                'prefix_too_short' => 'ההנחיות והכלים קצרים מדרישת המינימום של המודל; הבקשה נשלחת ללא מטמון.',
                'model_unsupported' => 'המודל שנבחר אינו תומך במטמון הזה; הבקשה נשלחת ללא מטמון.',
                default => 'המטמון לא היה זמין בקריאה האחרונה; הסוכן ממשיך לשלוח את ההנחיות והכלים ללא מטמון.',
            },
            default => ($status['reason'] ?? null) === 'expired'
                ? 'תוקף המטמון הקודם הסתיים. מטמון חדש ייבנה בשימוש הבא, אם הספק והמודל תומכים בכך.'
                : 'עדיין אין שימוש מתועד במטמון עבור ההגדרות השמורות. הוא ייבנה בפנייה הבאה לבוט, אם הספק והמודל תומכים בכך.',
        };
    }

    /** Invalidate local references only; the next queued request rebuilds them. */
    public function rebuildAssistantCache(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            app(GeminiContextCache::class)->invalidate();
        } catch (Throwable) {
            Notification::make()
                ->title('לא ניתן לבקש בנייה מחדש כרגע')
                ->body('המטמון המקומי אינו זמין. אפשר לנסות שוב; שיחות הבוט ממשיכות לפעול.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('המטמון ייבנה מחדש בשימוש הבא')
            ->body('עותקים קודמים אצל הספק יפוגו לפי התוקף שלהם.')
            ->success()
            ->send();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        // Validated state, not the raw component data: the field rules are the
        // only thing standing between a replayed Livewire request and a code
        // lifetime of minus one, or of a year. Reading $this->data straight
        // through would mean every rule on this form is decoration.
        $state = $this->form->getState();

        Setting::put('siteagent.enabled', data_get($state, 'siteagent.enabled') ? '1' : '0');
        Setting::put('siteagent.cache_enabled', data_get($state, 'siteagent.cache_enabled') ? '1' : '0');
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

        // Stored as what is OFF, so a permission added later starts allowed.
        $allowed = (array) data_get($state, 'siteagent.allowed', []);
        $off = array_values(array_diff(array_keys(SiteAgentPermissions::GROUPS), $allowed));

        if ($off === []) {
            Setting::forget('siteagent.disabled_permissions');
        } else {
            Setting::put('siteagent.disabled_permissions', implode(',', $off));
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

    /** Optional numeric overrides return to their config default when cleared. */
    protected function boundedInteger(string $key, string $label, int $min, int $max, int $default): TextInput
    {
        return TextInput::make('siteagent.'.$key)
            ->label($label)
            ->numeric()
            ->rule('integer')
            ->minValue($min)
            ->maxValue($max)
            ->placeholder((string) $default);
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
