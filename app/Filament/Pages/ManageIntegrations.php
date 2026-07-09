<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Providers\SettingsServiceProvider;
use App\Services\Health\IntegrationHealth;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
 */
class ManageIntegrations extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'הגדרות';

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
            'keys' => ['linet.login_id', 'linet.key', 'linet.company_id', 'linet.doctype', 'linet.vat_cat_taxable', 'linet.vat_cat_exempt', 'linet.payment_type'],
        ],
        'flywp' => [
            'label' => 'FlyWP',
            'keys' => ['flywp.api_token', 'flywp.server_id'],
        ],
        'waha' => [
            'label' => 'WAHA',
            'keys' => ['waha.base_url', 'waha.api_key', 'waha.session'],
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

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        // Start blank — we never echo stored secrets back to the browser.
        $this->form->fill();
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
                        TextInput::make('cardcom.api_password')->label('API Password')->password()->revealable()->live(onBlur: true)->autocomplete('new-password'),
                    ])->columns(3)
                    ->footerActions($this->groupActions('cardcom')),

                Section::make('לינט — חשבוניות')
                    ->description($this->groupDescription('linet', 'שלושת הערכים ממסך הגדרות ה-API בלינט: Login ID, Key ו-Company ID. הקודים למטה (סוג מסמך, קטגוריות מע״מ, אמצעי תשלום) ספציפיים לחשבון שלכם בלינט.'))
                    ->schema([
                        TextInput::make('linet.login_id')->label('Login ID')->password()->revealable()->live(onBlur: true)->autocomplete('new-password'),
                        TextInput::make('linet.key')->label('Key')->password()->revealable()->live(onBlur: true)->autocomplete('new-password'),
                        TextInput::make('linet.company_id')->label('Company ID')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.doctype')->label('קוד סוג מסמך (חשבונית מס/קבלה)')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.vat_cat_taxable')->label('קוד מע״מ — חייב')->numeric()->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.vat_cat_exempt')->label('קוד מע״מ — פטור')->numeric()->live(onBlur: true)->autocomplete(false),
                        TextInput::make('linet.payment_type')->label('קוד אמצעי תשלום (כרטיס אשראי)')->numeric()->live(onBlur: true)->autocomplete(false),
                    ])->columns(3)
                    ->footerActions($this->groupActions('linet')),

                Section::make('FlyWP — אחסון')
                    ->description($this->groupDescription('flywp'))
                    ->schema([
                        TextInput::make('flywp.api_token')->label('API Token')->password()->revealable()->live(onBlur: true)->autocomplete('new-password'),
                        TextInput::make('flywp.server_id')->label('Server ID')->live(onBlur: true)->autocomplete(false),
                    ])->columns(2)
                    ->footerActions($this->groupActions('flywp')),

                Section::make('WAHA — וואטסאפ')
                    ->description($this->groupDescription('waha', 'כתובת שרת WAHA + מפתח. אם WAHA רץ על אותו שרת בקונטיינר נפרד, השתמשו ב-http://host.docker.internal:3000. את חיבור מספר הוואטסאפ עצמו (סריקת QR) עושים בלוח הבקרה של WAHA.'))
                    ->schema([
                        TextInput::make('waha.base_url')->label('כתובת שרת (Base URL)')->placeholder('http://host.docker.internal:3000')->live(onBlur: true)->autocomplete(false),
                        TextInput::make('waha.api_key')->label('API Key')->password()->revealable()->live(onBlur: true)->autocomplete('new-password'),
                        TextInput::make('waha.session')->label('שם Session')->placeholder('default')->live(onBlur: true)->autocomplete(false),
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

            if (filled($value)) {
                Setting::put($key, (string) $value);
            }
        }

        // Clear the group's inputs (secrets are never echoed back) while
        // preserving everything the operator typed in other sections.
        foreach ($meta['keys'] as $key) {
            if ($key !== 'ai.enabled') {
                data_set($this->data, $key, null);
            }
        }

        // Overlay the just-saved values onto config so a subsequent connection
        // test (or anything else this request touches) sees them.
        (new SettingsServiceProvider(app()))->boot();

        $this->confirmSaved($meta['label'], $group);
    }

    /**
     * Run the live connection test for a group on demand. Kept separate from the
     * save so a slow/unreachable provider can never hang saving or hide its
     * confirmation.
     */
    public function testGroup(string $group): void
    {
        $label = self::GROUPS[$group]['label'] ?? $group;
        $healthKey = self::HEALTH_KEYS[$group] ?? null;

        if ($healthKey === null) {
            return;
        }

        // Ensure the check reads the latest stored credentials.
        (new SettingsServiceProvider(app()))->boot();

        try {
            $result = app(IntegrationHealth::class)->check($healthKey);
        } catch (\Throwable $e) {
            Notification::make()
                ->title("בדיקת החיבור ל{$label} לא הושלמה")
                ->body(Str::limit(trim($e->getMessage()) ?: class_basename($e), 150))
                ->warning()->persistent()->send();

            return;
        }

        $notification = Notification::make()->body($result->message);

        if ($result->ok) {
            $notification->title("החיבור ל{$label} תקין ✓")->success();
        } elseif ($result->configured) {
            $notification->title("בדיקת החיבור ל{$label} נכשלה")->danger();
        } else {
            $notification->title("{$label}: המפתחות עדיין לא מלאים")->warning();
        }

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

        Notification::make()
            ->title("מפתחות {$label} נשמרו והוצפנו")
            ->body($body)
            ->success()->send();
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

        return $actions;
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
