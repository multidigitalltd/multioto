<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Concerns\PersistsSettings;
use App\Models\Setting;
use App\Services\Ai\AiCostReporter;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\StyleLearner;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * סוכן AI — ניהול ההפעלה, הספק (אנתרופיק / תואם-OpenAI), הדגם וההוראות של הסוכן.
 * ההוראות (אישיות + כללים) ניתנות לעריכה מכאן; מעליהן הקוד תמיד מוסיף כלל בטיחות
 * שאי אפשר לעקוף (הכל נשמר כטיוטה לאישור אנושי).
 *
 * המפתח (API key) נשמר מוצפן ולעולם לא מוחזר לטופס — שדה ריק = לא לשנות.
 */
class ManageAiAgent extends Page implements HasForms
{
    use AdminOnly;
    use InteractsWithForms;
    use PersistsSettings;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'הגדרות';

    protected static ?string $navigationLabel = 'סוכן AI';

    protected static ?string $title = 'סוכן AI — הגדרות והוראות';

    protected static ?int $navigationSort = 84;

    protected static string $view = 'filament.pages.manage-ai-agent';

    /** Default API endpoint per provider — filled in when the provider changes. */
    private const DEFAULT_BASE_URLS = [
        'anthropic' => 'https://api.anthropic.com',
        'openai' => 'https://api.openai.com/v1',
        'google' => 'https://generativelanguage.googleapis.com',
    ];

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        // Prefill everything except the secret API key with the current config
        // (which already includes any stored overrides overlaid at boot).
        // NB: the fields use nested state (ai.* → data['ai'][*]), so the fill
        // array must be nested too — a flat 'ai.persona' key never reaches the
        // field and the form would render empty after a save.
        $this->form->fill([
            'ai' => [
                'enabled' => (bool) config('billing.ai.enabled'),
                'provider' => config('billing.ai.provider', 'anthropic'),
                'model' => config('billing.ai.model'),
                'base_url' => config('billing.ai.base_url'),
                'persona' => config('billing.ai.persona'),
                'rules' => config('billing.ai.rules'),
                'site_rules' => config('billing.ai.site_rules'),
                'style_summary' => config('billing.ai.style_summary'),
            ],
            'agent' => [
                'actions_enabled' => (bool) config('agent.actions_enabled'),
                'auto_investigate_tickets' => (bool) config('agent.auto_investigate_tickets'),
                'notify_owner_whatsapp' => (bool) config('agent.notify_owner_whatsapp'),
            ],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('הפעלה וספק')
                    ->description('כשכבוי — הפניות מטופלות ידנית כרגיל ושום דבר לא נשבר.')
                    ->schema([
                        Toggle::make('ai.enabled')->label('הפעל סוכן AI'),
                        Select::make('ai.provider')
                            ->label('ספק')
                            ->options([
                                'anthropic' => 'Anthropic (Claude)',
                                'openai' => 'תואם-OpenAI (OpenAI / Azure / OpenRouter / מקומי)',
                                'google' => 'Google (Gemini)',
                            ])
                            ->required()
                            ->live()
                            // Choosing a provider fills in its API endpoint, so
                            // there's no URL to remember.
                            ->afterStateUpdated(fn ($state, Set $set) => $set('ai.base_url', self::DEFAULT_BASE_URLS[$state] ?? null)),
                        TextInput::make('ai.model')
                            ->label('שם הדגם')
                            ->placeholder('claude-opus-4-8 / gpt-4o / gemini-2.5-flash')
                            ->autocomplete(false),
                        TextInput::make('ai.base_url')
                            ->label('כתובת ה-API')
                            ->helperText('אנתרופיק: https://api.anthropic.com · תואם-OpenAI: https://api.openai.com/v1 · Google: https://generativelanguage.googleapis.com')
                            ->autocomplete(false),
                        TextInput::make('ai.api_key')
                            ->label('מפתח API')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->helperText('נשמר מוצפן. השאירו ריק כדי לא לשנות.'),
                    ])->columns(2),

                Section::make('התראות בוואטסאפ')
                    ->description('שליטה על מה שהסוכן שולח לקבוצת הוואטסאפ. גם כשכבוי — כל הצעה עדיין ממתינה במסך "אישורי אוטומציה".')
                    ->schema([
                        Toggle::make('agent.notify_owner_whatsapp')
                            ->label('שלח הצעות ותשובות AI לאישור בוואטסאפ')
                            ->helperText('כשכבוי — הצעות הסוכן (תשובות ללקוח, פעולות באתרים) לא נשלחות לקבוצה; מאשרים אותן מהפאנל בלבד. פניות לקוח וניהול דרך הקבוצה אינם מושפעים.'),
                    ]),

                Section::make('פעולות באתרים (Kill-Switch)')
                    ->description('כשכבוי — הסוכן לא מבצע שום פעולה על אתרים, גם אם אושרה. מפעילים רק אחרי בדיקת אבטחה. כל פעולה תמיד עוברת אישור מנהל בנוסף.')
                    ->schema([
                        Toggle::make('agent.actions_enabled')
                            ->label('אפשר ביצוע פעולות על אתרים')
                            ->helperText('מתג-חירום ראשי. כיבוי חוסם מיידית כל ביצוע על כל האתרים.'),
                        Toggle::make('agent.auto_investigate_tickets')
                            ->label('בדיקת אתר אוטומטית בפניות חדשות')
                            ->helperText('בפנייה חדשה של לקוח עם אתר מחובר — הסוכן יבדוק את האתר לבד ויוסיף הערת מערכת עם המלצה. כל תיקון עדיין ממתין לאישור.'),
                    ]),

                Section::make('הוראות למענה לפניות (תשובות ללקוח)')
                    ->description('כאן קובעים איך הסוכן עונה ללקוחות. השאירו ריק כדי לחזור לברירת המחדל.')
                    ->schema([
                        Textarea::make('ai.persona')
                            ->label('אישיות ותפקיד')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('ai.rules')
                            ->label('כללים למענה לפניות')
                            ->helperText('שורה לכל כלל. חלים על טיוטות התשובה ללקוחות. מעל אלה תמיד מתווסף כלל בטיחות: הכל נשמר כטיוטה לאישור אנושי.')
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),

                Section::make('הוראות לטיפול באתרים (פעולות MCP)')
                    ->description('כללים נפרדים לעבודת הסוכן על אתרים — אבחון ותיקון. מעבר לכללים כאן, האכיפה הקשיחה (דירוג סיכון, סטייג׳ינג בלבד לכלים הרסניים, אישור מנהל, kill-switch) חלה תמיד בקוד.')
                    ->schema([
                        Textarea::make('ai.site_rules')
                            ->label('כללים לטיפול באתרים')
                            ->helperText('שורה לכל כלל. חלים על אבחון ופעולות באתרים דרך MCP. השאירו ריק כדי לחזור לברירת המחדל.')
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),

                Section::make('למידה מתשובות קודמות')
                    ->description('הסוכן יכול לקרוא את התשובות האחרונות שלכם ללקוחות ולסכם מהן את סגנון הכתיבה — הסיכום מתווסף אוטומטית לכל טיוטה, כך שהניסוח דומה לשלכם. לחצו "למד מתשובות קודמות" כדי לרענן.')
                    ->schema([
                        Textarea::make('ai.style_summary')
                            ->label('סגנון הצוות (נלמד)')
                            ->helperText('נוצר אוטומטית מהתשובות האחרונות, וניתן לעריכה ידנית. ריק = לא בשימוש.')
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        // Dotted field names are stored nested under `data`, so read with data_get.
        // Non-secret fields: persist the (prefilled/edited) value.
        Setting::put('ai.enabled', data_get($this->data, 'ai.enabled') ? '1' : '0');
        Setting::put('ai.provider', (string) (data_get($this->data, 'ai.provider') ?: 'anthropic'));
        Setting::put('agent.actions_enabled', data_get($this->data, 'agent.actions_enabled') ? '1' : '0');
        Setting::put('agent.auto_investigate_tickets', data_get($this->data, 'agent.auto_investigate_tickets') ? '1' : '0');
        Setting::put('agent.notify_owner_whatsapp', data_get($this->data, 'agent.notify_owner_whatsapp') ? '1' : '0');

        // model/base_url/persona/rules: persist when filled; clearing reverts to
        // the env/default value instead of storing an empty override.
        foreach (['ai.model', 'ai.base_url', 'ai.persona', 'ai.rules', 'ai.site_rules', 'ai.style_summary'] as $key) {
            $value = data_get($this->data, $key);
            if (filled($value)) {
                Setting::put($key, (string) $value);
            } else {
                Setting::forget($key);
            }
        }

        // The API key is a secret — only overwrite when a new value was typed.
        if (filled(data_get($this->data, 'ai.api_key'))) {
            Setting::put('ai.api_key', (string) data_get($this->data, 'ai.api_key'));
        }

        data_set($this->data, 'ai.api_key', null);

        // Re-apply the overlay so the (nested) form reflects exactly what was
        // stored, and show it back to confirm the save took.
        $this->refreshConfig();
        $this->mount();

        Notification::make()
            ->title('הגדרות הסוכן נשמרו')
            ->success()
            ->send();
    }

    /** Read recent agent replies, summarise their style, and load it into the form. */
    public function learnFromHistory(): void
    {
        $this->refreshConfig();

        if (! app(ClaudeClient::class)->isEnabled()) {
            Notification::make()->title('הסוכן אינו מופעל')->warning()->send();

            return;
        }

        $summary = app(StyleLearner::class)->refresh();

        if ($summary === null) {
            Notification::make()
                ->title('לא נלמד סגנון')
                ->body('צריך לפחות '.StyleLearner::MIN_REPLIES.' תשובות קודמות ללקוחות, וחיבור תקין לספק ה-AI.')
                ->warning()
                ->send();

            return;
        }

        data_set($this->data, 'ai.style_summary', $summary);

        Notification::make()
            ->title('הסגנון נלמד ונשמר')
            ->body('הסיכום יתווסף מעכשיו לכל טיוטה. אפשר לערוך אותו ידנית ולשמור.')
            ->success()
            ->send();
    }

    /**
     * The cached (24h) AI-spend snapshot for the dashboard section.
     *
     * @return array<string, mixed>
     */
    public function getCostSnapshotProperty(): array
    {
        return app(AiCostReporter::class)->snapshot();
    }

    /** Recompute the spend estimate now (busts the 24h cache). */
    public function refreshCost(): void
    {
        app(AiCostReporter::class)->forget();

        Notification::make()->title('נתוני העלות עודכנו')->success()->send();
    }

    /** Test the live connection to the AI provider and show the outcome. */
    public function testConnection(): void
    {
        $this->refreshConfig();
        $result = app(ClaudeClient::class)->testConnection();

        Notification::make()
            ->title(match ($result->state()) {
                'ok' => 'חיבור תקין',
                'fail' => 'בדיקת החיבור נכשלה',
                default => 'הסוכן אינו מוגדר',
            })
            ->body($result->message)
            ->{$result->ok ? 'success' : ($result->configured ? 'danger' : 'warning')}()
            ->persistent()
            ->send();
    }
}
