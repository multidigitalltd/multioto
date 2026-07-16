<?php

namespace App\Filament\Pages;

use App\Enums\ActionStatus;
use App\Filament\Concerns\RunsAgentCommands;
use App\Models\AgentCommand;
use App\Models\PendingAction;
use App\Services\Automation\ApprovalGate;
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
    use RunsAgentCommands;

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
        $this->dispatchAgentCommand((string) ($this->form->getState()['instruction'] ?? ''));
        $this->form->fill();
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

    /**
     * Everything the agent has proposed and is still waiting on — shown inline so
     * a proposal can be approved right here, without a detour to the approvals
     * screen.
     *
     * @return Collection<int, PendingAction>
     */
    public function getPendingApprovalsProperty(): Collection
    {
        return PendingAction::with('customer')
            ->where('status', ActionStatus::Pending)
            ->latest('id')
            ->limit(12)
            ->get();
    }

    /** Approve + run a proposal from the console. */
    public function approveAction(int $id): void
    {
        $action = PendingAction::find($id);

        if (! $action || $action->status !== ActionStatus::Pending) {
            return;
        }

        $result = app(ApprovalGate::class)->approve($action->fresh());

        Notification::make()->title($result)
            ->{$action->fresh()->status === ActionStatus::Executed ? 'success' : 'warning'}()
            ->send();
    }

    /** Reject a proposal from the console. */
    public function rejectAction(int $id): void
    {
        $action = PendingAction::find($id);

        if (! $action || $action->status !== ActionStatus::Pending) {
            return;
        }

        Notification::make()->title(app(ApprovalGate::class)->reject($action->fresh()))->send();
    }
}
