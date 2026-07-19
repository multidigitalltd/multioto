<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Concerns\PersistsSettings;
use App\Models\Setting;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;

/**
 * טופס הרשמה — עריכת ההוראות שמוצגות ללקוח בטופס פתיחת הכרטיס הציבורי (/join)
 * לכל אמצעי תשלום שאינו כרטיס אשראי: הוראת קבע (קוד מוסד + קישור הרשאה),
 * העברה בנקאית (פרטי החשבון) וצ׳קים. הטקסטים אינם סוד — מוצגים ומולאים מראש.
 */
class ManageSignup extends Page implements HasForms
{
    use AdminOnly;
    use InteractsWithForms;
    use PersistsSettings;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $cluster = Settings::class;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    protected static ?string $navigationLabel = 'טופס הרשמה';

    protected static ?string $title = 'טופס הרשמה — הוראות תשלום';

    protected static ?int $navigationSort = 84;

    protected static string $view = 'filament.pages.manage-signup';

    private const KEYS = [
        'signup.instructions.standing_order',
        'signup.instructions.bank_transfer',
        'signup.instructions.checks',
    ];

    /**
     * Optional notices that may be hidden by clearing them. Unlike KEYS (which
     * fall back to the config default when blank), an empty value here is stored
     * verbatim so "clear to hide" actually hides it rather than reverting.
     */
    private const OPTIONAL_KEYS = [
        'signup.tax_approval_notice',
    ];

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        // Nested state (signup.instructions.* → data['signup']['instructions'][*]);
        // build the fill array nested so values reach the fields.
        $values = [];
        $stored = Setting::map();
        foreach (self::KEYS as $key) {
            data_set($values, $key, config('billing.'.$key));
        }
        // Optional notices: show the stored value verbatim (even empty) when a
        // row exists, otherwise the config default.
        foreach (self::OPTIONAL_KEYS as $key) {
            data_set($values, $key, array_key_exists($key, $stored) ? $stored[$key] : config('billing.'.$key));
        }

        $this->form->fill($values);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('הוראות לפי אמצעי תשלום')
                    ->description('הטקסט שיוצג ללקוח בטופס /join כשיבחר את אמצעי התשלום, וגם בעמוד הסיום. קישורים (http/https) יהפכו אוטומטית ללחיצים. הזנת כרטיס אשראי לא נדרשת כאן — היא נעשית ישירות מול חברת הסליקה.')
                    ->schema([
                        Textarea::make('signup.instructions.standing_order')
                            ->label('הוראת קבע בנקאית')
                            ->rows(4)
                            ->helperText('קוד המוסד שלנו וקישור ההרשאה הדיגיטלית.'),
                        Textarea::make('signup.instructions.bank_transfer')
                            ->label('העברה בנקאית')
                            ->rows(4)
                            ->helperText('פרטי החשבון שאליו הלקוח מעביר (בנק, סניף, מספר חשבון, שם החשבון).'),
                        Textarea::make('signup.instructions.checks')
                            ->label('צ׳קים (מקדמה / תשלום מראש)')
                            ->rows(3),
                        Textarea::make('signup.tax_approval_notice')
                            ->label('אישורי ניהול ספרים / ניכוי מס במקור')
                            ->rows(3)
                            ->helperText('כיתוב שמופיע בשלב התשלום בטופס — קישור להורדת אישורים ומספר התיק. השאירו ריק כדי לא להציג.'),
                        Placeholder::make('link')
                            ->label('קישור לטופס ההרשמה')
                            ->content(fn (): string => rtrim((string) config('app.url'), '/').'/join'),
                    ])->columns(1)
                    ->footerActions([$this->saveAction()]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        foreach (self::KEYS as $key) {
            $value = data_get($this->data, $key);

            if (filled($value)) {
                Setting::put($key, (string) $value);
            } else {
                Setting::forget($key); // fall back to the config default
            }
        }

        // Optional notices persist even when blank, so clearing them hides them
        // instead of reverting to the config default.
        foreach (self::OPTIONAL_KEYS as $key) {
            Setting::put($key, (string) (data_get($this->data, $key) ?? ''));
        }

        $this->refreshConfig();

        Notification::make()->title('הוראות התשלום נשמרו')->success()->send();
    }

    protected function saveAction(): FormAction
    {
        return FormAction::make('save_signup')
            ->label('שמירה')
            ->icon('heroicon-o-check')
            ->action(fn () => $this->save());
    }
}
