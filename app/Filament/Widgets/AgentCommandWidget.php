<?php

namespace App\Filament\Widgets;

use App\Enums\AgentCommandOutcome;
use App\Filament\Concerns\RunsAgentCommands;
use App\Filament\Pages\AgentConsole;
use App\Models\AgentCommand;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Widgets\Widget;

/**
 * The compact agent command bar — just the instruction field and a send button,
 * no examples or history. Embedded on the dashboard and the tickets screen so a
 * quick "תענה ללקוח… / תנקה קאש באתר…" is always one line away. The full history
 * and examples live on the dedicated console page.
 */
class AgentCommandWidget extends Widget implements HasForms
{
    use InteractsWithForms;
    use RunsAgentCommands;

    protected static string $view = 'filament.widgets.agent-command-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    /** @var array<string, mixed> */
    public array $data = [];

    /** Only when the AI agent is on — otherwise the bar would do nothing. */
    public static function canView(): bool
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
                    ->placeholder('הוראה לסוכן — למשל: תענה למשה בכרטיס הפתוח שאנחנו על זה · תנקה קאש באתר example.co.il')
                    ->rows(2)
                    ->required()
                    ->maxLength(2000),
            ])
            ->statePath('data');
    }

    public function run(): void
    {
        $this->dispatchAgentCommand((string) ($this->form->getState()['instruction'] ?? ''));
        $this->form->fill();
    }

    /**
     * The agent's open question, if my last command is waiting for my answer —
     * so the compact bar can show a "reply to continue" hint too.
     */
    public function getAwaitingReplyProperty(): ?AgentCommand
    {
        $last = AgentCommand::query()
            ->where('user_id', auth()->id())
            ->latest('id')
            ->first();

        return $last?->outcome === AgentCommandOutcome::Unclear ? $last : null;
    }

    public function consoleUrl(): string
    {
        return AgentConsole::getUrl();
    }
}
