<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Providers\SettingsServiceProvider;
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
 * FlyWP, WAHA) from the UI. Values are stored encrypted (Setting) and overlaid
 * onto config at boot; blank fields fall back to .env. Existing secrets are
 * never rendered back into the form — a blank field means "leave unchanged".
 */
class ManageIntegrations extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'הגדרות';

    protected static ?string $title = 'מפתחות אינטגרציות';

    protected static string $view = 'filament.pages.manage-integrations';

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
                    ->description('מודול אסימונים + מסוף ללא חובת CVV. השאר ריק כדי לא לשנות ערך קיים.')
                    ->schema([
                        TextInput::make('cardcom.terminal_number')->label('מספר מסוף')->autocomplete(false),
                        TextInput::make('cardcom.api_name')->label('API Name')->autocomplete(false),
                        TextInput::make('cardcom.api_password')->label('API Password')->password()->revealable()->autocomplete('new-password'),
                    ])->columns(3),

                Section::make('לינט — חשבוניות')
                    ->description('שלושת הערכים ממסך הגדרות ה-API בלינט: Login ID, Key ו-Company ID. השאירו ריק כדי לא לשנות ערך קיים.')
                    ->schema([
                        TextInput::make('linet.login_id')->label('Login ID')->password()->revealable()->autocomplete('new-password'),
                        TextInput::make('linet.key')->label('Key')->password()->revealable()->autocomplete('new-password'),
                        TextInput::make('linet.company_id')->label('Company ID')->autocomplete(false),
                    ])->columns(3),

                Section::make('FlyWP — אחסון')
                    ->schema([
                        TextInput::make('flywp.api_token')->label('API Token')->password()->revealable()->autocomplete('new-password'),
                        TextInput::make('flywp.server_id')->label('Server ID')->autocomplete(false),
                    ])->columns(2),

                Section::make('WAHA — וואטסאפ')
                    ->schema([
                        TextInput::make('waha.api_key')->label('API Key')->password()->revealable()->autocomplete('new-password'),
                    ]),

                Section::make('Postmark — מייל (יוצא + נכנס)')
                    ->description('Server API Token מ-Postmark. הגדירו MAIL_MAILER=postmark ואת webhook ה-inbound אל /webhooks/email.')
                    ->schema([
                        TextInput::make('postmark.token')->label('Server Token')->password()->revealable()->autocomplete('new-password'),
                    ]),

                Section::make('סוכן AI — סיווג וטיוטות תשובה')
                    ->description('כשמופעל: כל פנייה מסווגת אוטומטית ומוכנה לה טיוטת תשובה — לאישורך בכרטיס לפני שליחה. שום דבר לא נשלח ללקוח אוטומטית.')
                    ->schema([
                        Toggle::make('ai.enabled')->label('הפעל סוכן AI'),
                        TextInput::make('ai.api_key')->label('מפתח Anthropic API')->password()->revealable()->autocomplete('new-password'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        // Only persist fields the operator actually filled in — a blank field
        // preserves the current value (env or previously stored). The AI toggle
        // is a boolean and is always persisted (unchecked = explicitly off).
        foreach (array_keys(SettingsServiceProvider::MAP) as $key) {
            $value = data_get($this->data, $key);

            if ($key === 'ai.enabled') {
                Setting::put($key, $value ? '1' : '0');

                continue;
            }

            if (filled($value)) {
                Setting::put($key, (string) $value);
            }
        }

        $this->form->fill();

        Notification::make()
            ->title('המפתחות נשמרו והוצפנו')
            ->success()
            ->send();
    }
}
