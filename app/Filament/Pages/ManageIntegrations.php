<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Concerns\PersistsSettings;
use App\Models\Setting;
use App\Providers\SettingsServiceProvider;
use App\Services\Health\IntegrationHealth;
use App\Services\Waha\WahaClient;
use App\Support\CardcomWebhook;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Admin settings page for entering integration credentials (Cardcom, Linet,
 * FlyWP, WAHA) from the UI. Values are stored encrypted (Setting) and overlaid
 * onto config at boot; blank fields fall back to .env. Existing secrets are
 * never rendered back into the form — a blank field means "leave unchanged".
 *
 * Saving ONLY persists + confirms; it never calls an external service. Testing
 * the live connection is a separate, deliberate button, so a slow or unreachable
 * provider (e.g. the Linet ERP) can never hang the save or swallow its feedback.
 *
 * Secret inputs use ->password() (masking) but deliberately NOT ->revealable():
 * the reveal toggle swaps the input type via Alpine, which broke Livewire's
 * deferred wire:model sync so typed keys never reached the server on save.
 */
class ManageIntegrations extends Page implements HasForms
{
    use AdminOnly;
    use InteractsWithForms;
    use PersistsSettings;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $cluster = Settings::class;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected static ?string $navigationLabel = 'מפתחות אינטגרציות';

    protected static ?string $title = 'מפתחות אינטגרציות';

    protected static string $view = 'filament.pages.manage-integrations';

    /**
     * Integration group => its setting keys and Hebrew label. This is the
     * per-group allow-list — saveGroup() persists nothing outside it.
     */
    public const GROUPS = [
        'cardcom' => [
            'label' => 'קארדקום',
            'keys' => ['cardcom.terminal_number', 'cardcom.api_name', 'cardcom.api_password'],
        ],
        'linet' => [
            'label' => 'לינט',
            'keys' => ['linet.login_id', 'linet.key', 'linet.company_id', 'linet.doctype', 'linet.doctype_proforma', 'linet.vat_cat_taxable', 'linet.vat_cat_exempt', 'linet.payment_type', 'linet.payment_type_bank_transfer', 'linet.payment_type_standing_order', 'linet.general_item_id', 'linet.income_account_exempt'],
        ],
        'flywp' => [
            'label' => 'FlyWP',
            'keys' => ['flywp.api_token', 'flywp.server_id'],
        ],
        'cloudflare' => [
            'label' => 'Cloudflare',
            'keys' => ['cloudflare.api_token'],
        ],
        'waha' => [
            'label' => 'WAHA',
            'keys' => ['waha.base_url', 'waha.api_key', 'waha.session', 'waha.owner_number'],
        ],
        // Postmark / outbound-mail settings live on their own page (ManageMail),
        // which also manages the sender address and verified-sender sync.
    ];

    /**
     * Integration group => IntegrationHealth check key. Groups missing here have
     * no live connection test (only a save button is shown).
     */
    public const HEALTH_KEYS = [
        'cardcom' => 'cardcom',
        'linet' => 'linet',
        'waha' => 'waha',
    ];

    /**
     * Secret keys are never rendered back into the form (write-only): blank on
     * load, blanked again after save. Everything else (doctype, VAT/payment
     * codes, URLs, ids) is non-secret configuration and IS shown, so the
     * operator can see and verify the current values — and tell that a save
     * actually took effect.
     */
    public const SECRET_KEYS = [
        'cardcom.api_password',
        'linet.login_id',
        'linet.key',
        'flywp.api_token',
        'waha.api_key',
        'cloudflare.api_token',
    ];

    /**
     * Optional non-secret keys that MAY be cleared back to their default. saveGroup
     * only persists filled values (so blanking a secret never wipes it), so a key
     * whose form says "empty = default" must be listed here to actually be
     * removed on clear. Never put a secret or a required credential here.
     */
    public const CLEARABLE_KEYS = [
        'linet.payment_type_bank_transfer',
        'linet.payment_type_standing_order',
    ];

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Inline result banner shown at the top of the page after a save/test. This
     * is a guaranteed, in-page confirmation that does not depend on the toast
     * notification layer rendering — the operator always sees an outcome.
     */
    public ?string $statusText = null;

    /** success | danger | warning — drives the banner colour. */
    public string $statusVariant = 'success';

    public function mount(): void
    {
        // Show current non-secret config (doctype, VAT/payment codes, URLs…) so
        // the operator can see what is configured; secrets stay blank (write-only).
        $this->form->fill($this->currentNonSecretValues());
    }

    /**
     * The current effective value of every non-secret setting, keyed for the
     * form's nested state (e.g. data.linet.doctype). Reads live config, which
     * already reflects stored settings overlaid on .env.
     *
     * @return array<string, mixed>
     */
    protected function currentNonSecretValues(): array
    {
        $values = [];

        foreach (self::GROUPS as $meta) {
            foreach ($meta['keys'] as $key) {
                if (in_array($key, self::SECRET_KEYS, true)) {
                    continue;
                }

                $path = SettingsServiceProvider::MAP[$key] ?? null;

                if ($path !== null) {
                    data_set($values, $key, config($path));
                }
            }
        }

        return $values;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('קארדקום — סליקה')
                    ->description($this->groupDescription('cardcom', 'מודול אסימונים + מסוף ללא חובת CVV. השאר ריק כדי לא לשנות ערך קיים.'))
                    ->schema([
                        TextInput::make('cardcom.terminal_number')->label('מספר מסוף')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('cardcom.api_name')->label('API Name')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('cardcom.api_password')->label('API Password')->password()->live(onBlur: true)->autocomplete('new-password'),
                        Placeholder::make('cardcom_webhook_url')
                            ->label('כתובת Webhook (WebHookUrl)')
                            ->content(fn (): HtmlString => new HtmlString(
                                '<code style="user-select:all;word-break:break-all;font-size:.8rem">'.e(CardcomWebhook::url()).'</code>'
                            ))
                            ->helperText('נשלחת לקארדקום אוטומטית בכל הזנת כרטיס/חיוב — אין חובה להגדיר ידנית. אפשר גם להדביק אותה בקארדקום: ניהול → הגדרות מסוף → "אינדיקטור / Webhook", כגיבוי.')
                            ->columnSpanFull(),
                    ])->columns(3)
                    ->footerActions($this->groupActions('cardcom')),

                Section::make('לינט — חשבוניות')
                    ->description($this->groupDescription('linet', 'שלושת הערכים ממסך הגדרות ה-API בלינט: Login ID, Key ו-Company ID. הקודים למטה (סוג מסמך, קטגוריות מע״מ, אמצעי תשלום) ספציפיים לחשבון שלכם בלינט.'))
                    ->schema([
                        TextInput::make('linet.login_id')->label('Login ID')->password()->live(onBlur: true)->autocomplete('new-password'),
                        TextInput::make('linet.key')->label('Key')->password()->live(onBlur: true)->autocomplete('new-password'),
                        TextInput::make('linet.company_id')->label('Company ID')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.doctype')->label('קוד סוג מסמך (חשבונית מס/קבלה)')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.doctype_proforma')->label('קוד סוג מסמך (חשבונית עסקה)')->helperText('קוד "חשבונית עסקה" (פרו-פורמה) מלינט — מונפק בעת יצירת דרישת תשלום. השאירו ריק כדי לא להנפיק פרו-פורמה.')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.vat_cat_taxable')->label('קוד מע״מ — חייב')->helperText('בלינט: 1 = חייב במע״מ (ברירת המחדל הנכונה כמעט תמיד).')->numeric()->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.vat_cat_exempt')->label('קוד מע״מ — פטור')->helperText('בלינט: 2 = פטור/חו״ל. אלה קודי vat_cat_id — חשבונות ההכנסה (100/102) מוגדרים בנפרד.')->numeric()->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.payment_type')->label('קוד אמצעי תשלום (כרטיס אשראי)')->numeric()->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.payment_type_bank_transfer')->label('קוד אמצעי תשלום (העברה בנקאית)')->helperText('קוד אמצעי התשלום בלינט להעברה בנקאית. ריק = ישתמש בקוד ברירת המחדל.')->numeric()->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.payment_type_standing_order')->label('קוד אמצעי תשלום (הו״ק בנקאית / מס״ב)')->helperText('קוד אמצעי התשלום בלינט להוראת קבע בנקאית (מס״ב). ריק = ישתמש בקוד ברירת המחדל.')->numeric()->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.general_item_id')->label('קוד פריט כללי')->helperText('הפריט בלינט שאליו משויכת כל שורת חשבונית. ברירת מחדל: 1. שנו רק אם הפריט הכללי בחשבונכם שונה.')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.income_account_exempt')->label('חשבון הכנסה — פטור')->helperText('חשבון הכנסות פטורות בלינט (בדרך כלל 102). נדרש רק בחשבוניות ללקוח פטור ממע״מ; שורה חייבת משתמשת בחשבון של הפריט (100).')->numeric()->live(onBlur: true)->autocomplete(false),
                    ])->columns(3)
                    ->footerActions($this->groupActions('linet')),

                Section::make('FlyWP — אחסון')
                    ->description($this->groupDescription('flywp'))
                    ->schema([
                        TextInput::make('flywp.api_token')->label('API Token')->password()->live(onBlur: true)->autocomplete('new-password'),
                        TextInput::make('flywp.server_id')->label('Server ID')->live(onBlur: true)->autocomplete(false),
                    ])->columns(2)
                    ->footerActions($this->groupActions('flywp')),

                Section::make('Cloudflare')
                    ->description('טוקן API של Cloudflare (אופציונלי) — מאפשר למערכת ולסוכן להחריג את כתובת ה-IP של הפאנל ולנקות קאש לאתרים. צרו Custom Token עם ההרשאות: Zone·Read, Firewall Services·Edit (החרגת IP), Cache Purge·Purge (ניקוי קאש). נשמר מוצפן; השאירו ריק כדי לא לשנות.')
                    ->schema([
                        TextInput::make('cloudflare.api_token')->label('API Token')->password()->live(onBlur: true)->autocomplete('new-password')
                            ->helperText('משמש לכל האתרים שמנוהלים תחת חשבון ה-Cloudflare הזה. אפשר גם להזין טוקן חד-פעמי בפעולה עצמה במקום לשמור כאן.'),
                    ])->columns(1)
                    ->footerActions($this->groupActions('cloudflare')),

                Section::make('WAHA — וואטסאפ')
                    ->description($this->groupDescription('waha', 'כתובת שרת WAHA + מפתח. אם WAHA רץ על אותו שרת בקונטיינר נפרד, השתמשו ב-http://host.docker.internal:3000. את חיבור מספר הוואטסאפ עצמו (סריקת QR) עושים בלוח הבקרה של WAHA.'))
                    ->schema([
                        TextInput::make('waha.base_url')->label('כתובת שרת (Base URL)')->placeholder('http://host.docker.internal:3000')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('waha.api_key')->label('API Key')->password()->live(onBlur: true)->autocomplete('new-password'),
                        TextInput::make('waha.session')->label('שם Session')->placeholder('default')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('waha.owner_number')->label('וואטסאפ לאישורים (מספר או קבוצה)')->placeholder('0501234567 או 12036…@g.us')->helperText('בקשות אישור (תשובות AI וכד׳) יישלחו לכאן — עונים "אשר <מספר>" או "דחה <מספר>". אפשר מספר אישי או מזהה קבוצה (@g.us) כדי שכל הצוות יאשר. איתור מזהה קבוצה: צרפו את מספר העסק לקבוצה, שלחו בה הודעה — המזהה יופיע בפנייה שנפתחת. הודעות רגילות בצ׳אט הזה לא פותחות פניות.')->live(onBlur: true)->autocomplete(false),
                    ])->columns(3)
                    ->footerActions($this->groupActions('waha')),
            ])
            ->statePath('data');
    }

    /**
     * Persist only the given integration's keys, then confirm. A blank field
     * preserves the current value (env or previously stored). This method never
     * touches an external service — the connection test is a separate button.
     */
    public function saveGroup(string $group): void
    {
        // Trace that the click reached the server (no values are ever logged) —
        // the page has a history of silent client-side failures.
        Log::info('ManageIntegrations: saveGroup invoked', ['group' => $group]);

        $meta = self::GROUPS[$group] ?? null;

        if ($meta === null) {
            return; // Unknown group — nothing outside the allow-list is ever saved.
        }

        // Force Filament to gather every component's current value into a fresh
        // array; fall back to the raw property if validation on another section
        // would otherwise block the read.
        try {
            $state = $this->form->getState();
        } catch (\Throwable) {
            $state = $this->data;
        }

        foreach ($meta['keys'] as $key) {
            $value = data_get($state, $key) ?? data_get($this->data, $key);

            if ($key === 'ai.enabled') {
                Setting::put($key, $value ? '1' : '0');

                continue;
            }

            // Trim so a stray space/newline pasted with an API key can never
            // silently reject auth (a value that is only whitespace is "blank").
            $value = is_string($value) ? trim($value) : $value;

            if (filled($value)) {
                Setting::put($key, (string) $value);
            } elseif (in_array($key, self::CLEARABLE_KEYS, true)) {
                // An optional code the operator blanked → remove the override so
                // it falls back to the default (RESET_ON_CLEAR reverts config too).
                Setting::forget($key);
            }
        }

        // Overlay the just-saved values onto config so the re-display below (and
        // any connection test) sees them. Guarded so an overlay hiccup can never
        // swallow the save confirmation.
        try {
            $this->refreshConfig();
        } catch (\Throwable) {
            // Values are already persisted; the overlay refresh is best-effort.
        }

        // Blank the secrets (never echoed back), but re-show the just-saved
        // non-secret values so the operator can SEE the save took effect.
        foreach ($meta['keys'] as $key) {
            if ($key === 'ai.enabled') {
                continue;
            }

            if (in_array($key, self::SECRET_KEYS, true)) {
                data_set($this->data, $key, null);

                continue;
            }

            $path = SettingsServiceProvider::MAP[$key] ?? null;
            data_set($this->data, $key, $path !== null ? config($path) : null);
        }

        $this->confirmSaved($meta['label'], $group);
    }

    /**
     * Run the live connection test for a group on demand. Kept separate from the
     * save so a slow/unreachable provider can never hang saving or hide its
     * confirmation.
     */
    public function testGroup(string $group): void
    {
        Log::info('ManageIntegrations: testGroup invoked', ['group' => $group]);

        $label = self::GROUPS[$group]['label'] ?? $group;
        $healthKey = self::HEALTH_KEYS[$group] ?? null;

        if ($healthKey === null) {
            return;
        }

        // Ensure the check reads the latest stored credentials (best-effort).
        try {
            $this->refreshConfig();
        } catch (\Throwable) {
            // Fall through — the check below still runs with current config.
        }

        try {
            $result = app(IntegrationHealth::class)->check($healthKey);
        } catch (\Throwable $e) {
            $this->announce(
                "בדיקת החיבור ל{$label} לא הושלמה",
                Str::limit(trim($e->getMessage()) ?: class_basename($e), 150),
                'warning',
            );

            return;
        }

        if ($result->ok) {
            $this->announce("החיבור ל{$label} תקין ✓", $result->message, 'success');
        } elseif ($result->configured) {
            $this->announce("בדיקת החיבור ל{$label} נכשלה", $result->message, 'danger');
        } else {
            $this->announce("{$label}: המפתחות עדיין לא מלאים", $result->message, 'warning');
        }
    }

    /**
     * Show an outcome both as a toast AND as an inline page banner, so the
     * operator always sees a result even if the toast layer doesn't render.
     */
    protected function announce(string $title, string $body, string $variant): void
    {
        $this->statusText = trim($title.' — '.$body, ' —');
        $this->statusVariant = $variant;

        $notification = Notification::make()->title($title)->body($body);
        match ($variant) {
            'success' => $notification->success(),
            'danger' => $notification->danger(),
            default => $notification->warning(),
        };
        $notification->persistent()->send();
    }

    /**
     * Save confirmation — reports how many of the group's keys are now stored so
     * a partial save is immediately visible. Never makes a network call.
     */
    protected function confirmSaved(string $label, string $group): void
    {
        $stored = Setting::map();
        $keys = collect(self::GROUPS[$group]['keys'])->reject(fn ($k) => $k === 'ai.enabled');
        $savedCount = $keys->filter(fn ($k) => filled($stored[$k] ?? null))->count();

        $body = "שמורים כעת {$savedCount} מתוך {$keys->count()} שדות בקבוצה זו.";

        if (isset(self::HEALTH_KEYS[$group])) {
            $body .= ' לבדיקת החיבור לספק לחצו על "בדיקת חיבור".';
        }

        $this->announce("מפתחות {$label} נשמרו והוצפנו", $body, 'success');
    }

    /**
     * The footer actions for a section: always a save button, plus a connection
     * test for groups that support one.
     *
     * @return array<int, FormAction>
     */
    protected function groupActions(string $group): array
    {
        $actions = [$this->saveAction($group)];

        if (isset(self::HEALTH_KEYS[$group])) {
            $actions[] = $this->testAction($group);
        }

        if ($group === 'waha') {
            $actions[] = FormAction::make('waha_inbound')
                ->label('הפעלת האזנה להודעות נכנסות')
                ->icon('heroicon-o-arrow-down-on-square')
                ->color('warning')
                ->action(fn () => $this->setupWahaInbound());
        }

        return $actions;
    }

    /**
     * Wire WAHA to push incoming WhatsApp messages into this system: ensure a
     * webhook secret exists (auto-generated, stored encrypted) and register our
     * /webhooks/waha endpoint on the WAHA session. Without this, WAHA only
     * SENDS — nothing arrives, so no tickets/acknowledgements can happen.
     */
    public function setupWahaInbound(): void
    {
        Log::info('ManageIntegrations: setupWahaInbound invoked');

        $secret = (string) config('billing.waha.webhook_secret');

        if ($secret === '') {
            $secret = Str::random(40);
            Setting::put('waha.webhook_secret', $secret);
            config(['billing.waha.webhook_secret' => $secret]);
        }

        $webhookUrl = route('webhooks.waha').'?secret='.$secret;

        try {
            app(WahaClient::class)->configureInboundWebhook($webhookUrl);
        } catch (\Throwable $e) {
            $this->announce(
                'הפעלת ההאזנה נכשלה',
                Str::limit(trim($e->getMessage()) ?: class_basename($e), 150),
                'danger',
            );

            return;
        }

        $this->announce(
            'ההאזנה להודעות נכנסות הופעלה ✓',
            'וואטסאפ ידווח כל הודעה נכנסת למערכת — פניות ייפתחו ויאושרו אוטומטית. שלחו הודעת בדיקה למספר העסק כדי לוודא.',
            'success',
        );
    }

    /** The per-section save button. */
    protected function saveAction(string $group): FormAction
    {
        return FormAction::make("save_{$group}")
            ->label('שמירת מפתחות '.self::GROUPS[$group]['label'])
            ->icon('heroicon-o-check')
            ->action(fn () => $this->saveGroup($group));
    }

    /** The per-section "test connection" button (only for testable groups). */
    protected function testAction(string $group): FormAction
    {
        return FormAction::make("test_{$group}")
            ->label('בדיקת חיבור')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->action(fn () => $this->testGroup($group));
    }

    /**
     * Section description, with a "keys already stored" marker so the operator
     * can tell configured integrations apart without ever seeing the values.
     */
    protected function groupDescription(string $group, string $text = ''): string
    {
        $configured = $this->groupConfigured($group);

        if ($configured) {
            return trim('✓ מפתחות שמורים במערכת. '.$text.' השאירו ריק כדי לא לשנות.');
        }

        return trim($text) !== '' ? $text : 'טרם הוזנו מפתחות.';
    }

    protected function groupConfigured(string $group): bool
    {
        static $stored = null;
        $stored ??= Setting::map();

        foreach (self::GROUPS[$group]['keys'] as $key) {
            if ($key !== 'ai.enabled' && filled($stored[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
