<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Concerns\PersistsSettings;
use App\Models\Setting;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;

/**
 * לוח שנה ושבת — הגדרות חסימת האוטומציות בשבת ובחג (הפעלה, זמני כניסה/יציאה,
 * שעת חזרה ומיקום לחישוב), והפעלת פיצ׳ר "ימי שירות מיוחדים" (שמנוהלים מהלוח).
 * הכול נשמר כ-settings ומחליף את ברירת המחדל שב-.env בלי צורך לגעת בקוד.
 */
class ManageSchedule extends Page implements HasForms
{
    use AdminOnly;
    use InteractsWithForms;
    use PersistsSettings;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = Settings::class;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static ?string $navigationLabel = 'לוח שנה ושבת';

    protected static ?string $title = 'לוח שנה ושבת — חסימת שבת/חג וימי שירות';

    protected static ?int $navigationSort = 86;

    protected static string $view = 'filament.pages.manage-schedule';

    /** Boolean toggles: stored as '1'/'0'. */
    private const BOOL_KEYS = ['shabbat.block_automations', 'service_days.enabled'];

    /** Plain-value keys (times, offsets, coordinates): stored as strings. */
    private const VALUE_KEYS = [
        'shabbat.resume_time',
        'shabbat.candle_offset_minutes',
        'shabbat.havdalah_offset_minutes',
        'shabbat.latitude',
        'shabbat.longitude',
    ];

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $values = [];
        data_set($values, 'shabbat.block_automations', (bool) config('billing.shabbat.block_automations'));
        data_set($values, 'shabbat.resume_time', (string) config('billing.shabbat.resume_time'));
        data_set($values, 'shabbat.candle_offset_minutes', (int) config('billing.shabbat.candle_offset_minutes'));
        data_set($values, 'shabbat.havdalah_offset_minutes', (int) config('billing.shabbat.havdalah_offset_minutes'));
        data_set($values, 'shabbat.latitude', (float) config('billing.shabbat.latitude'));
        data_set($values, 'shabbat.longitude', (float) config('billing.shabbat.longitude'));
        data_set($values, 'service_days.enabled', (bool) config('billing.service_days.enabled'));

        $this->form->fill($values);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('שבת וחג — חסימת אוטומציות')
                    ->description('כשמופעל, פעולות יוצאות (חיובים, דאנינג, תזכורות, דיוור ואישורי פנייה אוטומטיים) נעצרות מכניסת השבת/חג ועד שעת החזרה למחרת. הזמנים מחושבים אוטומטית לפי המיקום — אין צורך לסמן שבתות ידנית.')
                    ->schema([
                        Toggle::make('shabbat.block_automations')
                            ->label('לחסום אוטומציות בשבת ובחג')
                            ->helperText('חל על כל השבתות והחגים (ראש השנה, יום כיפור, סוכות, שמיני עצרת, פסח, שבועות).')
                            ->columnSpanFull(),
                        TimePicker::make('shabbat.resume_time')
                            ->label('שעת חזרה למחרת')->seconds(false)->native(false)->required()
                            ->helperText('האוטומציות שהוחזקו יֵצאו בשעה זו ביום שאחרי צאת השבת/החג.'),
                        TextInput::make('shabbat.candle_offset_minutes')
                            ->label('דקות הדלקת נרות (לפני השקיעה)')->numeric()->minValue(0)->maxValue(120)->required(),
                        TextInput::make('shabbat.havdalah_offset_minutes')
                            ->label('דקות צאת השבת (אחרי השקיעה)')->numeric()->minValue(0)->maxValue(120)->required(),
                        TextInput::make('shabbat.latitude')
                            ->label('קו רוחב (Latitude)')->numeric()->minValue(-90)->maxValue(90)->required()
                            ->helperText('ברירת מחדל: תל אביב (32.0853).'),
                        TextInput::make('shabbat.longitude')
                            ->label('קו אורך (Longitude)')->numeric()->minValue(-180)->maxValue(180)->required()
                            ->helperText('ברירת מחדל: תל אביב (34.7818).'),
                    ])->columns(2)
                    ->footerActions([$this->saveAction()]),

                Section::make('ימי שירות מיוחדים')
                    ->description('ימים של מתכונת מצומצמת או "דחוף בלבד" מסומנים ומנוהלים ישירות מ*לוח השנה* (לחיצה על יום). כאן רק מפעילים/מכבים את הפיצ׳ר: כשמופעל, הסוכן יודע על היום המסומן ומעדכן את הלקוח בהתאם בפתיחת פנייה חדשה.')
                    ->schema([
                        Toggle::make('service_days.enabled')
                            ->label('הפעלת ימי שירות מיוחדים')
                            ->helperText('כשמכובה — הסימונים נשמרים בלוח אך אינם משפיעים על המענה ללקוח.')
                            ->columnSpanFull(),
                        Placeholder::make('managed_from_calendar')
                            ->label('')
                            ->content('לסימון/עריכה/הסרה של ימי שירות — היכנסו ל"לוח שנה" (ניהול) ולחצו על היום הרצוי.'),
                    ])
                    ->footerActions([$this->saveAction()]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (self::BOOL_KEYS as $key) {
            Setting::put($key, data_get($data, $key) ? '1' : '0');
        }

        foreach (self::VALUE_KEYS as $key) {
            Setting::put($key, (string) data_get($data, $key));
        }

        $this->refreshConfig();

        Notification::make()->title('הגדרות לוח השנה והשבת נשמרו')->success()->send();
    }

    protected function saveAction(): Action
    {
        return Action::make('save_schedule')
            ->label('שמירה')
            ->icon('heroicon-o-check')
            ->action(fn () => $this->save());
    }
}
