<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Admin settings page for entering integration credentials (Cardcom, Linet,
 * FlyWP, WAHA, Postmark, AI) from the UI. Values are stored encrypted (Setting)
 * and overlaid onto config at boot; blank fields fall back to .env. Existing
 * secrets are never rendered back into the form — a blank field means "leave
 * unchanged". Each integration has its own save button, so updating one
 * provider never touches the others.
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
            'keys' => ['linet.login_id', 'linet.key', 'linet.company_id'],
        ],
        'flywp' => [
            'label' => 'FlyWP',
            'keys' => ['flywp.api_token', 'flywp.server_id'],
        ],
        'waha' => [
            'label' => 'WAHA',
            'keys' => ['waha.api_key'],
        ],
        'postmark' => [
            'label' => 'Postmark',
            'keys' => ['postmark.token'],
        ],
        'ai' => [
            'label' => 'סוכן AI',
            'keys' => ['ai.enabled', 'ai.api_key'],
        ],
    ];

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        // Start blank — we never echo stored secrets back to the browser —
        // except the AI toggle, whose current on/off state we do want to show.
        $this->form->fill(['ai.enabled' => (bool) config('billing.ai.enabled')]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('קארדקום — סליקה')
                    ->description($this->groupDescription('cardcom', 'מודול אסימונים + מסוף ללא חובת CVV. השאר ריק כדי לא לשנות ערך קיים.'))
                    ->schema([
                        TextInput::make('cardcom.terminal_number')->label('מספר מסוף')->autocomplete(false),
                        TextInput::make('cardcom.api_name')->label('API Name')->autocomplete(false),
                        TextInput::make('cardcom.api_password')->label('API Password')->password()->revealable()->autocomplete('new-password'),
                    ])->columns(3)
                    ->footerActions([$this->saveAction('cardcom')]),

                Section::make('לינט — חשבוניות')
                    ->description($this->groupDescription('linet', 'שלושת הערכים ממסך הגדרות ה-API בלינט: Login ID, Key ו-Company ID.'))
                    ->schema([
                        TextInput::make('linet.login_id')->label('Login ID')->password()->revealable()->autocomplete('new-password'),
                        TextInput::make('linet.key')->label('Key')->password()->revealable()->autocomplete('new-password'),
                        TextInput::make('linet.company_id')->label('Company ID')->autocomplete(false),
                    ])->columns(3)
                    ->footerActions([$this->saveAction('linet')]),

                Section::make('FlyWP — אחסון')
                    ->description($this->groupDescription('flywp'))
                    ->schema([
                        TextInput::make('flywp.api_token')->label('API Token')->password()->revealable()->autocomplete('new-password'),
                        TextInput::make('flywp.server_id')->label('Server ID')->autocomplete(false),
                    ])->columns(2)
                    ->footerActions([$this->saveAction('flywp')]),

                Section::make('WAHA — וואטסאפ')
                    ->description($this->groupDescription('waha'))
                    ->schema([
                        TextInput::make('waha.api_key')->label('API Key')->password()->revealable()->autocomplete('new-password'),
                    ])
                    ->footerActions([$this->saveAction('waha')]),

                Section::make('Postmark — מייל (יוצא + נכנס)')
                    ->description($this->groupDescription('postmark', 'Server API Token מ-Postmark. הגדירו MAIL_MAILER=postmark ואת webhook ה-inbound אל /webhooks/email.'))
                    ->schema([
                        TextInput::make('postmark.token')->label('Server Token')->password()->revealable()->autocomplete('new-password'),
                    ])
                    ->footerActions([$this->saveAction('postmark')]),

                Section::make('סוכן AI — סיווג וטיוטות תשובה')
                    ->description($this->groupDescription('ai', 'כשמופעל: כל פנייה מסווגת אוטומטית ומוכנה לה טיוטת תשובה — לאישורך לפני שליחה. שום דבר לא נשלח ללקוח אוטומטית.'))
                    ->schema([
                        Toggle::make('ai.enabled')->label('הפעל סוכן AI'),
                        TextInput::make('ai.api_key')->label('מפתח Anthropic API')->password()->revealable()->autocomplete('new-password'),
                    ])
                    ->footerActions([$this->saveAction('ai')]),
            ])
            ->statePath('data');
    }

    /**
     * Persist only the given integration's keys. A blank field preserves the
     * current value (env or previously stored); the AI toggle is a boolean and
     * is always persisted (unchecked = explicitly off).
     */
    public function saveGroup(string $group): void
    {
        $meta = self::GROUPS[$group] ?? null;

        if ($meta === null) {
            return; // Unknown group — nothing outside the allow-list is ever saved.
        }

        foreach ($meta['keys'] as $key) {
            $value = data_get($this->data, $key);

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

        Notification::make()
            ->title("מפתחות {$meta['label']} נשמרו והוצפנו")
            ->body('אפשר לאמת אותם במסך "בדיקת חיבורים".')
            ->success()
            ->send();
    }

    /** The per-section save button. */
    protected function saveAction(string $group): FormAction
    {
        return FormAction::make("save_{$group}")
            ->label('שמירת מפתחות '.self::GROUPS[$group]['label'])
            ->icon('heroicon-o-check')
            ->action(fn () => $this->saveGroup($group));
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
