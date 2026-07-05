<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Providers\SettingsServiceProvider;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
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
        // Start blank — we never echo stored secrets back to the browser.
        $this->form->fill();
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
                    ->schema([
                        TextInput::make('linet.api_key')->label('API Key')->password()->revealable()->autocomplete('new-password'),
                        TextInput::make('linet.api_secret')->label('API Secret')->password()->revealable()->autocomplete('new-password'),
                    ])->columns(2),

                Section::make('FlyWP — אחסון')
                    ->schema([
                        TextInput::make('flywp.api_token')->label('API Token')->password()->revealable()->autocomplete('new-password'),
                        TextInput::make('flywp.server_id')->label('Server ID')->autocomplete(false),
                    ])->columns(2),

                Section::make('WAHA — וואטסאפ')
                    ->schema([
                        TextInput::make('waha.api_key')->label('API Key')->password()->revealable()->autocomplete('new-password'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        // Only persist fields the operator actually filled in — a blank field
        // preserves the current value (env or previously stored).
        foreach (array_keys(SettingsServiceProvider::MAP) as $key) {
            $value = data_get($this->data, $key);

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
