<?php

namespace App\Filament\Pages;

use App\Enums\ActionStatus;
use App\Enums\AgentCommandOutcome;
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

    protected static ?string $navigationLabel = 'צ׳אט עם הסוכן';

    protected static ?string $title = 'צ׳אט עם הסוכן AI';

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
     * The chat thread — this operator's turns and the agent's replies (plus
     * system turns for approvals), oldest first so it reads top-to-bottom.
     *
     * @return Collection<int, AgentCommand>
     */
    public function getConversationProperty(): Collection
    {
        // Newest first — the blade renders the thread with flex-col-reverse, so
        // it reads oldest→newest top-to-bottom and stays pinned to the latest.
        return AgentCommand::with('pendingAction')
            ->where('user_id', auth()->id())
            ->latest('id')
            ->limit(40)
            ->get();
    }

    /**
     * Pending proposals not already shown inline in the thread — a run that
     * files several actions only links the first to its turn, and proposals can
     * also arrive from elsewhere (monitoring, WhatsApp). Surfacing the rest here
     * means no proposal is ever silently un-actionable in the chat.
     *
     * @return Collection<int, PendingAction>
     */
    public function getExtraPendingProperty(): Collection
    {
        $shownInThread = AgentCommand::query()
            ->where('user_id', auth()->id())
            ->whereNotNull('pending_action_id')
            ->latest('id')
            ->limit(40)
            ->pluck('pending_action_id')
            ->all();

        return PendingAction::with('customer')
            ->where('status', ActionStatus::Pending)
            ->when($shownInThread !== [], fn ($q) => $q->whereNotIn('id', $shownInThread))
            ->latest('id')
            ->limit(12)
            ->get();
    }

    /**
     * The agent's open question, if my last command is waiting for my answer.
     * When set, the console shows a "reply to continue" banner and the next
     * thing I send is treated as the answer (see RunsAgentCommands).
     */
    public function getAwaitingReplyProperty(): ?AgentCommand
    {
        $last = AgentCommand::query()
            ->where('user_id', auth()->id())
            ->latest('id')
            ->first();

        return $last?->outcome === AgentCommandOutcome::Unclear ? $last : null;
    }

    /** Approve + run a proposal from the chat; post the result back into the thread. */
    public function approveAction(int $id): void
    {
        $action = PendingAction::find($id);

        if (! $action || $action->status !== ActionStatus::Pending) {
            return;
        }

        $result = app(ApprovalGate::class)->approve($action->fresh());
        $executed = $action->fresh()->status === ActionStatus::Executed;

        $this->postSystemTurn($result, $id);
        Notification::make()->title($result)->{$executed ? 'success' : 'warning'}()->send();
    }

    /** Reject a proposal from the chat; post the result back into the thread. */
    public function rejectAction(int $id): void
    {
        $action = PendingAction::find($id);

        if (! $action || $action->status !== ActionStatus::Pending) {
            return;
        }

        $result = app(ApprovalGate::class)->reject($action->fresh());

        $this->postSystemTurn($result, $id);
        Notification::make()->title($result)->send();
    }

    /** Record an approval/rejection outcome as a system turn in the chat thread. */
    protected function postSystemTurn(string $result, int $actionId): void
    {
        AgentCommand::create([
            'user_id' => auth()->id(),
            'role' => 'system',
            'instruction' => "החלטה על פעולה #{$actionId}",
            'outcome' => AgentCommandOutcome::Dispatched,
            'result' => $result,
            'pending_action_id' => $actionId,
        ]);
    }
}
