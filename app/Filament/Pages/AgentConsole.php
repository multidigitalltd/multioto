<?php

namespace App\Filament\Pages;

use App\Models\AgentCommand;
use App\Services\Agent\CommandInterpreter;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * מסוף פקודות לסוכן — מקום אחד לתת לסוכן ה-AI הוראות בשפה חופשית ("תענה למשה
 * בכרטיס הפתוח שאנחנו על זה", "תנקה קאש באתר X"). הסוכן מבין, מזהה את היעד
 * (פנייה/אתר) ומגיש את הפעולה לאישור — שום דבר לא נשלח או מתבצע בלי אישור.
 */
class AgentConsole extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $navigationGroup = 'תמיכה';

    protected static ?string $navigationLabel = 'מסוף פקודות לסוכן';

    protected static ?string $title = 'מסוף פקודות לסוכן AI';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.agent-console';

    /** @var array<string, mixed> */
    public array $data = [];

    /** Hide the console entirely when the AI agent is switched off. */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('billing.ai.enabled');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Textarea::make('instruction')
                    ->hiddenLabel()
                    ->placeholder('כתבו הוראה בשפה חופשית — למשל: תענה למשה בכרטיס הפתוח שאנחנו על זה · תנקה קאש באתר example.co.il')
                    ->rows(3)
                    ->required()
                    ->maxLength(2000)
                    ->autofocus(),
            ])
            ->statePath('data');
    }

    /** Interpret the instruction and act on it (propose / dispatch / clarify). */
    public function run(): void
    {
        $instruction = trim((string) ($this->form->getState()['instruction'] ?? ''));

        if ($instruction === '') {
            return;
        }

        $command = app(CommandInterpreter::class)->run($instruction, auth()->id());

        $this->form->fill();

        Notification::make()
            ->title($command->outcome->getLabel())
            ->body($command->result)
            ->{$command->outcome->value === 'failed' ? 'danger' : ($command->outcome->value === 'unclear' ? 'warning' : 'success')}()
            ->persistent()
            ->send();
    }

    /**
     * The console history — the most recent instructions and what came of them.
     *
     * @return Collection<int, AgentCommand>
     */
    public function getRecentCommandsProperty(): Collection
    {
        return AgentCommand::with('pendingAction')
            ->latest('id')
            ->limit(15)
            ->get();
    }
}
