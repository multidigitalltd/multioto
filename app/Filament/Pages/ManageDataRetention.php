<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Concerns\PersistsSettings;
use App\Models\Setting;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;

/**
 * שמירת נתונים — כמה זמן נשמרת ההיסטוריה של הניטור, ה-webhooks, ההתראות
 * ולוג המערכת לפני שהיא נמחקת אוטומטית בניקוי הלילי. הכול נשמר כ-settings
 * ומחליף את ברירת המחדל שב-.env בלי לגעת בקוד.
 */
class ManageDataRetention extends Page implements HasForms
{
    use AdminOnly;
    use InteractsWithForms;
    use PersistsSettings;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $cluster = Settings::class;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected static ?string $navigationLabel = 'שמירת נתונים';

    protected static ?string $title = 'שמירת נתונים — משך שמירת היסטוריה';

    protected static ?int $navigationSort = 88;

    protected static string $view = 'filament.pages.manage-data-retention';

    /** Setting key => config path for the retention windows (all integer days). */
    private const KEYS = [
        'system.monitor_check_retention_days' => 'billing.system.monitor_check_retention_days',
        'system.webhook_retention_days' => 'billing.system.webhook_retention_days',
        'system.notification_retention_days' => 'billing.system.notification_retention_days',
        'system.log_retention_days' => 'billing.system.log_retention_days',
    ];

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $values = [];

        foreach (self::KEYS as $key => $configPath) {
            data_set($values, $key, (int) config($configPath));
        }

        $this->form->fill($values);
    }

    public function form(Form $form): Form
    {
        // The monitoring report summarises this window, so probe history must
        // outlive it — used as the floor for the monitor-checks retention.
        $reportWindow = (int) config('billing.monitoring.monthly_report.window_days', 30);

        return $form
            ->schema([
                Section::make('משך שמירת היסטוריה (בימים)')
                    ->description('רשומות ישנות מהמשך שנקבע נמחקות אוטומטית בניקוי לילי, כדי שמסד הנתונים יישאר רזה ומהיר. ערכים גבוהים יותר = יותר היסטוריה זמינה אך מסד נתונים גדול יותר.')
                    ->schema([
                        TextInput::make('system.monitor_check_retention_days')
                            ->label('היסטוריית בדיקות ניטור')
                            ->numeric()->required()
                            ->minValue($reportWindow)->maxValue(3650)
                            ->suffix('ימים')
                            ->helperText("כל בדיקת אתר נשמרת כשורה — הטבלה הגדולה ביותר. חייב להיות לפחות {$reportWindow} ימים (חלון הדוח החודשי ללקוח)."),
                        TextInput::make('system.webhook_retention_days')
                            ->label('רשומות webhooks נכנסים')
                            ->numeric()->required()
                            ->minValue(7)->maxValue(3650)
                            ->suffix('ימים')
                            ->helperText('לוג האירועים מקארדקום/וואטסאפ/מייל. משמש למניעת עיבוד כפול — די בחלון קצר.'),
                        TextInput::make('system.notification_retention_days')
                            ->label('התראות שנקראו בפאנל')
                            ->numeric()->required()
                            ->minValue(7)->maxValue(3650)
                            ->suffix('ימים')
                            ->helperText('התראות שכבר נקראו נמחקות אחרי משך זה. התראות שלא נקראו נשמרות תמיד.'),
                        TextInput::make('system.log_retention_days')
                            ->label('לוג מערכת ("מערכת ועדכונים")')
                            ->numeric()->required()
                            ->minValue(7)->maxValue(3650)
                            ->suffix('ימים'),
                    ])->columns(2)
                    ->footerActions([$this->saveAction()]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (self::KEYS as $key => $configPath) {
            // Store the sanitised integer as a string (settings are text rows).
            Setting::put($key, (string) max(1, (int) data_get($data, $key)));
        }

        $this->refreshConfig();

        Notification::make()->title('הגדרות שמירת הנתונים נשמרו')->success()->send();
    }

    protected function saveAction(): Action
    {
        return Action::make('save_retention')
            ->label('שמירה')
            ->icon('heroicon-o-check')
            ->action(fn () => $this->save());
    }
}
